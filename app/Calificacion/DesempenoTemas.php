<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Grupo;

/**
 * Desempeño del grupo por tema del temario, sumando todas las actividades calificadas del
 * periodo (6.10.4). Alimenta la marca de «bajo desempeño» en la lista de chequeo.
 */
final class DesempenoTemas
{
    /** Por debajo de este acierto el tema se resalta para reforzarlo. */
    public const UMBRAL = 0.6;

    /** @return array<int, array{acierto: float, respuestas: int}> por id de tema */
    public function deGrupo(Grupo $grupo): array
    {
        $actividades = Actividad::query()
            ->whereNotNull('artefacto_id')
            ->whereHas('componente.division', fn ($q) => $q->where('dictado_id', $grupo->dictado_id))
            ->where(fn ($q) => $q->whereDoesntHave('grupos')->orWhereHas('grupos', fn ($g) => $g->whereKey($grupo->id)))
            ->whereHas('entregas')
            ->get();

        $acum = [];
        foreach ($actividades as $a) {
            foreach ((new EstadisticasActividad($a, $grupo))->porTema() as $tema => $d) {
                $acum[$tema]['ponderado'] = ($acum[$tema]['ponderado'] ?? 0) + $d['acierto'] * $d['respuestas'];
                $acum[$tema]['respuestas'] = ($acum[$tema]['respuestas'] ?? 0) + $d['respuestas'];
            }
        }

        return array_map(fn ($a) => ['acierto' => round($a['ponderado'] / max($a['respuestas'], 1), 3), 'respuestas' => $a['respuestas']], $acum);
    }
}
