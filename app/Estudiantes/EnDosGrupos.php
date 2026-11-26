<?php

namespace App\Estudiantes;

use App\Models\Actividad;
use App\Models\Dictado;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Nota;
use App\Notas\RegistroNotas;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Estudiantes que aparecen en dos grupos del mismo periodo (3.1.12, decisión pendiente 5). La secretaría a
 * veces los matricula en dos jornadas, o presentan un parcial en la otra. El docente decide en qué grupo
 * queda: en los demás se marca retirado (sin borrar nada, 3.4.6) y las notas de actividades propias del otro
 * grupo pasan a la actividad equivalente del grupo elegido, con constancia en la bitácora. Nada se
 * sobrescribe: un conflicto o una actividad sin equivalente se reporta para resolverlo a mano.
 */
final class EnDosGrupos
{
    public function __construct(private readonly RegistroNotas $registro) {}

    /**
     * Estudiantes activos en más de un grupo del dictado y sin revisar, con lo que tienen en cada grupo.
     *
     * @return Collection<int, array{estudiante: Estudiante, grupos: Collection, sugerido: ?Grupo, planes: array<int, array>}>
     */
    public function casos(Dictado $dictado): Collection
    {
        $estudiantes = Estudiante::query()->where('dictado_id', $dictado->id)
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true), '>=', 2)
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true)->whereNull('grupo_estudiante.doble_revisado_en'))
            ->with(['grupos' => fn ($q) => $q->where('grupo_estudiante.activo', true)->orderBy('nombre')])
            ->orderBy('apellidos')->get();

        return $estudiantes->map(function (Estudiante $e) {
            $grupos = $e->grupos->map(fn (Grupo $g) => ['grupo' => $g] + $this->evidencia($e, $g));
            $puntaje = fn ($x) => $x['notas']->count() + $x['entregas'];
            $max = $grupos->max($puntaje);
            $mejores = $grupos->filter(fn ($x) => $puntaje($x) === $max);

            return [
                'estudiante' => $e,
                'grupos' => $grupos,
                'sugerido' => $max > 0 && $mejores->count() === 1 ? $mejores->first()['grupo'] : null,
                'planes' => $e->grupos->mapWithKeys(fn (Grupo $g) => [$g->id => $this->plan($e, $g)])->all(),
            ];
        });
    }

    /** Cuántos casos hay en el periodo, para el aviso de la página del dictado. */
    public function cuantosEnDictado(Dictado $dictado): int
    {
        return Estudiante::query()->where('dictado_id', $dictado->id)
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true), '>=', 2)
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true)->whereNull('grupo_estudiante.doble_revisado_en'))->count();
    }

    /** Cuántos casos tocan a un grupo, para el aviso del registro. */
    public function cuantos(Grupo $grupo): int
    {
        // Basta una inscripción activa sin revisar: si lo agregan a un tercer grupo, vuelve a avisar
        return $grupo->estudiantes()->wherePivot('activo', true)
            ->whereHas('grupos', fn ($q) => $q->where('grupos.id', '!=', $grupo->id)->where('grupo_estudiante.activo', true))
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true)->whereNull('grupo_estudiante.doble_revisado_en'))
            ->count();
    }

    /** Lo que el estudiante tiene en un grupo: notas en actividades propias de ese grupo y entregas. */
    private function evidencia(Estudiante $e, Grupo $g): array
    {
        $propias = Actividad::query()->whereHas('grupos', fn ($q) => $q->whereKey($g->id))->pluck('id');
        $notas = Nota::query()->where('estudiante_id', $e->id)->whereIn('actividad_id', $propias)->whereNotNull('valor')->with('actividad')->get();
        $entregas = Entrega::query()->whereIn('actividad_id', $propias)->whereHas('integrantes', fn ($q) => $q->whereKey($e->id))->get(['id', 'created_at']);

        return ['notas' => $notas, 'entregas' => $entregas->count(),
            'ultima' => collect([$notas->max('updated_at'), $entregas->max('created_at')])->filter()->max()];
    }

    /**
     * Qué pasaría si queda en $destino: por cada nota en actividades propias de los otros grupos, su
     * actividad equivalente en el destino (mismo examen o mismo nombre en el mismo componente).
     *
     * @return array{trasladar: array, conflictos: array, sin_equivalente: array, retirar: Collection}
     */
    public function plan(Estudiante $e, Grupo $destino): array
    {
        $otros = $e->grupos()->wherePivot('activo', true)->where('grupos.id', '!=', $destino->id)->get();
        $plan = ['trasladar' => [], 'conflictos' => [], 'sin_equivalente' => [], 'retirar' => $otros];
        $delDestino = Actividad::query()->whereHas('componente.division', fn ($q) => $q->where('dictado_id', $destino->dictado_id))
            ->where(fn ($q) => $q->whereDoesntHave('grupos')->orWhereHas('grupos', fn ($g) => $g->whereKey($destino->id)))->get();

        foreach ($otros as $g) {
            $notas = Nota::query()->where('estudiante_id', $e->id)->whereNotNull('valor')
                ->whereHas('actividad.grupos', fn ($q) => $q->whereKey($g->id))
                ->whereDoesntHave('actividad.grupos', fn ($q) => $q->whereKey($destino->id))->with('actividad')->get();
            foreach ($notas as $n) {
                $eq = $this->equivalente($n->actividad, $delDestino);
                $fila = ['nota' => $n, 'origen' => $n->actividad, 'grupo' => $g, 'destino' => $eq];
                if ($eq === null) {
                    $plan['sin_equivalente'][] = $fila;
                } elseif (($ya = Nota::query()->where('actividad_id', $eq->id)->where('estudiante_id', $e->id)->value('valor')) !== null
                    && ! BigDecimal::of((string) $ya)->isEqualTo((string) $n->valor)) {
                    $plan['conflictos'][] = $fila + ['ya' => $ya];
                } else {
                    $plan['trasladar'][] = $fila;
                }
            }
        }

        return $plan;
    }

    /** @return array{trasladadas: int, conflictos: int, sin_equivalente: int, retirado_de: array<int, string>} */
    public function resolver(Estudiante $e, Grupo $destino): array
    {
        if (! $e->grupos()->wherePivot('activo', true)->whereKey($destino->id)->exists()) {
            throw new InvalidArgumentException('El estudiante no está activo en ese grupo.');
        }
        $plan = $this->plan($e, $destino);

        DB::transaction(function () use ($e, $destino, $plan) {
            $this->registro->operacion(function () use ($e, $destino, $plan) {
                foreach ($plan['trasladar'] as $t) {
                    $this->registro->asignar($t['destino'], $e, (string) $t['nota']->valor, 'traslado',
                        "Viene de «{$t['origen']->nombre}» ({$t['grupo']->nombre}): el estudiante queda en {$destino->nombre}.");
                }
            });
            foreach ($plan['retirar'] as $g) {
                $g->estudiantes()->updateExistingPivot($e->id, ['activo' => false]);
            }
            $destino->estudiantes()->updateExistingPivot($e->id, ['doble_revisado_en' => now()]);
        });

        return ['trasladadas' => count($plan['trasladar']), 'conflictos' => count($plan['conflictos']), 'sin_equivalente' => count($plan['sin_equivalente']),
            'retirado_de' => $plan['retirar']->pluck('nombre')->all()];
    }

    /** Sigue en todos sus grupos, a sabiendas: no se vuelve a avisar. */
    public function mantener(Estudiante $e): void
    {
        DB::table('grupo_estudiante')->where('estudiante_id', $e->id)->where('activo', true)->update(['doble_revisado_en' => now()]);
    }

    private function equivalente(Actividad $a, Collection $candidatas): ?Actividad
    {
        $mismoComponente = $candidatas->where('componente_id', $a->componente_id);
        if ($a->artefacto_id && ($x = $mismoComponente->firstWhere('artefacto_id', $a->artefacto_id))) {
            return $x;
        }
        $norm = fn (string $s) => Str::of(Str::ascii($s))->lower()->replaceMatches('/\((diurno|nocturno|manana|tarde|noche)\)|\b(diurno|nocturno|manana|tarde|noche)\b/', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
        $coinciden = $mismoComponente->filter(fn ($c) => $norm($c->nombre) === $norm($a->nombre));

        return $coinciden->count() === 1 ? $coinciden->first() : null;
    }
}
