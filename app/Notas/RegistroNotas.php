<?php

namespace App\Notas;

use App\Models\Actividad;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\NotaCambio;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Única puerta de escritura de notas. Toda creación, edición o borrado deja una entrada
 * en la bitácora (3.5), agrupada por operación para poder deshacer por operación (3.1.9).
 */
final class RegistroNotas
{
    private ?string $operacionActual = null;

    /**
     * Ejecuta varias asignaciones como una sola operación (un solo «deshacer»).
     *
     * @return string identificador de la operación
     */
    public function operacion(Closure $trabajo): string
    {
        if ($this->operacionActual !== null) {
            $trabajo();

            return $this->operacionActual;
        }

        $this->operacionActual = (string) Str::uuid();

        try {
            DB::transaction($trabajo);

            return $this->operacionActual;
        } finally {
            $this->operacionActual = null;
        }
    }

    /**
     * Asigna (o vacía, con null) la nota de un estudiante en una actividad.
     *
     * @param  string  $origen  digitada | archivo | calificador | grupal | migracion | ajuste_grupal | ...
     */
    public function asignar(
        Actividad $actividad,
        Estudiante $estudiante,
        ?string $valor,
        string $origen,
        ?string $motivo = null,
        ?int $entregaId = null,
        bool $ajusteIndividual = false,
        ?string $comentario = null,
    ): Nota {
        $escala = $actividad->componente->division->dictado->escala();

        if ($estudiante->dictado_id !== $actividad->componente->division->dictado_id) {
            throw new InvalidArgumentException('El estudiante no pertenece al dictado de la actividad.');
        }

        if ($valor !== null) {
            if (! $escala->contiene($valor)) {
                throw new InvalidArgumentException(
                    "La nota {$valor} está fuera de la escala {$escala->mostrar($escala->minimo)} a {$escala->mostrar($escala->maximo)}."
                );
            }
            $valor = Calculo::paraGuardar($valor);
        }

        $nota = null;

        $this->operacion(function () use ($actividad, $estudiante, $valor, $origen, $motivo, $entregaId, $ajusteIndividual, $comentario, &$nota) {
            $nota = Nota::query()->firstOrNew([
                'actividad_id' => $actividad->id,
                'estudiante_id' => $estudiante->id,
            ]);

            $anterior = $nota->exists ? $nota->valor : null;
            $mismoValor = $nota->exists && $this->iguales($anterior, $valor);

            // Origen de la nota: el del cambio, salvo los que son solo trazas de bitácora
            $origenNota = in_array($origen, ['digitada', 'archivo', 'calificador', 'grupal', 'migracion', 'traslado'], true)
                ? $origen
                : ($nota->origen ?? 'digitada');

            // 6.8.7: corregir a mano una nota que vino de una entrega es un ajuste individual; el próximo
            // «Pasar al registro» lo conserva en vez de pisarlo con la nota de la entrega
            $ajuste = $ajusteIndividual || ($origen === 'digitada' && $nota->exists && $nota->entrega_id !== null && ! $mismoValor)
                || ($origen === 'digitada' && $mismoValor && $nota->ajuste_individual);

            $nota->fill([
                'valor' => $valor,
                'origen' => $origenNota,
                'entrega_id' => $entregaId ?? $nota->entrega_id,
                'ajuste_individual' => $ajuste,
                'comentario' => $comentario ?? $nota->comentario,
            ])->save();

            if (! $mismoValor) {
                $this->registrarCambio($nota, $anterior, $valor, $origen, $motivo);
            }
        });

        return $nota;
    }

    /**
     * Convierte las celdas vacías de una actividad en ceros (3.1.6): acción explícita y reversible.
     *
     * @return string operación, para deshacerla
     */
    public function vaciasACeros(Actividad $actividad, string $motivo): string
    {
        return $this->operacion(function () use ($actividad, $motivo) {
            $escala = $actividad->componente->division->dictado->escala();
            $estudiantes = $actividad->componente->division->dictado->estudiantes()->get();
            $conValor = $actividad->notas()->whereNotNull('valor')->pluck('estudiante_id')->all();

            foreach ($estudiantes as $e) {
                if (! in_array($e->id, $conValor, true)) {
                    $this->asignar($actividad, $e, $escala->minimo, 'ceros_explicitos', $motivo);
                }
            }
        });
    }

    /**
     * Deshace una operación. No borra la bitácora: agrega una operación inversa (3.5.2).
     *
     * @return string la nueva operación (que a su vez se puede deshacer)
     */
    public function deshacer(string $operacionId, ?string $motivo = null): string
    {
        $cambios = NotaCambio::query()->where('operacion_id', $operacionId)->orderByDesc('id')->get();

        if ($cambios->isEmpty()) {
            throw new InvalidArgumentException('La operación no existe.');
        }

        return $this->operacion(function () use ($cambios, $operacionId, $motivo) {
            foreach ($cambios as $c) {
                $nota = Nota::query()->find($c->nota_id);
                if ($nota === null) {
                    continue; // la actividad se eliminó después; la bitácora queda como evidencia
                }
                $anterior = $nota->valor;
                $nota->update(['valor' => $c->valor_anterior]);
                $this->registrarCambio($nota, $anterior, $c->valor_anterior, 'deshacer', $motivo ?? "Deshace {$operacionId}");
            }
        });
    }

    /**
     * Elimina una actividad y sus notas. Exige confirmar el número de notas afectadas (9.1).
     * La bitácora conserva una entrada por cada nota eliminada.
     */
    public function eliminarActividad(Actividad $actividad, int $notasConfirmadas, string $motivo): void
    {
        $total = $actividad->notas()->count();

        if ($total !== $notasConfirmadas) {
            throw new InvalidArgumentException(
                "La actividad tiene {$total} notas; confirme ese número para eliminarla."
            );
        }

        $this->operacion(function () use ($actividad, $motivo) {
            foreach ($actividad->notas()->get() as $nota) {
                $this->registrarCambio($nota, $nota->valor, null, 'eliminacion', $motivo);
            }
            $actividad->delete();
        });
    }

    /**
     * Deja en el historial de la nota un evento que no cambia su valor original, como un supletorio
     * o una recuperación (3.6): la nota original se conserva y el evento queda visible en la auditoría.
     */
    public function registrarEvento(Actividad $actividad, Estudiante $estudiante, string $origen, ?string $valorEvento, string $motivo): void
    {
        $this->operacion(function () use ($actividad, $estudiante, $origen, $valorEvento, $motivo) {
            $nota = Nota::query()->firstOrCreate(
                ['actividad_id' => $actividad->id, 'estudiante_id' => $estudiante->id],
                ['valor' => null, 'origen' => 'digitada'],
            );
            $this->registrarCambio($nota, $nota->valor, $valorEvento, $origen, $motivo);
        });
    }

    private function registrarCambio(Nota $nota, ?string $anterior, ?string $nuevo, string $origen, ?string $motivo): void
    {
        NotaCambio::query()->create([
            'nota_id' => $nota->id,
            'actividad_id' => $nota->actividad_id,
            'estudiante_id' => $nota->estudiante_id,
            'user_id' => auth()->id(),
            'valor_anterior' => $anterior,
            'valor_nuevo' => $nuevo,
            'origen' => $origen,
            'motivo' => $motivo,
            'operacion_id' => $this->operacionActual,
        ]);
    }

    private function iguales(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return BigDecimal::of($a)->isEqualTo($b);
    }
}
