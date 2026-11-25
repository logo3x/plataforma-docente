<?php

namespace App\Calificacion;

final readonly class ResultadoItem
{
    /**
     * @param  ?string  $puntaje  null = no lo califica el servidor (abierta → docente, reto → navegador)
     * @param  array<string, mixed>  $desglose  detalle autoexplicativo del ítem (6.4)
     */
    public function __construct(
        public ?string $puntaje,
        public string $puntajeMax,
        public array $desglose = [],
        public ?string $pendienteDe = null,
    ) {}

    public static function pendiente(string $max, string $de): self
    {
        return new self(null, $max, [], $de);
    }
}
