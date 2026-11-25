<?php

namespace App\Http\Controllers;

use App\Calificacion\AjustesDeExamen;
use App\Calificacion\EstadisticasActividad;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\Grupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Estadísticas de grupo, análisis de ítems (6.10) y anulación de ítems (6.5.5). */
class EstadisticasController extends Controller
{
    public function show(Request $request, Actividad $actividad): View
    {
        $this->autorizar($actividad);
        $grupo = $this->grupo($request, $actividad);
        $e = new EstadisticasActividad($actividad, $grupo);
        $dictado = $actividad->componente->division->dictado;

        return view('calificador.estadisticas', [
            'actividad' => $actividad->load('artefacto', 'componente.division.dictado.materia'),
            'grupo' => $grupo,
            'grupos' => $actividad->grupos()->get()->whenEmpty(fn () => $dictado->grupos()->get()),
            'escala' => $e->escala(),
            'resumen' => $e->resumen(),
            'items' => $e->items(),
            'temas' => $dictado->materia->temario()->where('tipo', 'tema')->orderBy('parent_id')->orderBy('orden')->get(['id', 'titulo']),
            'proyectar' => $request->boolean('proyectar'),
        ]);
    }

    public function csv(Request $request, Actividad $actividad): StreamedResponse
    {
        $this->autorizar($actividad);
        $grupo = $this->grupo($request, $actividad);
        $e = new EstadisticasActividad($actividad, $grupo);
        $r = $e->resumen();
        $esc = $e->escala();

        return response()->streamDownload(function () use ($e, $r, $esc, $actividad, $grupo) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\u{FEFF}");
            $fila = fn (array $c) => fputcsv($f, array_map(fn ($v) => is_float($v) ? str_replace('.', ',', (string) $v) : $v, $c), ';');
            $fila(['Actividad', $actividad->nombre, 'Grupo', $grupo?->nombre ?? 'Todos']);
            $fila(['Entregas calificadas', $r['n'], 'Promedio', $r['promedio'] === null ? '' : $esc->mostrar($r['promedio']), 'Aprobados', $r['aprobados'], 'Reprobados', $r['reprobados']]);
            $fila([]);
            $fila(['Ítem', 'Tipo', 'Tema', 'Enunciado', 'Respuestas', 'Acierto %', 'Discriminación', 'Anulado', 'Revisar']);
            foreach ($e->items() as $i) {
                $fila([$i['numero'], $i['tipo'], $i['tema'] ?? '', $i['texto'], $i['respuestas'],
                    $i['acierto'] === null ? '' : round($i['acierto'] * 100, 1), $i['discriminacion'] ?? '',
                    $i['item']->anulada_en ? 'Sí' : 'No', implode(' ', $i['marcas'])]);
            }
            fclose($f);
        }, 'estadisticas-'.str($actividad->nombre)->slug().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * 6.5.5: anula un ítem para todo el grupo, deja traza con su motivo y reprocesa el grupo completo
     * (6.5.4). Las notas que ya estaban en el registro se actualizan y quedan en la bitácora.
     */
    public function anular(Request $request, ArtefactoPregunta $item, AjustesDeExamen $ajustes): RedirectResponse
    {
        $this->actividadDelItem($request, $item);
        $d = $request->validate([
            'regla' => ['required', Rule::in(['dar_por_buena', 'retirar_y_reescalar'])],
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $pasadas = $ajustes->anular($item, $d['regla'], $d['motivo']);

        return back()->with('estado', "Ítem {$this->numero($item)} anulado para todos los grupos que presentaron este examen. "
            .($pasadas ? "{$pasadas} nota(s) del registro cambiaron y quedaron en la bitácora." : 'Ninguna nota del registro cambió.'));
    }

    public function restaurar(Request $request, ArtefactoPregunta $item, AjustesDeExamen $ajustes): RedirectResponse
    {
        $this->actividadDelItem($request, $item);
        $pasadas = $ajustes->restaurar($item);

        return back()->with('estado', "La anulación del ítem {$this->numero($item)} se revirtió".($pasadas ? "; {$pasadas} nota(s) del registro cambiaron." : '.'));
    }

    /** Vincula a mano una pregunta con un tema del temario (6.10.4). */
    public function tema(Request $request, ArtefactoPregunta $item): RedirectResponse
    {
        $actividad = $this->actividadDelItem($request, $item);
        $temaId = $request->integer('temario_item_id') ?: null;
        if ($temaId !== null) {
            abort_unless($actividad->componente->division->dictado->materia->temario()->where('tipo', 'tema')->whereKey($temaId)->exists(), 422);
        }
        $item->pregunta->update(['temario_item_id' => $temaId]);

        return back()->with('estado', "Tema del ítem {$this->numero($item)} actualizado.");
    }

    private function actividadDelItem(Request $request, ArtefactoPregunta $item): Actividad
    {
        $actividad = Actividad::query()->where('artefacto_id', $item->artefacto_id)->findOrFail($request->integer('actividad_id'));
        $this->autorizar($actividad);

        return $actividad;
    }

    private function numero(ArtefactoPregunta $item): int
    {
        return ArtefactoPregunta::query()->where('artefacto_id', $item->artefacto_id)->where('orden', '<', $item->orden)->count() + 1;
    }

    private function grupo(Request $request, Actividad $actividad): ?Grupo
    {
        $id = $request->integer('grupo');

        return $id ? $actividad->componente->division->dictado->grupos()->findOrFail($id) : null;
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
