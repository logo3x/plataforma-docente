<?php

namespace App\Http\Controllers;

use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Material\EjemplosPracticos;
use App\Material\GeneradorEjemplos;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/** Ejemplos prácticos resueltos paso a paso (7.4): a mano o generados con IA, con el código verificado en el navegador. */
class EjemploController extends Controller
{
    public function index(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('ejemplos.index', [
            'materia' => $materia,
            'conjuntos' => $materia->materiales()->where('tipo', EjemplosPracticos::TIPO)->latest()->get(),
            'unidades' => $materia->temario()->whereNull('parent_id')->with(['hijos' => fn ($q) => $q->where('tipo', 'tema')->orderBy('orden')])->orderBy('orden')->get(),
            'marcados' => array_map('intval', (array) old('temas', $request->input('temas', []))),
            'dictados' => $materia->dictados()->with('grupos')->latest()->get(),
            'contenidos' => $materia->contenidos()->latest()->get(['id', 'titulo']),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $materia->id)->where('tarea', 'ejemplos')->where('estado', 'borrador')->latest()->limit(10)->get(),
        ]);
    }

    public function create(Request $request, Materia $materia, EjemplosPracticos $servicio): View
    {
        Gate::authorize('gestionar', $materia);
        $temas = $materia->temario()->where('tipo', 'tema')->whereIn('id', (array) $request->input('temas', []))->orderBy('orden')->pluck('titulo')->all();

        return $this->editor($materia, $servicio->plantillaVacia($temas), route('ejemplos.store', $materia), plantilla: true);
    }

    public function store(Request $request, Materia $materia, EjemplosPracticos $servicio): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $datos = $this->entrada($request);
        if ($e = $servicio->errores($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $d = $servicio->normalizar($datos);
        $m = $materia->materiales()->create(['tipo' => EjemplosPracticos::TIPO, 'titulo' => $d['titulo'], 'contenido' => $d]);

        return redirect()->route('ejemplos.show', $m)->with('estado', "Ejemplos «{$m->titulo}» guardados.");
    }

    public function show(Request $request, Material $material, EjemplosPracticos $servicio): View
    {
        $this->autorizarMaterial($material);

        return view('ejemplos.show', [
            'm' => $material->load('materia', 'generacion.motor'),
            'c' => $material->contenido,
            'estudiante' => $request->query('version') === 'estudiante',
            'verificables' => $servicio->verificables($material->contenido),
            'advertencias' => $servicio->advertencias($material->contenido, $material->materia),
        ]);
    }

    public function edit(Material $material): View
    {
        $this->autorizarMaterial($material);

        return $this->editor($material->materia, $material->contenido, route('ejemplos.update', $material), $material);
    }

    public function update(Request $request, Material $material, EjemplosPracticos $servicio): RedirectResponse
    {
        $this->autorizarMaterial($material);
        $datos = $this->entrada($request);
        if ($e = $servicio->errores($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $d = $servicio->normalizar($datos);
        $material->update(['titulo' => $d['titulo'], 'contenido' => $d]);

        return redirect()->route('ejemplos.show', $material)->with('estado', 'Ejemplos actualizados.');
    }

    public function destroy(Material $material): RedirectResponse
    {
        $this->autorizarMaterial($material);
        $material->delete();

        return redirect()->route('ejemplos.index', $material->materia)->with('estado', "Ejemplos «{$material->titulo}» eliminados.");
    }

    public function preparar(Request $request, Materia $materia, GeneradorEjemplos $generador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate([
            'dictado_id' => ['required', 'integer'],
            'temas' => ['nullable', 'array'],
            'temas.*' => ['integer'],
            'tema' => ['nullable', 'string', 'max:1000'],
            'cantidad' => ['required', 'integer', 'between:1,'.EjemplosPracticos::MAX_EJEMPLOS],
            'dificultad' => ['required', 'in:mixta,baja,media,alta'],
            'contexto' => ['nullable', 'string', 'max:500'],
            'grupo' => ['nullable', 'string', 'max:60'],
            'contenidos' => ['nullable', 'array'],
            'contenidos.*' => ['integer'],
            'motor_id' => ['nullable', 'integer'],
        ]);
        $dictado = $materia->dictados()->findOrFail($d['dictado_id']);

        try {
            $prompt = $generador->preparar($request->user(), $materia, $dictado, $d + ['temas' => [], 'contenidos' => []]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['tema' => $e->getMessage()]);
        }
        $request->session()->put('ejemplos.meta', ['plantillas' => $prompt['plantillas'], 'contenidos' => $prompt['contenidos']]);

        return view('generacion.prompt', [
            'materia' => $materia, 'prompt' => $prompt, 'motorId' => $d['motor_id'] ?? null, 'motor' => $this->motor($request, $d['motor_id'] ?? null),
            'accion' => route('ejemplos.generar', $materia), 'volver' => route('ejemplos.index', $materia), 'migas' => 'Ejemplos prácticos',
        ]);
    }

    public function generar(Request $request, Materia $materia, GeneradorEjemplos $generador): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['sistema' => ['required', 'string', 'max:200000'], 'usuario' => ['required', 'string', 'max:400000'], 'motor_id' => ['nullable', 'integer']]);

        try {
            $r = $generador->generar($request->user(), $materia, $d['sistema'], $d['usuario'], $request->session()->get('ejemplos.meta', []), $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return redirect()->route('ejemplos.index', $materia)->with('error_ia', $e->getMessage());
        }

        return redirect()->route('ejemplos.borrador', $r['generacion']);
    }

    public function borrador(GeneracionIa $generacion, GeneradorEjemplos $generador): View
    {
        $this->autorizar($generacion);
        $datos = json_decode((string) $generacion->borrador, true);

        return $this->editor($generacion->materia, is_array($datos) ? $datos : ['titulo' => '', 'ejemplos' => []], route('ejemplos.borrador.editar', $generacion),
            generacion: $generacion->load('motor'), errores: $generador->errores($generacion->borrador));
    }

    public function editarBorrador(Request $request, GeneracionIa $generacion, GeneradorEjemplos $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generador->editar($generacion, $this->entrada($request));

        return $request->boolean('aprobar') ? $this->aprobar($generacion, $generador) : back()->with('estado', 'Borrador guardado y validado de nuevo.');
    }

    /** Pide a la IA que corrija los errores de formato y las comprobaciones que fallaron en el navegador. */
    public function corregir(Request $request, GeneracionIa $generacion, GeneradorEjemplos $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $fallidos = json_decode((string) $request->validate(['fallidos' => ['nullable', 'json', 'max:20000']])['fallidos'] ?? '[]', true) ?: [];
        $fallidos = collect($fallidos)->filter(fn ($f) => is_array($f) && is_numeric($f['numero'] ?? null))
            ->map(fn ($f) => ['numero' => (int) $f['numero'], 'error' => is_string($f['error'] ?? null) ? mb_substr($f['error'], 0, 300) : null])->take(EjemplosPracticos::MAX_EJEMPLOS)->all();

        try {
            $r = $generador->corregir($request->user(), $generacion, null, $fallidos);
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('ejemplos.borrador', $r['generacion'])
            ->with('estado', $r['errores'] ? 'La IA devolvió una versión nueva; aún tiene observaciones.' : 'La IA devolvió una versión nueva. Revise que las comprobaciones pasen.');
    }

    public function aprobar(GeneracionIa $generacion, GeneradorEjemplos $generador): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $m = $generador->aprobar($generacion);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('ejemplos.show', $m)->with('estado', "Ejemplos «{$m->titulo}» guardados.");
    }

    public function descartar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route('ejemplos.index', $generacion->materia)->with('estado', 'Borrador descartado (queda en la trazabilidad).');
    }

    private function editor(Materia $materia, array $contenido, string $accion, ?Material $existente = null, ?GeneracionIa $generacion = null, array $errores = [], bool $plantilla = false): View
    {
        Gate::authorize('gestionar', $materia);
        $viejo = json_decode((string) old('ejemplos'), true);
        $servicio = app(EjemplosPracticos::class);
        // La plantilla vacía del formulario nuevo no se normaliza: sus ejemplos en blanco se descartarían
        $contenido = is_array($viejo) ? $servicio->normalizar($viejo) : ($plantilla ? $contenido : $servicio->normalizar($contenido));

        return view('ejemplos.editor', [
            'materia' => $materia,
            'contenido' => $contenido,
            'accion' => $accion,
            'existente' => $existente,
            'generacion' => $generacion,
            'errores' => session('observaciones', $errores),
            'advertencias' => $servicio->advertencias($contenido, $materia),
            'temas' => $materia->temario()->where('tipo', 'tema')->orderBy('orden')->pluck('titulo'),
        ]);
    }

    private function entrada(Request $request): array
    {
        return json_decode($request->validate(['ejemplos' => ['required', 'json', 'max:400000']])['ejemplos'], true) ?: [];
    }

    private function autorizarMaterial(Material $m): void
    {
        abort_unless($m->tipo === EjemplosPracticos::TIPO, 404);
        Gate::authorize('gestionar', $m->materia);
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null || $g->tarea !== 'ejemplos', 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aprobado o descartado.');
    }
}
