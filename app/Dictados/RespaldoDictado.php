<?php

namespace App\Dictados;

use App\Models\Dictado;
use App\Models\NotaCambio;

/**
 * Respaldo completo de un dictado en un archivo JSON (3.1.10, principio «el contenido es del
 * docente»): estructura, grupos, estudiantes, actividades, notas y bitácora de cambios.
 * Es legible por cualquier herramienta; no depende de esta aplicación para leerse.
 */
final class RespaldoDictado
{
    public const FORMATO = 'plataforma-docente-unipaz/respaldo-dictado';

    public const VERSION = 1;

    /** @return array<string, mixed> */
    public function exportar(Dictado $dictado): array
    {
        $dictado->load([
            'materia',
            'divisiones.componentes.actividades.notas',
            'divisiones.componentes.actividades.grupos',
            'grupos.estudiantes',
            'estudiantes',
        ]);

        $actividadIds = $dictado->divisiones->flatMap->componentes->flatMap->actividades->pluck('id');

        return [
            'formato' => self::FORMATO,
            'version' => self::VERSION,
            'generado_en' => now()->toIso8601String(),
            'materia' => $dictado->materia->only(['nombre', 'codigo', 'nivel']),
            'dictado' => $dictado->only(['periodo', 'escala_min', 'escala_max', 'nota_aprobatoria', 'decimales', 'redondeo', 'estado']),
            'grupos' => $dictado->grupos->map(fn ($g) => [
                'nombre' => $g->nombre,
                'estudiantes' => $g->estudiantes->map(fn ($e) => ['documento' => $e->documento, 'activo' => (bool) $e->pivot->activo])->values(),
            ])->values(),
            'estudiantes' => $dictado->estudiantes->map(fn ($e) => $e->makeVisible(['email', 'telefono', 'observacion'])->only([
                'documento', 'apellidos', 'nombres', 'numero_matriculas', 'numero_convocatorias', 'observacion', 'email', 'telefono',
            ]))->values(),
            'divisiones' => $dictado->divisiones->map(fn ($d) => [
                'nombre' => $d->nombre,
                'peso' => $d->peso,
                'componentes' => $d->componentes->map(fn ($c) => [
                    'nombre' => $c->nombre,
                    'peso' => $c->peso,
                    'actividades' => $c->actividades->map(fn ($a) => [
                        'nombre' => $a->nombre,
                        'fecha' => $a->fecha?->toDateString(),
                        'grupos' => $a->grupos->pluck('nombre')->values(),
                        'notas' => $a->notas->map(fn ($n) => [
                            'documento' => $dictado->estudiantes->firstWhere('id', $n->estudiante_id)?->documento,
                            'valor' => $n->valor,
                            'origen' => $n->origen,
                            'comentario' => $n->comentario,
                        ])->values(),
                    ])->values(),
                ])->values(),
            ])->values(),
            'bitacora' => NotaCambio::query()->whereIn('actividad_id', $actividadIds)->orderBy('id')->get()
                ->map(fn ($c) => [
                    'fecha' => $c->created_at?->toIso8601String(),
                    'actividad' => $c->actividad_id,
                    'documento' => $dictado->estudiantes->firstWhere('id', $c->estudiante_id)?->documento,
                    'anterior' => $c->valor_anterior,
                    'nuevo' => $c->valor_nuevo,
                    'origen' => $c->origen,
                    'motivo' => $c->motivo,
                ])->values(),
        ];
    }
}
