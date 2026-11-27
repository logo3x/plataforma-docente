<?php

namespace App\Http\Controllers;

use App\Historial\Versiones;
use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Material\GeneradorMaterial;
use App\Material\TipoMaterial;
use App\Models\ConfigMotorIa;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Pantallas comunes de un generador de material (7.4): lista con formulario de generación, editor
 * (a mano o borrador de la IA), vista imprimible y el flujo prompt → borrador → aprobación.
 * Cada tipo aporta su generador, el prefijo de sus rutas y vistas y, si los necesita, datos extra.
 */
abstract class MaterialController extends Controller
{
    abstract protected function generador(): GeneradorMaterial;

    /** Prefijo de los nombres de ruta y de la carpeta de vistas («ejemplos», «debates»). */
    abstract protected function ruta(): string;

    /** Nombre en plural para las migas del prompt. */
    abstract protected function nombre(): string;

    /** Datos extra para las vistas «show» y «editor». */
    protected function datosVista(string $vista, Materia $materia, array $contenido): array
    {
        return [];
    }

    /** Problemas detectados por la plataforma que se le devuelven a la IA al corregir. */
    protected function problemasCorreccion(Request $request): array
    {
        return [];
    }

    /** Ajuste propio del tipo a lo pedido en el formulario de generación, antes de armar el prompt. */
    protected function ajustarGeneracion(array $d, Materia $materia, Dictado $dictado): array
    {
        return $d;
    }

    protected function tipo(): TipoMaterial
    {
        return $this->generador()->tipo;
    }

