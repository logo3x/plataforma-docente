<?php

namespace App\Notas;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Escala de notas del dictado: rango, nota aprobatoria y redondeo (3.2).
 * El redondeo solo se aplica a lo que se muestra; el cálculo interno conserva la precisión.
 */
final readonly class Escala
{
    public function __construct(
        public string $minimo = '0',
        public string $maximo = '5',
        public string $aprobatoria = '3',
        public int $decimales = 1,
        public string $redondeo = 'cercano',
    ) {
        if (BigDecimal::of($minimo)->isGreaterThanOrEqualTo($maximo)) {
            throw new InvalidArgumentException('El mínimo de la escala debe ser menor que el máximo.');
        }
        if (! $this->contiene($aprobatoria)) {
            throw new InvalidArgumentException('La nota aprobatoria debe estar dentro de la escala.');
        }
        if (! in_array($redondeo, ['cercano', 'arriba', 'abajo'], true)) {
            throw new InvalidArgumentException("Regla de redondeo desconocida: {$redondeo}.");
        }
    }

    public static function porDefecto(): self
    {
        return new self('0', '5', '3', 1, 'cercano');
    }

    public function contiene(string $valor): bool
    {
        $v = BigDecimal::of($valor);

        return $v->isGreaterThanOrEqualTo($this->minimo) && $v->isLessThanOrEqualTo($this->maximo);
    }

    /** Nota tal como se muestra y reporta. */
    public function mostrar(string $valor): string
    {
        $modo = match ($this->redondeo) {
            'arriba' => RoundingMode::Ceiling,
            'abajo' => RoundingMode::Floor,
            default => RoundingMode::HalfUp,
        };

        return (string) BigDecimal::of($valor)->toScale($this->decimales, $modo);
    }

    /** Meta mínima a mostrar: siempre hacia arriba, para que sacar la nota mostrada sí alcance. */
    public function meta(string $valor): string
    {
        return (string) BigDecimal::of($valor)->toScale($this->decimales, RoundingMode::Ceiling);
    }

    /** Valor para un campo editable: sin ceros a la derecha ("3.5000" → "3.5"). */
    public function editable(?string $valor): string
    {
        return $valor === null ? '' : (string) BigDecimal::of($valor)->strippedOfTrailingZeros();
    }

    /** Aprueba con el valor interno, sin redondeo. */
    public function aprueba(string $valor): bool
    {
        return BigDecimal::of($valor)->isGreaterThanOrEqualTo($this->aprobatoria);
    }

    /** Aprueba solo porque la nota mostrada se redondeó hasta la aprobatoria (3.7.2). */
    public function apruebaSoloPorRedondeo(string $valor): bool
    {
        return ! $this->aprueba($valor)
            && BigDecimal::of($this->mostrar($valor))->isGreaterThanOrEqualTo($this->aprobatoria);
    }

    /**
     * Convierte una nota de otra escala (0–10, 0–50, 0–100...) a esta (3.1.5).
     * Se asume que la escala de origen empieza en 0.
     */
    public function convertirDesde(string $valor, string $maximoOrigen): string
    {
        $fraccion = BigDecimal::of($valor)->dividedBy($maximoOrigen, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven);
        $rango = BigDecimal::of($this->maximo)->minus($this->minimo);

        return Calculo::normalizar($fraccion->multipliedBy($rango)->plus($this->minimo));
    }
}
