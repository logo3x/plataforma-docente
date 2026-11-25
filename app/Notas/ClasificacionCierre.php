<?php

namespace App\Notas;

/** Clasificación del simulador de cierre (3.7.2). */
enum ClasificacionCierre: string
{
    case YaAprueba = 'ya_aprueba';
    case ApruebaPorRedondeo = 'aprueba_por_redondeo';
    case DependeDeLoPendiente = 'depende_de_lo_pendiente';
    case NoAlcanza = 'no_alcanza';
}
