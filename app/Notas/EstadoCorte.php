<?php

namespace App\Notas;

/** Estado de una división del periodo (3.2). */
enum EstadoCorte: string
{
    case Aprobado = 'aprobado';
    case Reprobado = 'reprobado';
    case Incompleto = 'incompleto';
    case SinActividades = 'sin_actividades';

    /** Texto con símbolo: el estado nunca se comunica solo por color (9.3). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Aprobado => '✓ Aprobado',
            self::Reprobado => '✗ Reprobado',
            self::Incompleto => '… Incompleto',
            self::SinActividades => '— Sin actividades',
        };
    }
}
