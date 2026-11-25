<?php

namespace App\Notas;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Utilidades de precisión compartidas. */
final class Calculo
{
    /** Decimales del cálculo interno. Las columnas guardan 4; el cálculo trabaja con más. */
    public const ESCALA_INTERNA = 10;

    public static function normalizar(BigDecimal $valor): string
    {
        return (string) $valor->strippedOfTrailingZeros();
    }

    /** Valor con la precisión de las columnas de nota (decimal 7,4). */
    public static function paraGuardar(string $valor): string
    {
        return (string) BigDecimal::of($valor)->toScale(4, RoundingMode::HalfUp);
    }
}
