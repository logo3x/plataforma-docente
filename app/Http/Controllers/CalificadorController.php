<?php

namespace App\Http\Controllers;

use App\Calificacion\CalificadorEntrega;
use App\Calificacion\PasoAlRegistro;
use App\Evaluacion\ConfiguracionActividad;
use App\Evaluacion\GeneradorExamen;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\ProcesadorEntregas;
use App\Evaluacion\UsoPreguntas;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Componente;
use App\Models\Entrega;
use App\Models\Materia;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // 5.4.13: avisa si hay estudiantes que ya respondieron preguntas de este examen en otro periodo
        $vistas = app(UsoPreguntas::class)->resumen($artefacto, $dictado, $grupos->all());

        return redirect()->route('calificador.show', $actividad)
            ->with('estado', 'Actividad aplicada: ya aparece como columna en el registro. Descargue el examen y, después de aplicarlo, suba aquí las entregas.')
            ->with('aviso_vistas', $vistas ? ($vistas['estudiantes'] === 1 ? 'Un estudiante de estos grupos ya respondió' : "Hasta {$vistas['estudiantes']} estudiantes de estos grupos ya respondieron")
                .', en '.implode(', ', $vistas['periodos']).', '.($vistas['preguntas'] === 1 ? 'una pregunta' : "{$vistas['preguntas']} preguntas").' de este examen. Si no quiere repetírselas, cámbielas en el banco o use otra versión.' : null);
    }

    /**
     * 6.2.3: modalidad II. La práctica se entrega aparte y solo se registra su nota: una columna junto al examen,
     * en el mismo componente y para los mismos grupos, donde se digitan la nota y el comentario.
     */
    public function columnaPractica(Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $practica = $actividad->practica ?? DB::transaction(function () use ($actividad) {
            $p = $actividad->componente->actividades()->create([
                'nombre' => Str::limit('Práctica — '.$actividad->nombre, 120, ''),
                'valor_max' => $actividad->valor_max,
                'practica_de_id' => $actividad->id,
                'orden' => $actividad->orden + 1,
            ]);
            $p->grupos()->attach($actividad->grupos()->pluck('grupos.id'));

            return $p;
        });
        $grupo = $actividad->grupos()->first() ?? $actividad->componente->division->dictado->grupos()->first();

        return redirect()->route('registro', $grupo)->with('estado', "Columna «{$practica->nombre}» lista en «{$actividad->componente->nombre}»: digite ahí la nota de la práctica y su comentario.");
    }

    /** El examen de un banco (5.4.2): el banco 2 es para la otra jornada, con otros datos. */
    public function examen(Request $request, Actividad $actividad, GeneradorExamen $generador): Response|RedirectResponse
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404);
        $banco = $request->integer('banco', 1);
        abort_unless(in_array($banco, $actividad->artefacto->bancos(), true), 404, 'El examen no tiene ese banco.');
        // 9.2: si el docente lo exige, el examen real no sale sin un ensayo de estas mismas preguntas
        if (ConfiguracionActividad::efectiva($actividad->artefacto)['exigir_ensayo'] && $actividad->artefacto->ensayado_en === null) {
            return redirect()->route('calificador.ensayo', $actividad)
                ->withErrors(['ensayo' => 'Este examen exige el ensayo antes de aplicarlo: descargue el examen de ensayo, pruébelo en un equipo de la sala y suba aquí su entrega.']);
        }

        return response($generador->html($actividad, banco: $banco), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$actividad->artefacto->sigla.($banco === 2 ? '_banco2' : '').'.html"',
        ]);
    }

    // ---------- Calificador ----------

    public function show(Request $request, Actividad $actividad): View
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

        // 6.2.4: filtros por estado y por alerta; los indicadores son siempre de todo el grupo
        $estado = $request->query('estado');
        $alerta = $request->query('alerta');
        $visibles = $filas
            ->when($estado === 'pendientes', fn ($c) => $c->filter(fn ($f) => $f['retos'] + $f['abiertas'] > 0))
            ->when($estado === 'calificadas', fn ($c) => $c->filter(fn ($f) => $f['nota'] !== null))
            ->when($estado === 'reprobadas', fn ($c) => $c->filter(fn ($f) => $f['nota'] !== null && ! $escala->aprueba($f['nota'])))
            ->when($alerta === 'con', fn ($c) => $c->filter(fn ($f) => $f['entrega']->alertas->isNotEmpty()))
            ->when($alerta === 'sin', fn ($c) => $c->filter(fn ($f) => $f['entrega']->alertas->isEmpty()))
            ->when($alerta && ! in_array($alerta, ['con', 'sin'], true), fn ($c) => $c->filter(fn ($f) => $f['entrega']->alertas->contains('tipo', $alerta)))
            ->values();

        return view('calificador.ver', [
            'actividad' => $actividad,
            'filas' => $visibles,
            'total' => $filas->count(),
            'filtros' => ['estado' => $estado, 'alerta' => $alerta],
            'tiposPresentes' => $entregas->flatMap->alertas->pluck('tipo')->unique()->values(),
            'escala' => $escala,
            'indicadores' => [
                'procesadas' => $entregas->count(),
                'calificadas' => $notas->count(),
                'promedio' => $notas->isEmpty() ? null : $escala->mostrar((string) ($notas->sum(fn ($n) => (float) $n) / $notas->count())),
                'aprobados' => $notas->filter(fn ($n) => $escala->aprueba($n))->count(),
                'reprobados' => $notas->reject(fn ($n) => $escala->aprueba($n))->count(),
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
                    'lenguaje' => $r->item->pregunta->enunciado['lenguaje'] ?? 'javascript',
                    'casos' => $r->item->pregunta->enunciado['casos_visibles'] ?? [],
                    'esquema' => $r->item->pregunta->enunciado['esquema'] ?? null,
                    'verificaciones' => collect($r->item->pregunta->clave['verificaciones'])->map(fn ($v) => ImportadorBanco::criterio($v) + ['id' => $v['id']])->values(),
                ]))
            ->values();

        return response()->json(['tiempo_ms' => (int) ($config['tiempo_ejecucion_ms'] ?? 2000), 'pendientes' => $pendientes]);
    }

    /** Lleva las notas completas al registro, sin transcripción (6.6). */
    public function pasarAlRegistro(Request $request, Actividad $actividad, PasoAlRegistro $paso): RedirectResponse
    {
        $this->autorizar($actividad);
        ['operacion' => $op, 'pasadas' => $pasadas, 'pendientes' => $pendientes, 'conservadas' => $conservadas] = $paso->ejecutar($actividad);

        $grupo = $actividad->grupos()->first() ?? $actividad->componente->division->dictado->grupos()->first();
        $pila = $request->session()->get("deshacer.grupo.{$grupo->id}", []);
        $pila[] = ['op' => $op, 'descripcion' => "Notas de «{$actividad->nombre}» desde el calificador"];
        $request->session()->put("deshacer.grupo.{$grupo->id}", array_slice($pila, -50));

        return back()->with('estado', "{$pasadas} entregas pasadas al registro".($pendientes ? "; {$pendientes} siguen con ítems por calificar." : '.')
            .($conservadas ? " Se conservaron {$conservadas} nota(s) ajustadas a un solo estudiante (reclamo aceptado o corrección a mano)." : ''));
    }

    /** Calificación de respuestas abiertas por pregunta (6.7). */
    public function abiertas(Actividad $actividad): View
    {
        $this->autorizar($actividad);

        return view('calificador.abiertas', ['actividad' => $actividad->load('componente.division.dictado.materia')]);
    }

    /**
     * Las respuestas abiertas de todos los estudiantes con su puntaje y el comentario del docente (6.7.14):
     * una fila por entrega y, por cada pregunta abierta, su puntaje, el comentario y la respuesta. CSV para Excel en español.
     */
    public function abiertasCsv(Actividad $actividad): StreamedResponse
    {
        $this->autorizar($actividad);
        $items = $actividad->artefacto?->items()->with('pregunta')->get()->filter(fn ($i) => $i->pregunta->tipo === 'abierta')->values() ?? collect();
        $entregas = $actividad->entregas()->with('integrantes', 'respuestas')->get()
            ->sortBy(fn ($e) => $e->integrantes->first()?->apellidos)->values();
        $num = fn ($v) => $v === null ? '' : str_replace('.', ',', (string) BigDecimal::of($v)->strippedOfTrailingZeros());

        return response()->streamDownload(function () use ($items, $entregas, $num) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\u{FEFF}");
            $cab = ['Documento', 'Estudiante'];
            foreach ($items as $k => $i) {
                $n = 'P'.($k + 1);
                array_push($cab, "{$n} puntaje (de {$num($i->peso)})", "{$n} comentario", "{$n} respuesta");
            }
            fputcsv($f, [...$cab, 'Total abiertas', 'Sin calificar'], ';');
            foreach ($entregas as $e) {
                $fila = [$e->integrantes->pluck('documento')->join(' / '), $e->integrantes->map->nombreCompleto()->join(' / ')];
                $total = BigDecimal::zero();
                $faltan = 0;
                foreach ($items as $i) {
                    $r = $e->respuestas->firstWhere('artefacto_pregunta_id', $i->id);
                    $faltan += $r && $r->puntaje === null ? 1 : 0;
                    $total = $r?->puntaje !== null ? $total->plus($r->puntaje) : $total;
                    array_push($fila, $num($r?->puntaje), (string) $r?->comentario, is_string($r?->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '');
                }
                fputcsv($f, [...$fila, $num((string) $total), $faltan], ';');
            }
            fclose($f);
        }, 'abiertas-'.Str::slug($actividad->nombre).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Detalle autoexplicativo de una entrega (6.4). */
    public function detalle(Entrega $entrega): View
    {
        $this->autorizar($entrega->actividad);
        $entrega->load('integrantes.grupos', 'alertas', 'respuestas.item.pregunta', 'actividad.componente.division.dictado');

        return view('calificador.detalle', [
            'entrega' => $entrega,
            'nota' => $this->calificador->nota($entrega),
            'partes' => $this->calificador->explicacionPartes($entrega),
            'escala' => $entrega->actividad->componente->division->dictado->escala(),
        ]);
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
