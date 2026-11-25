<?php

namespace App\IA;

final readonly class RespuestaIA
{
    public function __construct(
        public string $texto,
        public ?int $tokensEntrada = null,
        public ?int $tokensSalida = null,
        public int $duracionMs = 0,
    ) {}

    /** @return array<string, mixed> */
    public function json(): array
    {
        return json_decode($this->texto, true, flags: JSON_THROW_ON_ERROR);
    }
}