    public function index(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view($this->ruta().'.index', [
            'materia' => $materia,
            'conjuntos' => $materia->materiales()->where('tipo', $this->tipo()->tipo())->latest()->get(),
            'unidades' => $materia->temario()->whereNull('parent_id')->with(['hijos' => fn ($q) => $q->where('tipo', 'tema')->orderBy('orden')])->orderBy('orden')->get(),
            'marcados' => array_map('intval', (array) old('temas', $request->input('temas', []))),
            'dictados' => $materia->dictados()->with('grupos')->latest()->get(),
            'contenidos' => $materia->contenidos()->with('tema')->latest()->get(['id', 'titulo', 'temario_item_id']),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $materia->id)->where('tarea', $this->tipo()->tipo())->where('estado', 'borrador')->latest()->limit(10)->get(),
        ]);
    }

    public function create(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);
        $temas = $materia->temario()->where('tipo', 'tema')->whereIn('id', (array) $request->input('temas', []))->orderBy('orden')->pluck('titulo')->all();

        return $this->editor($materia, $this->tipo()->plantillaVacia($temas), route($this->ruta().'.store', $materia), plantilla: true);
    }

    public function store(Request $request, Materia $materia): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $datos = $this->entrada($request);
        if ($e = $this->tipo()->errores($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $d = $this->tipo()->normalizar($datos);
        $m = $materia->materiales()->create(['tipo' => $this->tipo()->tipo(), 'titulo' => $d['titulo'], 'contenido' => $d]);

        return redirect()->route($this->ruta().'.show', $m)->with('estado', "«{$m->titulo}» guardado.");
    }

    public function show(Request $request, Material $material): View
    {
        $this->autorizarMaterial($material);

        return view($this->ruta().'.show', [
            'm' => $material->load('materia', 'generacion.motor'),
            'c' => $material->contenido,
            'estudiante' => $request->query('version') === 'estudiante',
            'advertencias' => $this->tipo()->advertencias($material->contenido, $material->materia),
        ] + $this->datosVista('show', $material->materia, $material->contenido));
    }

    public function edit(Material $material): View
    {
        $this->autorizarMaterial($material);

        return $this->editor($material->materia, $material->contenido, route($this->ruta().'.update', $material), $material);
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $this->autorizarMaterial($material);
        $datos = $this->entrada($request);
        if ($e = $this->tipo()->errores($datos)) {
            return back()->withInput()->with('observaciones', $e);
        }
        $d = $this->tipo()->normalizar($datos);
        app(Versiones::class)->guardar($material);        // 7.6: cómo estaba
        $material->update(['titulo' => $d['titulo'], 'contenido' => $d]);

        return redirect()->route($this->ruta().'.show', $material)->with('estado', 'Cambios guardados.');
    }

    public function destroy(Material $material): RedirectResponse
    {
        $this->autorizarMaterial($material);
        $material->delete();

        return redirect()->route($this->ruta().'.index', $material->materia)->with('estado', "«{$material->titulo}» eliminado.");
    }

    public function preparar(Request $request, Materia $materia): View|RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate([
            'dictado_id' => ['required', 'integer'],
            'temas' => ['nullable', 'array'],
            'temas.*' => ['integer'],
            'tema' => ['nullable', 'string', 'max:1000'],
            'grupo' => ['nullable', 'string', 'max:60'],
            'contenidos' => ['nullable', 'array'],
            'contenidos.*' => ['integer'],
            'motor_id' => ['nullable', 'integer'],
        ] + $this->tipo()->reglasGeneracion());
        $dictado = $materia->dictados()->findOrFail($d['dictado_id']);

        try {
            $d = $this->ajustarGeneracion($d + ['temas' => [], 'contenidos' => []], $materia, $dictado);
            $prompt = $this->generador()->preparar($request->user(), $materia, $dictado, $d);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['tema' => $e->getMessage()]);
        }
        $request->session()->put($this->ruta().'.meta', ['plantillas' => $prompt['plantillas'], 'contenidos' => $prompt['contenidos']]);

        return view('generacion.prompt', [
            'materia' => $materia, 'prompt' => $prompt, 'motorId' => $d['motor_id'] ?? null, 'motor' => $this->motor($request, $d['motor_id'] ?? null),
            'accion' => route($this->ruta().'.generar', $materia), 'volver' => route($this->ruta().'.index', $materia), 'migas' => $this->nombre(),
        ]);
    }

    public function generar(Request $request, Materia $materia): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['sistema' => ['required', 'string', 'max:200000'], 'usuario' => ['required', 'string', 'max:400000'], 'motor_id' => ['nullable', 'integer']]);

        try {
            $r = $this->generador()->generar($request->user(), $materia, $d['sistema'], $d['usuario'], $request->session()->get($this->ruta().'.meta', []), $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return redirect()->route($this->ruta().'.index', $materia)->with('error_ia', $e->getMessage());
        }

        return redirect()->route($this->ruta().'.borrador', $r['generacion']);
    }

    public function borrador(GeneracionIa $generacion): View
    {
        $this->autorizar($generacion);
        $datos = json_decode((string) $generacion->borrador, true);

        return $this->editor($generacion->materia, is_array($datos) ? $datos : ['titulo' => ''], route($this->ruta().'.borrador.editar', $generacion),
            generacion: $generacion->load('motor'), errores: $this->generador()->errores($generacion->borrador));
    }

    public function editarBorrador(Request $request, GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $this->generador()->editar($generacion, $this->entrada($request));

        return $request->boolean('aprobar') ? $this->aprobar($generacion) : back()->with('estado', 'Borrador guardado y validado de nuevo.');
    }

    public function corregir(Request $request, GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $r = $this->generador()->corregir($request->user(), $generacion, null, $this->problemasCorreccion($request));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route($this->ruta().'.borrador', $r['generacion'])
            ->with('estado', $r['errores'] ? 'La IA devolvió una versión nueva; aún tiene observaciones.' : 'La IA devolvió una versión nueva. Revísela antes de guardarla.');
    }

    public function aprobar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        try {
            $m = $this->generador()->aprobar($generacion);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route($this->ruta().'.show', $m)->with('estado', "«{$m->titulo}» guardado.");
    }

    public function descartar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route($this->ruta().'.index', $generacion->materia)->with('estado', 'Borrador descartado (queda en la trazabilidad).');
    }

    private function editor(Materia $materia, array $contenido, string $accion, ?Material $existente = null, ?GeneracionIa $generacion = null, array $errores = [], bool $plantilla = false): View
    {
        Gate::authorize('gestionar', $materia);
        $viejo = json_decode((string) old('contenido'), true);
        // La plantilla vacía del formulario nuevo no se normaliza: sus filas en blanco se descartarían
        $contenido = is_array($viejo) ? $this->tipo()->normalizar($viejo) : ($plantilla ? $contenido : $this->tipo()->normalizar($contenido));

        return view($this->ruta().'.editor', [
            'materia' => $materia,
            'contenido' => $contenido,
            'accion' => $accion,
            'existente' => $existente,
            'generacion' => $generacion,
            'errores' => session('observaciones', $errores),
            'advertencias' => $this->tipo()->advertencias($contenido, $materia),
            'temas' => $materia->temario()->where('tipo', 'tema')->orderBy('orden')->pluck('titulo'),
        ] + $this->datosVista('editor', $materia, $contenido));
    }

    private function entrada(Request $request): array
    {
        return json_decode($request->validate(['contenido' => ['required', 'json', 'max:400000']])['contenido'], true) ?: [];
    }

    protected function autorizarMaterial(Material $m): void
    {
        abort_unless($m->tipo === $this->tipo()->tipo(), 404);
        Gate::authorize('gestionar', $m->materia);
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null || $g->tarea !== $this->tipo()->tipo(), 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aprobado o descartado.');
    }
}
