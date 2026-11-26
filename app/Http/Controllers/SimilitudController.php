<?php

namespace App\Http\Controllers;

use App\Calificacion\Similitud;
use App\Models\Actividad;
use App\Models\Entrega;
use App\Models\RevisionSimilitud;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Similitud entre entregas (6.11): pares parecidos, comparación lado a lado y lo que concluyó el docente. */
class SimilitudController extends Controller
{
    public function index(Request $request, Actividad $actividad, Similitud $similitud): View
    {
        $this->autorizar($actividad);
        $otros = $request->boolean('otros');
        $revisiones = RevisionSimilitud::query()->where('actividad_id', $actividad->id)->get()->keyBy(fn ($r) => "{$r->entrega_a_id}-{$r->entrega_b_id}");

        return view('calificador.similitud', [
            'actividad' => $actividad->load('componente.division.dictado.materia'),
            'pares' => $similitud->pares($actividad, $otros),
            'revisiones' => $revisiones,
            'otros' => $otros,
            'reutilizada' => $actividad->artefacto_id && Actividad::query()->where('artefacto_id', $actividad->artefacto_id)->whereKeyNot($actividad->id)->exists(),
            'entregas' => $actividad->entregas()->count(),
        ]);
    }

    public function ver(Request $request, Actividad $actividad, Entrega $a, Entrega $b, Similitud $similitud): View
    {
        $this->autorizar($actividad);
        abort_unless($a->actividad_id === $actividad->id, 404);
        abort_unless($b->actividad_id === $actividad->id || ($actividad->artefacto_id && $b->actividad?->artefacto_id === $actividad->artefacto_id), 404);
        Gate::authorize('gestionar', $b->actividad->componente->division->dictado);

        $par = $similitud->pares($actividad, $b->actividad_id !== $actividad->id)
            ->first(fn ($p) => $p['a']->id === $a->id && $p['b']->id === $b->id);
        $puntajes = collect($par['items'] ?? [])->keyBy('item');

        $respB = $b->respuestas()->with('item.pregunta')->get()->keyBy(fn ($r) => $r->item->pregunta_id);
        $comparaciones = $a->respuestas()->with('item.pregunta')->get()->sortBy(fn ($r) => $r->item->orden)
            ->filter(fn ($r) => in_array($r->item->pregunta->tipo, ['abierta', 'reto'], true) && isset($respB[$r->item->pregunta_id]))
            ->map(function ($r) use ($respB, $similitud, $puntajes) {
                $codigo = $r->item->pregunta->tipo === 'reto';
                $ta = is_string($r->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '';
                $tb = is_string($respB[$r->item->pregunta_id]->respuesta['valor'] ?? null) ? $respB[$r->item->pregunta_id]->respuesta['valor'] : '';

                return ['numero' => $r->item->orden + 1, 'enunciado' => $r->item->pregunta->enunciado['texto'] ?? '', 'codigo' => $codigo,
                    'puntaje' => $puntajes[$r->item->pregunta_id]['puntaje'] ?? null, 'lados' => $similitud->comparar($ta, $tb, $codigo)];
            })->values();

        return view('calificador.similitud-par', [
            'actividad' => $actividad, 'a' => $a->load('integrantes'), 'b' => $b->load('integrantes', 'actividad.componente.division.dictado'),
            'comparaciones' => $comparaciones, 'puntaje' => $par['puntaje'] ?? null,
            'revision' => RevisionSimilitud::query()->where(['actividad_id' => $actividad->id, 'entrega_a_id' => $a->id, 'entrega_b_id' => $b->id])->first(),
        ]);
    }

    public function revisar(Request $request, Actividad $actividad, Entrega $a, Entrega $b): RedirectResponse
    {
        $this->autorizar($actividad);
        abort_unless($a->actividad_id === $actividad->id, 404);
        $d = $request->validate(['conclusion' => ['required', Rule::in(array_keys(RevisionSimilitud::CONCLUSIONES))], 'nota' => ['nullable', 'string', 'max:1000']]);
        RevisionSimilitud::query()->updateOrCreate(
            ['actividad_id' => $actividad->id, 'entrega_a_id' => $a->id, 'entrega_b_id' => $b->id],
            $d + ['user_id' => $request->user()->id],
        );

        return redirect()->route('calificador.similitud', $actividad)->with('estado', 'Conclusión guardada: «'.RevisionSimilitud::CONCLUSIONES[$d['conclusion']].'». Ninguna nota cambió.');
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
