<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\NotaCambio;
use App\Notas\RegistroNotas;

/**
 * Lleva las notas completas del calificador al registro, sin transcripción (6.6). Se usa al pulsar
 * «Pasar notas al registro» y al reprocesar el grupo después de anular un ítem (6.5.4).
 */
final class PasoAlRegistro
{
    public function __construct(
        private readonly CalificadorEntrega $calificador,
        private readonly AsignacionGrupal $asignacion,
        private readonly RegistroNotas $registro,
    ) {}

    /**
     * @param  bool  $soloYaRegistradas  al reprocesar, solo actualiza entregas que ya habían pasado
     * @return array{operacion: string, pasadas: int, pendientes: int}
     */
    public function ejecutar(Actividad $actividad, ?string $motivo = null, bool $soloYaRegistradas = false): array
    {
        $pasadas = 0;
        $pendientes = 0;

        $op = $this->registro->operacion(function () use ($actividad, $motivo, $soloYaRegistradas, &$pasadas, &$pendientes) {
            foreach ($actividad->entregas()->get() as $e) {
                if ($soloYaRegistradas && ! $actividad->notas()->where('entrega_id', $e->id)->exists()) {
                    continue;
                }
                $nota = $this->calificador->nota($e);
                if ($nota === null) {
                    $pendientes++;

                    continue;
                }
                $this->asignacion->asignar($e, $nota, motivoExtra: $motivo);
                $pasadas++;
            }
        });

        return [
            'operacion' => $op, 'pasadas' => $pasadas, 'pendientes' => $pendientes,
            // Notas que de verdad cambiaron: las que dejaron entrada en la bitácora
            'cambiadas' => NotaCambio::query()->where('operacion_id', $op)->count(),
        ];
    }
}
