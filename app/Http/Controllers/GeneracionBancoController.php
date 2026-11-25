<?php

namespace App\Http\Controllers;

use App\Evaluacion\GeneradorBanco;
use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Materia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Generador de cuestionarios y parciales con IA (7.4): formulario → prompt editable → borrador → aprobación. */
class GeneracionBancoController extends Controller
{
    public function create(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('generacion.crear', [
            'materia' => $materia,
            'dictados' => $materia->dictados()->with('grupos')->latest()->get(),
            'contenidos' => $materia->contenidos()->latest()->get(['id', 'titulo', 'tipo', 'texto_extraido']),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $materia->id)->where('tarea', 'banco')->where('estado', 'borrador')->latest()->limit(10)->get(),
            'unidades' => $materia->temario()->whereNull('parent_id')->with(['hijos' => fn ($q) => $q->where('tipo', 'tema')->orderBy('orden')])->get(),
        ]);
    }

    /** Paso 1: arma el prompt y lo muestra para revisar y editar antes de enviar (8.4.3). */
    public function preparar(Request $request, Materia $materia, GeneradorBanco $generador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate([
            'dictado_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(['quiz', 'parcial', 'taller'])],
            'tema' => ['nullable', 'string', 'max:500'],
            'temas' => ['nullable', 'array'],
            'temas.*' => ['integer'],
            'grupo' => ['nullable', 'string', 'max:60'],
            'valor' => ['required', 'numeric', 'gt:0'],
            'cantidades' => ['required', 'array'],
            'cantidades.*' => ['nullable', 'integer', 'between:0,40'],
            'obligatorio' => ['nullable', 'string', 'max:1000'],
            'excluido' => ['nullable', 'string', 'max:1000'],
            'contenidos' => ['nullable', 'array'],
            'contenidos.*' => ['integer'],
            'motor_id' => ['nullable', 'integer'],
        ]);
        $dictado = $materia->dictados()->findOrFail($d['dictado_id']);

        try {
            $prompt = $generador->preparar($request->user(), $materia, $dictado, $d + ['contenidos' => $d['contenidos'] ?? [], 'temas' => $d['temas'] ?? [], 'tema' => $d['tema'] ?? '']);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['cantidades' => $e->getMessage()]);
        }

        $request->session()->put('generacion.meta', ['plantillas' => $prompt['plantillas'], 'contenidos' => $prompt['contenidos'], 'sigla' => $prompt['sigla']]);

        return view('generacion.prompt', ['materia' => $materia, 'prompt' => $prompt, 'motorId' => $d['motor_id'] ?? null,
            'motor' => $this->motor($request, $d['motor_id'] ?? null)]);
    }

    /** Paso 2: envía al motor y guarda el borrador. */
    public function generar(Request $request, Materia $materia, GeneradorBanco $generador): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['sistema' => ['required', 'string', 'max:200000'], 'usuario' => ['required', 'string', 'max:400000'], 'motor_id' => ['nullable', 'integer']]);
        $meta = $request->session()->get('generacion.meta', []);

        try {
            $r = $generador->generar($request->user(), $materia, $d['sistema'], $d['usuario'], $meta, $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return redirect()->route('generacion.create', $materia)->with('error_ia', $e->getMessage());
        }

        return redirect()->route('generacion.show', $r['generacion']);
    }

    public function show(GeneracionIa $generacion, GeneradorBanco $generador): View
    {
        $this->autorizar($generacion);
        $banco = json_decode((string) $generacion->borrador, true);

        return view('generacion.borrador', [
            'g' => $generacion->load('motor', 'materia'),
            'banco' => is_array($banco) ? $banco : null,
            'errores' => $generador->errores($generacion->borrador),
        ]);
    }

    public function editar(Request $request, GeneracionIa $generacion, GeneradorBanco $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generador->editar($generacion, (string) $request->validate(['borrador' => ['required', 'string', 'max:400000']])['borrador']);

        return back()->with('estado', 'Borrador guardado y validado de nuevo.');
    }

    public function reescalar(GeneracionIa $generacion, GeneradorBanco $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generador->reescalar($generacion);

        return back()->with('estado', 'Pesos repartidos proporcionalmente para sumar el valor.');
    }

    public function corregir(Request $request, GeneracionIa $generacion, GeneradorBanco $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $r = $generador->corregir($request->user(), $generacion);
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('generacion.show', $r['generacion'])->with('estado', $r['errores'] ? 'La IA devolvió una versión nueva; aún tiene observaciones.' : 'La IA corrigió el borrador: ya es válido.');
    }

    public function aprobar(GeneracionIa $generacion, GeneradorBanco $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $a = $generador->aprobar($generacion);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('artefactos.index', $generacion->materia)
            ->with('estado', "Actividad {$a->sigla} guardada con {$a->items()->count()} ítems. Revísela, aplíquela en un corte y descargue el examen.");
    }

    public function descartar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route('generacion.create', $generacion->materia)->with('estado', 'Borrador descartado (queda en la trazabilidad).');
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null, 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aprobado o descartado.');
    }
}
