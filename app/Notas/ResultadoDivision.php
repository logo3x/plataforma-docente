<?php

namespace App\Notas;

final readonly class ResultadoDivision
{
    /**
     * @param  ?string  $definitiva  definitiva con precisión completa; null si no hay nada que promediar
     * @param  array<int|string, ?string>  $promedios  promedio por componente (null = sin notas)
     */
    public function __construct(
        public EstadoCorte $estado,
        public ?string $definitiva,
        public array $promedios = [],
    ) {}

    public function esFinal(): bool
    {
        return in_array($this->estado, [EstadoCorte::Aprobado, EstadoCorte::Reprobado], true);
    }
}
