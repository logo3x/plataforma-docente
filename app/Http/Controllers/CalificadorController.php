<?php

namespace App\Http\Controllers;

use App\Calificacion\CalificadorEntrega;
use App\Calificacion\PasoAlRegistro;
use App\Evaluacion\GeneradorExamen;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\ProcesadorEntregas;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Componente;
use App\Models\Entrega;
use App\Models\Materia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/** Actividades evaluables y calificador (módulos 2 y 3). */
class CalificadorController extends Controller
{
    public function __construct(private readonly CalificadorEntrega $calificador) {}

    // ---------- Banco de actividades de la materia ----------

    public function artefactos(Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('calificador.artefactos', [
            'materia' => $materia,
            'artefactos' => $materia->artefactos()->withCount('items')->with('actividades.componente.division.dictado', 'actividades.grupos')->latest()->get(),
            'dictados' => $materia->dictados()->with('divisiones.componentes', 'grupos')->latest()->get(),
        ]);
    }

    public function importar(Request $request, Materia $materia, ImportadorBanco $importador): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $request->validate(['banco' => ['required', 'file', 'max:1024']]);

        $datos = json_decode((string) file_get_contents($request->file('banco')->getRealPath()), true);
        if (! is_array($datos)) {
            return back()->withErrors(['banco' => 'El archivo no es JSON válido.']);
        }
        try {
            $a = $importador->importar($materia, $datos);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['banco' => $e->getMessage()]);
        }

        return back()->with('estado', "Actividad {$a->sigla} importada con {$a->items()->count()} ítems. Aplíquela en un corte para generar el examen.");
    }

    public function aplicar(Request $request, Artefacto $artefacto): RedirectResponse
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $datos = $request->validate([
            'componente_id' => ['required', 'integer'],
            'grupos' => ['required', 'array', 'min:1'],
            'grupos.*' => ['integer'],
            'es_grupal' => ['nullable', 'boolean'],
        ]);
        $componente = Componente::query()->with('division.dictado')->findOrFail($datos['componente_id']);
        $dictado = $componente->division->dictado;
        abort_unless($dictado->materia_id === $artefacto->materia_id, 403);
        $grupos = $dictado->grupos()->whereIn('id', $datos['grupos'])->pluck('id');

        $actividad = $componente->actividades()->create([
            'artefacto_id' => $artefacto->id,
            'nombre' => $artefacto->titulo,
            'valor_max' => $dictado->escala_max,
            'es_grupal' => (bool) ($datos['es_grupal'] ?? false),
            'orden' => (int) $componente->actividades()->max('orden') + 1,
        ]);
        $actividad->grupos()->attach($grupos);

        return redirect()->route('calificador.show', $actividad)
            ->with('estado', 'Actividad aplicada: ya aparece como columna en el registro. Descargue el examen y, después de aplicarlo, suba aquí las entregas.');
    }

    public function examen(Actividad $actividad, GeneradorExamen $generador): Response
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404);

        return response($generador->html($actividad), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$actividad->artefacto->sigla.'.html"',
        ]);
    }

    // ---------- Calificador ----------

    public function show(Actividad $actividad): View
    {
        $this->autorizar($actividad);
        $actividad->load('artefacto', 'componente.division.dictado.materia', 'grupos');

        $entregas = $actividad->entregas()->with(['integrantes', 'respuestas.item.pregunta', 'alertas'])->latest()->get();
        $filas = $entregas->map(function (Entrega $e) {
            $pend = fn (string $tipo) => $e->respuestas->filter(fn ($r) => $r->puntaje === null && $r->item->pregunta->tipo === $tipo && ! $r->item->anulada_en)->count();

            return [
                'entrega' => $e,
                'servidor' => $e->respuestas->whereNotNull('puntaje')->sum(fn ($r) => (float) $r->puntaje),
                'retos' => $pend('reto'),
                'abiertas' => $pend('abierta'),
                'nota' => $this->calificador->nota($e),
            ];
        });

        $escala = $actividad->componente->division->dictado->escala();
        $notas = $filas->pluck('nota')->filter(fn ($n) => $n !== null);

        return view('calificador.ver', [
            'actividad' => $actividad,
            'filas' => $filas,
            'escala' => $escala,
            'indicadores' => [
                'procesadas' => $entregas->count(),
                'calificadas' => $notas->count(),
                'promedio' => $notas->isEmpty() ? null : $escala->mostrar((string) ($notas->sum(fn ($n) => (float) $n) / $notas->count())),
                'aprobados' => $notas->filter(fn ($n) => $escala->aprueba($n))->count(),
                'pendientes' => $filas->sum(fn ($f) => $f['retos'] + $f['abiertas']),
            ],
            'tieneAbiertas' => $actividad->artefacto?->items()->whereHas('pregunta', fn ($q) => $q->where('tipo', 'abierta'))->exists(),
        ]);
    }

    public function subir(Request $request, Actividad $actividad, ProcesadorEntregas $procesador): RedirectResponse
    {
        $this->autorizar($actividad);
        $request->validate(['entregas' => ['required', 'array', 'max:80'], 'entregas.*' => ['file', 'max:2048']]);

        $resultados = [];
        foreach ($request->file('entregas') as $archivo) {
            $resultados[] = $procesador->recibir($actividad, (string) file_get_contents($archivo->getRealPath()), $archivo->getClientOriginalName());
        }

        return back()->with('resultados', array_map(fn ($r) => ['estado' => $r['estado'], 'mensaje' => $r['mensaje']], $resultados));
    }

    /** Retos pendientes para ejecutarlos en el navegador del docente (calificación híbrida). */
    public function retosPendientes(Actividad $actividad): JsonResponse
    {
        $this->autorizar($actividad);
        $config = $actividad->artefacto?->configuracion ?? [];

        $pendientes = $actividad->entregas()->with('respuestas.item.pregunta')->get()
            ->flatMap(fn (Entrega $e) => $e->respuestas
                ->filter(fn ($r) => $r->puntaje === null && $r->item->pregunta->tipo === 'reto')
                ->map(fn ($r) => [
                    'entrega' => $e->id,
                    'item' => $r->artefacto_pregunta_id,
                    'codigo' => is_string($r->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '',
                    'casos' => $r->item->pregunta->enunciado['casos_visibles'] ?? [],
                    'verificaciones' => collect($r->item->pregunta->clave['verificaciones'])->map(fn ($v) => ['id' => $v['id'], 'prueba' => $v['prueba']])->values(),
                ]))
            ->values();

        return response()->json(['tiempo_ms' => (int) ($config['tiempo_ejecucion_ms'] ?? 2000), 'pendientes' => $pendientes]);
    }

    /** Lleva las notas completas al registro, sin transcripción (6.6). */
    public function pasarAlRegistro(Request $request, Actividad $actividad, PasoAlRegistro $paso): RedirectResponse
    {
        $this->autorizar($actividad);
        ['operacion' => $op, 'pasadas' => $pasadas, 'pendientes' => $pendientes] = $paso->ejecutar($actividad);

        $grupo = $actividad->grupos()->first() ?? $actividad->componente->division->dictado->grupos()->first();
        $pila = $request->session()->get("deshacer.grupo.{$grupo->id}", []);
        $pila[] = ['op' => $op, 'descripcion' => "Notas de «{$actividad->nombre}» desde el calificador"];
        $request->session()->put("deshacer.grupo.{$grupo->id}", array_slice($pila, -50));

        return back()->with('estado', "{$pasadas} entregas pasadas al registro".($pendientes ? "; {$pendientes} siguen con ítems por calificar." : '.'));
    }

    /** Calificación de respuestas abiertas por pregunta (6.7). */
    public function abiertas(Actividad $actividad): View
    {
        $this->autorizar($actividad);

        return view('calificador.abiertas', ['actividad' => $actividad->load('componente.division.dictado.materia')]);
    }

    /** Detalle autoexplicativo de una entrega (6.4). */
    public function detalle(Entrega $entrega): View
    {
        $this->autorizar($entrega->actividad);
        $entrega->load('integrantes', 'alertas', 'respuestas.item.pregunta', 'actividad.componente.division.dictado');

        return view('calificador.detalle', [
            'entrega' => $entrega,
            'nota' => $this->calificador->nota($entrega),
            'escala' => $entrega->actividad->componente->division->dictado->escala(),
        ]);
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
