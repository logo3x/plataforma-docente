<?php

namespace App\Estudiantes;

/** Una fila de la plantilla oficial ya validada (3.4). */
final class FilaLista
{
    /** @var array<int, string> */
    public array $errores = [];

    /** @var array<int, string> */
    public array $advertencias = [];

    public ?string $estado = null;   // nuevo | existente

    public function __construct(
        public int $numero,
        public string $documento,
        public string $apellidos,
        public string $nombres,
        public bool $revisarNombre,
        public ?int $matriculas,
        public ?int $convocatorias,
        public ?string $observacion,
        public ?string $email,
        public ?string $telefono,
    ) {}

    public function importable(): bool
    {
        return $this->errores === [];
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        $f = new self($a['numero'], $a['documento'], $a['apellidos'], $a['nombres'], $a['revisarNombre'],
            $a['matriculas'], $a['convocatorias'], $a['observacion'], $a['email'], $a['telefono']);
        $f->errores = $a['errores'];
        $f->advertencias = $a['advertencias'];
        $f->estado = $a['estado'];

        return $f;
    }
}
