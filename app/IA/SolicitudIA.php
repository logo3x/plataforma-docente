<?php

namespace App\IA;

final readonly class SolicitudIA
{
    /**
     * @param  string  $sistema  capas 1 y 2 del prompt ya armadas
     * @param  string  $usuario  capa 3 más el material de contexto
     * @param  bool  $contieneDatosEstudiantes  respuestas, código o nombres de estudiantes (1.5.3)
     * @param  ?array<string, mixed>  $esquemaJson  JSON Schema para salidas estructuradas (banco, temario...)
     */
    public function __construct(
        public string $sistema,
        public string $usuario,
        public bool $contieneDatosEstudiantes = false,
        public ?array $esquemaJson = null,
        public bool $soloJson = false,       // pedir JSON en el modo nativo del motor, sin esquema estricto
        public int $maxTokens = 16000,
    ) {}
}
