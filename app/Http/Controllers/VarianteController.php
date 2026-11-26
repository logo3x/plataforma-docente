<?php

namespace App\Http\Controllers;

use App\Evaluacion\GeneradorVariante;
use App\Evaluacion\ImportadorBanco;
use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Models\Artefacto;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Versión B y segundo banco de un examen con IA (5.4.2): prompt editable, borrador junto al original y aprobación. */
class VarianteController extends Controller
{
    public function create(Request $request, Artefacto $artefacto): View
    {
        Gate::authorize('gestionar', $artefacto->materia);

        return view('variantes.crear', [
            'a' => $artefacto, 'posibles' => GeneradorVariante::posibles($artefacto),
            'conEntregas' => $artefacto->actividades()->whereHas('entregas')->exists(),
            'dictados' => $artefacto->materia->dictados()->latest()->get(),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $artefacto->materia_id)->where('tarea', GeneradorVariante::TAREA)->where('estado', 'borrador')
                ->latest()->get()->filter(fn ($g) => (json_decode((string) $g->borrador, true)['artefacto_id'] ?? null) === $artefacto->id)->values(),
        ]);
    }

    public function preparar(Request $request, Artefacto $artefacto, GeneradorVariante $generador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $d = $request->validate([
            'version' => ['required', Rule::in(['B', '2A', '2B'])], 'dictado_id' => ['required', 'integer'],
            'indicaciones' => ['nullable', 'string', 'max:1000'], 'motor_id' => ['nullable', 'integer'],
        ]);
        $dictado = $artefacto->materia->dictados()->findOrFail($d['dictado_id']);
        try {
            $prompt = $generador->preparar($request->user(), $artefacto, $dictado, $d['version'], $d['indicaciones'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['version' => $e->getMessage()]);
        }
        $request->session()->put('variante.meta', ['plantillas' => $prompt['plantillas'], 'version' => $d['version'], 'artefacto' => $artefacto->id]);

        return view('generacion.prompt', [
            'materia' => $artefacto->materia, 'prompt' => $prompt, 'motorId' => $d['motor_id'] ?? null, 'motor' => $this->motor($request, $d['motor_id'] ?? null),
            'accion' => route('variantes.generar', $artefacto), 'volver' => route('variantes.create', $artefacto), 'migas' => "Versión {$d['version']} de {$artefacto->sigla}",
        ]);
    }

    public function generar(Request $request, Artefacto $artefacto, GeneradorVariante $generador): RedirectResponse
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $d = $request->validate(['sistema' => ['required', 'string', 'max:200000'], 'usuario' => ['required', 'string', 'max:400000'], 'motor_id' => ['nullable', 'integer']]);
        $meta = $request->session()->get('variante.meta', []);
        abort_unless(($meta['artefacto'] ?? null) === $artefacto->id, 409, 'Prepare de nuevo el prompt de esta versión.');

        try {
            $r = $generador->generar($request->user(), $artefacto, $meta['version'], $d['sistema'], $d['usuario'], $meta, $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return redirect()->route('variantes.create', $artefacto)->with('error_ia', $e->getMessage());
        }

        return redirect()->route('variantes.borrador', $r['generacion']);
    }

    public function borrador(GeneracionIa $generacion, GeneradorVariante $generador): View
    {
        $this->autorizar($generacion);
        [$a, $version] = $generador->destino($generacion);
        $origen = ImportadorBanco::origenDe($a, $version);

        $originales = $a->items()->with('pregunta')->where('banco', $origen)->orderBy('orden')->get()->map(fn ($ap) => ImportadorBanco::aBanco($ap))->values();
        $nuevos = array_values((array) (json_decode((string) $generacion->borrador, true)['items'] ?? []));

        return view('variantes.borrador', [
            'g' => $generacion->load('motor'), 'a' => $a, 'version' => $version, 'origenVersion' => $origen,
            'originales' => $originales, 'nuevos' => $nuevos,
            'errores' => $generador->errores($generacion),
            'advertencias' => GeneradorVariante::advertencias($originales->all(), $nuevos, $origen),
        ]);
    }

    public function editar(Request $request, GeneracionIa $generacion, GeneradorVariante $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generador->editar($generacion, (string) $request->validate(['borrador' => ['required', 'string', 'max:400000']])['borrador']);

        return back()->with('estado', 'Borrador guardado y validado de nuevo.');
    }

    public function corregir(Request $request, GeneracionIa $generacion, GeneradorVariante $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $r = $generador->corregir($request->user(), $generacion);
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('variantes.borrador', $r['generacion'])->with('estado', $r['errores'] ? 'La IA devolvió una versión nueva; aún tiene observaciones.' : 'La IA corrigió el borrador: ya es válido.');
    }

    public function aprobar(GeneracionIa $generacion, GeneradorVariante $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        [$a, $version] = $generador->destino($generacion);
        try {
            $n = $generador->aprobar($generacion);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('artefactos.index', $a->materia)->with('estado', "Versión {$version} agregada a {$a->sigla} con {$n} ítems. "
            .(str_starts_with($version, '2') ? 'El banco 2 ya se puede descargar para la otra jornada.' : 'A cada estudiante le tocará A o B según su documento.'));
    }

    public function descartar(GeneracionIa $generacion, GeneradorVariante $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        [$a] = $generador->destino($generacion);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route('variantes.create', $a)->with('estado', 'Borrador descartado (queda en la trazabilidad).');
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null || $g->tarea !== GeneradorVariante::TAREA, 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aprobado o descartado.');
    }
}
