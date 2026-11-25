<?php

namespace App\Notas;

use App\Models\SegundaOportunidad;

final readonly class ResultadoDivision
{
    /**
     * @param  ?string  $definitiva  definitiva con precisión completa; null si no hay nada que promediar
     * @param  array<int|string, ?string>  $promedios  promedio por componente (null = sin notas)
     * @param  ?SegundaOportunidad  $segunda  habilitación aplicada a esta división (3.6)
     * @param  ?string  $original  definitiva antes de la habilitación; se conserva
     */
    public function __construct(
        public EstadoCorte $estado,
        public ?string $definitiva,
        public array $promedios = [],
        public ?SegundaOportunidad $segunda = null,
        public ?string $original = null,
    ) {}

    public function esFinal(): bool
    {
        return in_array($this->estado, [EstadoCorte::Aprobado, EstadoCorte::Reprobado], true);
    }
}
