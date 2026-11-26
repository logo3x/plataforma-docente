<?php

namespace App\Dictados;

use App\Models\Actividad;
use App\Models\Dictado;
use App\Models\Division;
use App\Models\NotaCambio;
use App\Models\SegundaOportunidad;

/**
 * Respaldo completo de un dictado en un archivo JSON (3.1.10, principio «el contenido es del
 * docente»): estructura, grupos, estudiantes, actividades, notas y bitácora de cambios.
 * Es legible por cualquier herramienta; no depende de esta aplicación para leerse.
 */
final class RespaldoDictado
{
    public const FORMATO = 'plataforma-educativa/respaldo-dictado';

    public const VERSION = 2;          // 2: orden, clave de actividad (para la bitácora), valores y examen aplicado

    /** @return array<string, mixed> */
    public function exportar(Dictado $dictado): array
    {
        $dictado->load([
            'materia',
            'divisiones.componentes' => fn ($q) => $q->orderBy('orden'),
            'divisiones.componentes.actividades' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
            'divisiones.componentes.actividades.notas',
            'divisiones.componentes.actividades.grupos',
            'divisiones.componentes.actividades.artefacto',
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
                'jornada' => $g->jornada,
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
                        'clave' => $a->id,                                       // la bitácora la nombra así
                        'nombre' => $a->nombre,
                        'fecha' => $a->fecha?->toDateString(),
                        'valor_max' => $a->valor_max,
                        'es_grupal' => (bool) $a->es_grupal,
                        'tamano_grupo' => $a->tamano_grupo,
                        'tope_exceso' => $a->tope_exceso,
                        'examen' => $a->artefacto?->sigla,
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
            'segundas_oportunidades' => SegundaOportunidad::query()->whereIn('estudiante_id', $dictado->estudiantes->pluck('id'))
                ->with('ambito')->orderBy('id')->get()
                ->map(fn (SegundaOportunidad $s) => [
                    'documento' => $dictado->estudiantes->firstWhere('id', $s->estudiante_id)?->documento,
                    'tipo' => $s->tipo,
                    'sobre' => $s->ambito instanceof Actividad ? 'actividad: '.$s->ambito->nombre
                        : ($s->ambito instanceof Division ? 'corte: '.$s->ambito->nombre : 'materia'),
                    'valor' => $s->valor, 'regla' => $s->regla, 'tope' => $s->tope,
                    'fecha' => $s->fecha?->toDateString(), 'motivo' => $s->motivo,
                    'anulada_en' => $s->anulada_en?->toIso8601String(), 'motivo_anulacion' => $s->motivo_anulacion,
                ])->values(),
        ];
    }
}
