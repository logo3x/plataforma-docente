<?php

namespace App\Jobs;

use App\Calificacion\EnvioInformes;
use App\Models\Devolucion;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envío en segundo plano del enlace al informe (con CORREO_EN_COLA=true). */
class EnviarEnlaceInforme implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $devolucionId, public int $docenteId, public ?string $mensaje) {}

    public function handle(EnvioInformes $envio): void
    {
        $d = Devolucion::query()->find($this->devolucionId);
        $docente = User::query()->find($this->docenteId);
        // Si entre tanto se retiró la devolución, no se envía
        if ($d && $docente && $d->revocada_en === null && $d->actividad->devolucion_liberada_en !== null) {
            $envio->enviarAhora($d, $docente, $this->mensaje);
        } elseif ($d) {
            $d->update(['correo_en_cola_en' => null]);
        }
    }
}
