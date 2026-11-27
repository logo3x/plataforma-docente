<?php

namespace App\Http\Controllers;

use App\Calificacion\AjustesDeExamen;
use App\Calificacion\InformeEstudiante;
use App\Calificacion\Reclamos;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\Materia;
use App\Models\Reclamo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Reclamos y anulaciones atendidos (6.5.4, 6.5.6). */
class ReclamoController extends Controller
{
    public function index(Actividad $actividad, InformeEstudiante $informe): View
    {
        $this->autorizar($actividad);

        return view('reclamos.actividad', [
            'actividad' => $actividad->load('componente.division.dictado.materia', 'artefacto.items.pregunta'),
            'estudiantes' => $informe->destinatarios($actividad)->filter(fn ($e) => $informe->presento($actividad, $e))->values(),
            'reclamos' => $actividad->reclamos()->with('estudiante', 'item.pregunta', 'docente')->latest()->get(),
            'escala' => $actividad->componente->division->dictado->escala(),
            'corregibles' => AjustesDeExamen::CORREGIBLES,
            'numero' => fn ($item) => app(AjustesDeExamen::class)->numero($item),
        ]);
    }

    public function store(Request $request, Actividad $actividad, Reclamos $reclamos): RedirectResponse
    {
        $this->autorizar($actividad);
        $d = $request->validate([
            'estudiante_id' => ['required', 'integer'],
            'artefacto_pregunta_id' => ['nullable', 'integer'],
            'descripcion' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $e = $actividad->componente->division->dictado->estudiantes()->findOrFail($d['estudiante_id']);

        try {
            $reclamos->registrar($actividad, $e, $d['artefacto_pregunta_id'] ?? null, $d['descripcion']);
        } catch (InvalidArgumentException $ex) {
            return back()->withInput()->withErrors(['descripcion' => $ex->getMessage()]);
        }

        return back()->with('estado', "Reclamo de {$e->nombreCompleto()} registrado.");
    }

    public function resolver(Request $request, Reclamo $reclamo, Reclamos $reclamos): RedirectResponse
    {
        $this->autorizar($reclamo->actividad);
        $escala = $reclamo->actividad->componente->division->dictado->escala();
        $d = $request->validate([
            'decision' => ['required', Rule::in(['rechazar', 'aceptar'])],
            'resolucion' => ['required', 'string', 'min:5', 'max:1000'],
            'accion' => ['required_if:decision,aceptar', 'nullable', Rule::in(array_keys(Reclamo::ACCIONES))],
            'regla' => ['required_if:accion,anulacion', 'nullable', Rule::in(['dar_por_buena', 'retirar_y_reescalar'])],
            'valor' => ['required_if:accion,ajuste_nota', 'nullable', 'numeric'],
            'correcta' => ['nullable', 'string', 'max:20'],
            'correctas' => ['nullable', 'array'],
            'huecos' => ['nullable', 'array'],
        ]);

        try {
            if ($d['decision'] === 'rechazar') {
                $reclamos->rechazar($reclamo, $d['resolucion']);
                $mensaje = 'Reclamo rechazado; la respuesta queda registrada.';
            } else {
                if ($d['accion'] === 'ajuste_nota' && ! $escala->contiene((string) $d['valor'])) {
                    throw new InvalidArgumentException("La nota {$d['valor']} está fuera de la escala.");
                }
                if ($d['accion'] === 'anulacion' && $reclamo->item?->anulada_en) {
                    throw new InvalidArgumentException('Ese ítem ya está anulado.');
                }
                $reclamos->aceptar($reclamo, $d + ['clave' => $this->clave($reclamo, $d)]);
                $det = $reclamo->fresh()->detalle;
                $mensaje = 'Reclamo aceptado. '.Reclamo::ACCIONES[$d['accion']]
                    .(! isset($det['notas_recalculadas']) ? '.'
                        : ($det['notas_recalculadas'] ? ": {$det['notas_recalculadas']} nota(s) del registro cambiaron." : '. Ninguna nota del registro cambió.'));
            }
        } catch (InvalidArgumentException $ex) {
            return back()->withInput()->with('error_reclamo', $ex->getMessage())->with('reclamo_abierto', $reclamo->id);
        }

        return back()->with('estado', $mensaje);
    }

    /** Registro de la materia: reclamos de todos los periodos, para no repetir el error (6.5.6). */
    public function materia(Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('reclamos.materia', [
            'materia' => $materia,
            'reclamos' => Reclamo::query()
                ->whereHas('actividad.componente.division.dictado', fn ($q) => $q->where('materia_id', $materia->id))
                ->with('actividad.componente.division.dictado', 'actividad.artefacto', 'estudiante', 'item.pregunta')
                ->latest()->get(),
            // 6.5.6: también las anulaciones de ítems, se hayan hecho por un reclamo o desde Estadísticas
            'anulaciones' => ArtefactoPregunta::query()->whereNotNull('anulada_en')
                ->whereHas('artefacto', fn ($q) => $q->where('materia_id', $materia->id))
                ->with('artefacto', 'pregunta')->latest('anulada_en')->get(),
        ]);
    }

    /** Clave nueva según el tipo del ítem, a partir del formulario. */
    private function clave(Reclamo $r, array $d): ?array
    {
        if (($d['accion'] ?? null) !== 'correccion_clave' || $r->item === null) {
            return null;
        }

        return match ($r->item->pregunta->tipo) {
            'unica' => ['correcta' => (string) ($d['correcta'] ?? '')],
            'vf' => ['correcta' => ($d['correcta'] ?? '') === 'true'],
            'multiple' => ['correctas' => array_values($d['correctas'] ?? [])],
            'completar' => ['huecos' => array_map(
                fn ($h) => array_values(array_filter(array_map('trim', explode('|', (string) $h)), fn ($x) => $x !== '')),
                array_values($d['huecos'] ?? []),
            )],
            default => null,
        };
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
