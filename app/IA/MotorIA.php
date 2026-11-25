<?php

namespace App\IA;

/**
 * Interfaz común de los motores de IA (1.5.2): cambiar de local a nube, o de un modelo a
 * otro, no obliga a rehacer las plantillas de prompt.
 */
interface MotorIA
{
    /** @throws MotorNoDisponible|RespuestaRechazada */
    public function generar(SolicitudIA $solicitud): RespuestaIA;

    /** Comprobación corta para avisar antes de generar (1.5.4). */
    public function disponible(): bool;

    public function esLocal(): bool;
}
