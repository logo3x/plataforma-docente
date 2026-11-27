<?php

namespace App\Http\Controllers;

use App\Evaluacion\GeneradorRubrica;
use App\Evaluacion\Rubricas;
use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Material\Publicador;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Pregunta;
use App\Models\Respuesta;
use App\Models\Rubrica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/** Rúbricas de la materia (7.4): a mano o generadas con IA, y su asociación a las preguntas abiertas (6.7). */
class RubricaController extends Controller
{
    public function index(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('rubricas.index', [
            'materia' => $materia,
            'rubricas' => $materia->rubricas()->withCount('preguntas')->latest()->get(),
            'abiertas' => $this->abiertas($materia),
            'dictados' => $materia->dictados()->with('grupos')->latest()->get(),
            'contenidos' => $materia->contenidos()->latest()->get(['id', 'titulo', 'texto_extraido']),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $materia->id)->where('tarea', 'rubrica')->where('estado', 'borrador')->latest()->limit(10)->get(),
            'unidades' => $materia->temario()->whereNull('parent_id')->with(['hijos' => fn ($q) => $q->where('tipo', 'tema')->orderBy('orden')])->get(),
        ]);
    }

    public function create(Request $request, Materia $materia, Rubricas $rubricas): View
    {
        Gate::authorize('gestionar', $materia);
        $pregunta = $request->integer('pregunta') ?: null;

        return $this->editor($materia, $rubricas->plantillaVacia(), $pregunta ? [$pregunta] : [], route('rubricas.store', $materia));
    }

    public function store(Request $request, Materia $materia, Rubricas $rubricas): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        [$datos, $preguntas] = $this->entrada($request);
        if ($e = $rubricas->validar($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $r = DB::transaction(function () use ($materia, $rubricas, $datos, $preguntas) {
            $r = $materia->rubricas()->create($rubricas->normalizar($datos));
            $this->asociar($r, $preguntas);

            return $r;
        });

        return redirect()->route('rubricas.index', $materia)->with('estado', "Rúbrica «{$r->titulo}» guardada.");
    }

    public function edit(Rubrica $rubrica): View
    {
        Gate::authorize('gestionar', $rubrica->materia);

        return $this->editor($rubrica->materia, $rubrica->only('titulo', 'proposito', 'criterios'), $rubrica->preguntas()->pluck('id')->all(),
            route('rubricas.update', $rubrica), $rubrica);
    }

    public function update(Request $request, Rubrica $rubrica, Rubricas $rubricas): RedirectResponse
    {
        Gate::authorize('gestionar', $rubrica->materia);
        [$datos, $preguntas] = $this->entrada($request);
        if ($e = $rubricas->validar($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        DB::transaction(function () use ($rubrica, $rubricas, $datos, $preguntas) {
            $rubrica->update($rubricas->normalizar($datos));
            $this->asociar($rubrica, $preguntas);
        });

        return redirect()->route('rubricas.index', $rubrica->materia)->with('estado', "Rúbrica «{$rubrica->titulo}» actualizada. Los puntajes ya asignados no cambian.");
    }

    public function destroy(Rubrica $rubrica): RedirectResponse
    {
        Gate::authorize('gestionar', $rubrica->materia);
        $rubrica->delete();

        return redirect()->route('rubricas.index', $rubrica->materia)
            ->with('estado', "Rúbrica «{$rubrica->titulo}» eliminada. Las respuestas ya calificadas conservan el detalle de sus criterios.");
    }

    /** Paso 1 del generador: arma el prompt y lo muestra para revisar (8.4.3). */
    public function preparar(Request $request, Materia $materia, GeneradorRubrica $generador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate([
            'dictado_id' => ['required', 'integer'],
            'pregunta_id' => ['nullable', 'integer'],
            'proposito' => ['nullable', 'string', 'max:3000'],
            'tema' => ['nullable', 'string', 'max:500'],
            'temas' => ['nullable', 'array'],
            'temas.*' => ['integer'],
            'grupo' => ['nullable', 'string', 'max:60'],
            'criterios' => ['required', 'integer', 'between:1,'.Rubricas::MAX_CRITERIOS],
            'niveles' => ['nullable', 'string', 'max:300'],
            'contenidos' => ['nullable', 'array'],
            'contenidos.*' => ['integer'],
            'motor_id' => ['nullable', 'integer'],
        ]);
        $dictado = $materia->dictados()->findOrFail($d['dictado_id']);

        try {
            $prompt = $generador->preparar($request->user(), $materia, $dictado, $d + ['temas' => [], 'contenidos' => []]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['proposito' => $e->getMessage()]);
        }
        $request->session()->put('rubrica.meta', ['plantillas' => $prompt['plantillas'], 'contenidos' => $prompt['contenidos'], 'pregunta_id' => $prompt['pregunta_id']]);

        return view('generacion.prompt', [
            'materia' => $materia, 'prompt' => $prompt, 'motorId' => $d['motor_id'] ?? null, 'motor' => $this->motor($request, $d['motor_id'] ?? null),
            'accion' => route('rubricas.generar', $materia), 'volver' => route('rubricas.index', $materia), 'migas' => 'Rúbricas',
        ]);
    }

    public function generar(Request $request, Materia $materia, GeneradorRubrica $generador): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['sistema' => ['required', 'string', 'max:200000'], 'usuario' => ['required', 'string', 'max:400000'], 'motor_id' => ['nullable', 'integer']]);

        try {
            $r = $generador->generar($request->user(), $materia, $d['sistema'], $d['usuario'], $request->session()->get('rubrica.meta', []), $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return redirect()->route('rubricas.index', $materia)->with('error_ia', $e->getMessage());
        }

        return redirect()->route('rubricas.borrador', $r['generacion']);
    }

    /** Borrador de la IA en el mismo editor visual de la rúbrica a mano. */
    public function borrador(GeneracionIa $generacion, GeneradorRubrica $generador): View
    {
        $this->autorizar($generacion);
        $datos = json_decode((string) $generacion->borrador, true);
        $pregunta = $generador->preguntaDe($generacion);

        return $this->editor($generacion->materia, is_array($datos) ? $datos : ['titulo' => '', 'criterios' => []], $pregunta ? [$pregunta] : [],
            route('rubricas.borrador.editar', $generacion), generacion: $generacion->load('motor'), errores: $generador->errores($generacion->borrador));
    }

    public function editarBorrador(Request $request, GeneracionIa $generacion, GeneradorRubrica $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        [$datos, $preguntas] = $this->entrada($request);
        $generador->editar($generacion, $datos, $preguntas[0] ?? null);

        if ($request->boolean('aprobar')) {
            return $this->aprobar($generacion, $generador);
        }

        return back()->with('estado', 'Borrador guardado y validado de nuevo.');
    }

    public function corregir(Request $request, GeneracionIa $generacion, GeneradorRubrica $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $r = $generador->corregir($request->user(), $generacion);
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('rubricas.borrador', $r['generacion'])
            ->with('estado', $r['errores'] ? 'La IA devolvió una versión nueva; aún tiene observaciones.' : 'La IA corrigió el borrador: ya es válido.');
    }

    public function aprobar(GeneracionIa $generacion, GeneradorRubrica $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $r = $generador->aprobar($generacion);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('rubricas.index', $r->materia)->with('estado', "Rúbrica «{$r->titulo}» guardada"
            .($r->preguntas()->exists() ? ' y asociada a la pregunta: ya se usa al calificar.' : '. Asóciela a una pregunta abierta para usarla al calificar.'));
    }

    public function descartar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route('rubricas.index', $generacion->materia)->with('estado', 'Borrador descartado (queda en la trazabilidad).');
    }

    private function editor(Materia $materia, array $rubrica, array $preguntas, string $accion, ?Rubrica $existente = null, ?GeneracionIa $generacion = null, array $errores = []): View
    {
        Gate::authorize('gestionar', $materia);
        $viejo = json_decode((string) old('rubrica'), true);

        return view('rubricas.editor', [
            'materia' => $materia,
            'rubrica' => is_array($viejo) ? $viejo : $rubrica,
            'seleccionadas' => array_map('intval', old('preguntas', $preguntas)),
            'abiertas' => $this->abiertas($materia),
            'accion' => $accion,
            'existente' => $existente,
            'generacion' => $generacion,
            'errores' => session('observaciones', $errores),
            'usada' => $existente ? Respuesta::query()->whereHas('item', fn ($q) => $q->whereIn('pregunta_id', $existente->preguntas()->select('id')))
                ->whereNotNull('desglose->rubrica')->count() : 0,
        ]);
    }

    /** @return array{0: array, 1: array<int, int>} */
    private function entrada(Request $request): array
    {
        $d = $request->validate(['rubrica' => ['required', 'json', 'max:100000'], 'preguntas' => ['nullable', 'array'], 'preguntas.*' => ['nullable', 'integer']]);

        return [json_decode($d['rubrica'], true) ?: [], array_values(array_map('intval', array_filter($d['preguntas'] ?? [])))];
    }

    /** Asocia la rúbrica exactamente a las preguntas abiertas elegidas de su materia. */
    private function asociar(Rubrica $r, array $preguntas): void
    {
        Pregunta::query()->where('rubrica_id', $r->id)->whereNotIn('id', $preguntas)->update(['rubrica_id' => null]);
        Pregunta::query()->where('materia_id', $r->materia_id)->where('tipo', 'abierta')->whereIn('id', $preguntas)->update(['rubrica_id' => $r->id]);
    }

    private function abiertas(Materia $materia)
    {
        return Pregunta::query()->where('materia_id', $materia->id)->where('tipo', 'abierta')->with('rubrica:id,titulo')->latest()->get(['id', 'enunciado', 'rubrica_id', 'materia_id']);
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null || $g->tarea !== 'rubrica', 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aprobado o descartado.');
    }

    /** 7.8: la rúbrica como documento HTML autocontenido o de Word. */
    public function exportar(Rubrica $rubrica, string $formato, Publicador $publicador): Response
    {
        Gate::authorize('gestionar', $rubrica->materia);
        $d = $publicador->documento(view('rubricas.documento', ['r' => $rubrica])->render(), $rubrica->titulo, $rubrica->materia, $formato);

        return response($d['contenido'], 200, ['Content-Type' => $d['mime'], 'Content-Disposition' => 'attachment; filename="'.$d['nombre'].'"']);
    }
}
