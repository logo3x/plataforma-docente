<?php

namespace App\Http\Controllers;

use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Material\Publicador;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\TemarioItem;
use App\Models\UnidadDidactica;
use App\Planificacion\GeneradorUnidad;
use App\Planificacion\UnidadesDidacticas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/** Unidades didácticas de la materia (7.4): a mano o generadas con IA a partir del temario (7.1). */
class UnidadDidacticaController extends Controller
{
    public function index(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('unidades.index', [
            'materia' => $materia,
            'planeadas' => $materia->unidadesDidacticas()->with('unidad')->latest()->get(),
            'unidades' => $this->unidadesDelTemario($materia),
            'dictados' => $materia->dictados()->with('grupos')->latest()->get(),
            'contenidos' => $materia->contenidos()->with('tema')->latest()->get(['id', 'titulo', 'temario_item_id']),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $materia->id)->where('tarea', 'unidad')->where('estado', 'borrador')->latest()->limit(10)->get(),
        ]);
    }

    public function create(Request $request, Materia $materia, UnidadesDidacticas $servicio): View
    {
        Gate::authorize('gestionar', $materia);
        $unidad = $request->integer('unidad') ? $materia->temario()->whereNull('parent_id')->find($request->integer('unidad')) : null;

        return $this->editor($materia, $servicio->plantillaVacia($unidad), $unidad?->id, route('unidades.store', $materia));
    }

    public function store(Request $request, Materia $materia, UnidadesDidacticas $servicio): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        [$datos, $unidadId] = $this->entrada($request, $materia);
        if ($e = $servicio->errores($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $u = $servicio->normalizar($datos);
        $creada = $materia->unidadesDidacticas()->create(['titulo' => $u['titulo'], 'contenido' => $u, 'temario_item_id' => $unidadId]);

        return redirect()->route('unidades.show', $creada)->with('estado', "Unidad «{$creada->titulo}» guardada.");
    }

    public function show(UnidadDidactica $unidad, UnidadesDidacticas $servicio): View
    {
        Gate::authorize('gestionar', $unidad->materia);

        return view('unidades.show', [
            'u' => $unidad->load('materia', 'unidad', 'generacion.motor'),
            'c' => $unidad->contenido,
            'temario' => $unidad->materia->temario()->where('tipo', 'tema')->get(),
            'servicio' => $servicio,
            'advertencias' => $servicio->advertencias($unidad->contenido, $unidad->materia, $unidad->unidad),
            'minutos' => $servicio->minutos($unidad->contenido),
        ]);
    }

    public function edit(UnidadDidactica $unidad): View
    {
        Gate::authorize('gestionar', $unidad->materia);

        return $this->editor($unidad->materia, $unidad->contenido, $unidad->temario_item_id, route('unidades.update', $unidad), $unidad);
    }

    public function update(Request $request, UnidadDidactica $unidad, UnidadesDidacticas $servicio): RedirectResponse
    {
        Gate::authorize('gestionar', $unidad->materia);
        [$datos, $unidadId] = $this->entrada($request, $unidad->materia);
        if ($e = $servicio->errores($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $u = $servicio->normalizar($datos);
        $unidad->update(['titulo' => $u['titulo'], 'contenido' => $u, 'temario_item_id' => $unidadId]);

        return redirect()->route('unidades.show', $unidad)->with('estado', 'Unidad actualizada.');
    }

    public function destroy(UnidadDidactica $unidad): RedirectResponse
    {
        Gate::authorize('gestionar', $unidad->materia);
        $unidad->delete();

        return redirect()->route('unidades.index', $unidad->materia)->with('estado', "Unidad «{$unidad->titulo}» eliminada.");
    }

    public function preparar(Request $request, Materia $materia, GeneradorUnidad $generador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate([
            'dictado_id' => ['required', 'integer'],
            'unidad_id' => ['nullable', 'integer'],
            'tema' => ['nullable', 'string', 'max:1000'],
            'sesiones' => ['required', 'integer', 'between:1,'.UnidadesDidacticas::MAX_SESIONES],
            'duracion' => ['required', 'integer', 'between:10,600'],
            'grupo' => ['nullable', 'string', 'max:60'],
            'enfasis' => ['nullable', 'string', 'max:1000'],
            'contenidos' => ['nullable', 'array'],
            'contenidos.*' => ['integer'],
            'motor_id' => ['nullable', 'integer'],
        ]);
        $dictado = $materia->dictados()->findOrFail($d['dictado_id']);

        try {
            $prompt = $generador->preparar($request->user(), $materia, $dictado, $d + ['contenidos' => []]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['tema' => $e->getMessage()]);
        }
        $request->session()->put('unidad.meta', ['plantillas' => $prompt['plantillas'], 'contenidos' => $prompt['contenidos'], 'unidad_id' => $prompt['unidad_id']]);

        return view('generacion.prompt', [
            'materia' => $materia, 'prompt' => $prompt, 'motorId' => $d['motor_id'] ?? null, 'motor' => $this->motor($request, $d['motor_id'] ?? null),
            'accion' => route('unidades.generar', $materia), 'volver' => route('unidades.index', $materia), 'migas' => 'Unidades didácticas',
        ]);
    }

    public function generar(Request $request, Materia $materia, GeneradorUnidad $generador): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['sistema' => ['required', 'string', 'max:200000'], 'usuario' => ['required', 'string', 'max:400000'], 'motor_id' => ['nullable', 'integer']]);

        try {
            $r = $generador->generar($request->user(), $materia, $d['sistema'], $d['usuario'], $request->session()->get('unidad.meta', []), $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return redirect()->route('unidades.index', $materia)->with('error_ia', $e->getMessage());
        }

        return redirect()->route('unidades.borrador', $r['generacion']);
    }

    public function borrador(GeneracionIa $generacion, GeneradorUnidad $generador): View
    {
        $this->autorizar($generacion);
        $datos = json_decode((string) $generacion->borrador, true);

        return $this->editor($generacion->materia, is_array($datos) ? $datos : ['titulo' => '', 'sesiones' => []], $generador->unidadDe($generacion),
            route('unidades.borrador.editar', $generacion), generacion: $generacion->load('motor'), errores: $generador->errores($generacion->borrador));
    }

    public function editarBorrador(Request $request, GeneracionIa $generacion, GeneradorUnidad $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        [$datos, $unidadId] = $this->entrada($request, $generacion->materia);
        $generador->editar($generacion, $datos, $unidadId);

        return $request->boolean('aprobar') ? $this->aprobar($generacion, $generador) : back()->with('estado', 'Borrador guardado y validado de nuevo.');
    }

    public function corregir(Request $request, GeneracionIa $generacion, GeneradorUnidad $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $r = $generador->corregir($request->user(), $generacion);
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('unidades.borrador', $r['generacion'])
            ->with('estado', $r['errores'] ? 'La IA devolvió una versión nueva; aún tiene observaciones.' : 'La IA corrigió el borrador: ya es válido.');
    }

    public function aprobar(GeneracionIa $generacion, GeneradorUnidad $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $u = $generador->aprobar($generacion);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('unidades.show', $u)->with('estado', "Unidad «{$u->titulo}» guardada.");
    }

    public function descartar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route('unidades.index', $generacion->materia)->with('estado', 'Borrador descartado (queda en la trazabilidad).');
    }

    private function editor(Materia $materia, array $contenido, ?int $unidadId, string $accion, ?UnidadDidactica $existente = null, ?GeneracionIa $generacion = null, array $errores = []): View
    {
        Gate::authorize('gestionar', $materia);
        $viejo = json_decode((string) old('unidad'), true);
        $contenido = is_array($viejo) ? $viejo : $contenido;
        $unidadId = (int) old('temario_item_id', $unidadId) ?: null;
        $servicio = app(UnidadesDidacticas::class);

        return view('unidades.editor', [
            'materia' => $materia,
            'contenido' => $servicio->normalizar($contenido) + ['sesiones' => []],
            'unidadId' => $unidadId,
            'unidades' => $this->unidadesDelTemario($materia),
            'accion' => $accion,
            'existente' => $existente,
            'generacion' => $generacion,
            'errores' => session('observaciones', $errores),
            'advertencias' => $servicio->advertencias($contenido, $materia, $unidadId ? TemarioItem::query()->find($unidadId) : null),
        ]);
    }

    /** @return array{0: array, 1: ?int} */
    private function entrada(Request $request, Materia $materia): array
    {
        $d = $request->validate(['unidad' => ['required', 'json', 'max:300000'], 'temario_item_id' => ['nullable', 'integer']]);
        $unidadId = ! empty($d['temario_item_id']) ? $materia->temario()->whereNull('parent_id')->findOrFail($d['temario_item_id'])->id : null;

        return [json_decode($d['unidad'], true) ?: [], $unidadId];
    }

    private function unidadesDelTemario(Materia $materia)
    {
        return $materia->temario()->whereNull('parent_id')->with(['hijos' => fn ($q) => $q->orderBy('orden')])->orderBy('orden')->get();
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null || $g->tarea !== 'unidad', 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aprobado o descartado.');
    }

    /** 7.8: la unidad didáctica como documento HTML autocontenido o de Word. */
    public function exportar(UnidadDidactica $unidad, string $formato, Publicador $publicador): Response
    {
        Gate::authorize('gestionar', $unidad->materia);
        $d = $publicador->documento($publicador->limpiar($this->show($unidad, app(UnidadesDidacticas::class))->render()), $unidad->titulo, $unidad->materia, $formato);

        return response($d['contenido'], 200, ['Content-Type' => $d['mime'], 'Content-Disposition' => 'attachment; filename="'.$d['nombre'].'"']);
    }
}
