<?php

namespace App\Notas;

/** Estado de una división del periodo (3.2). */
enum EstadoCorte: string
{
    case Aprobado = 'aprobado';
    case Reprobado = 'reprobado';
    case Incompleto = 'incompleto';
    case SinActividades = 'sin_actividades';
}
