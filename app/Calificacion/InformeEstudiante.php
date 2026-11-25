<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\SegundaOportunidad;
use App\Notas\TableroNotas;

/**
 * Informe individual del estudiante (6.5): su nota vigente, el detalle autoexplicativo de su
 * entrega (6.4), los comentarios del docente y los ítems anulados con su motivo.
 *
 * Nunca incluye las alertas internas, la sesión ni datos de otros estudiantes (6.9.4). Solo
 * nombra a los compañeros de su misma entrega grupal, que comparten la nota.
 */
final class InformeEstudiante
{
    /** @return array<string, mixed> datos para la vista «informe.estudiante» */
    public function datos(Actividad $actividad, Estudiante $estudiante): array
    {
        $actividad->loadMissing('componente.division.dictado.materia', 'artefacto');
        $dictado = $actividad->componente->division->dictado;
        $escala = $dictado->escala();

        $entrega = $actividad->entregas()
            ->whereHas('integrantes', fn ($q) => $q->whereKey($estudiante->id))
            ->with(['integrantes', 'respuestas.item.pregunta'])
            ->latest()->first();

        // La nota vigente es la del registro, que ya incluye supletorios y recuperaciones (3.6)
        $grupo = $this->grupoDe($actividad, $estudiante);
        $tablero = $grupo ? new TableroNotas($grupo) : null;
        $original = $tablero?->nota($actividad->id, $estudiante->id);
        $vigente = $tablero?->notaEfectiva($actividad->id, $estudiante->id);
        $segunda = $tablero?->segunda($actividad->getMorphClass(), $actividad->id, $estudiante->id);

        return [
            'actividad' => $actividad,
            'materia' => $dictado->materia,
            'dictado' => $dictado,
            'estudiante' => $estudiante,
            'escala' => $escala,
            'entrega' => $entrega,
            'companeros' => $entrega?->integrantes->reject(fn ($i) => $i->id === $estudiante->id)->values() ?? collect(),
            'vigente' => $vigente,
            'original' => $original,
            'segunda' => $segunda,
            'tipoSegunda' => $segunda ? SegundaOportunidad::TIPOS[$segunda->tipo] : null,
            'mostrarOriginal' => (bool) $actividad->informe_muestra_original,
            'comentarioNota' => $tablero ? $actividad->notas()->where('estudiante_id', $estudiante->id)->value('comentario') : null,
            'generado' => now(),
        ];
    }

    /** Estudiantes a quienes se les devuelve: los de los grupos de la actividad (o del dictado). */
    public function destinatarios(Actividad $actividad)
    {
        $grupos = $actividad->grupos()->pluck('grupos.id');
        $dictado = $actividad->componente->division->dictado;

        return $dictado->estudiantes()
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true)
                ->when($grupos->isNotEmpty(), fn ($w) => $w->whereIn('grupos.id', $grupos)))
            ->orderBy('apellidos')->orderBy('nombres')->get();
    }

    /** Quién presentó: tiene entrega procesada o nota en el registro. */
    public function presento(Actividad $actividad, Estudiante $e): bool
    {
        return Entrega::query()->where('actividad_id', $actividad->id)->whereHas('integrantes', fn ($q) => $q->whereKey($e->id))->exists()
            || $actividad->notas()->where('estudiante_id', $e->id)->whereNotNull('valor')->exists();
    }

    private function grupoDe(Actividad $actividad, Estudiante $e): ?Grupo
    {
        $ids = $actividad->grupos()->pluck('grupos.id');

        return $e->grupos()->when($ids->isNotEmpty(), fn ($q) => $q->whereIn('grupos.id', $ids))->first();
    }
}
