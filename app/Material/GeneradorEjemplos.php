<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador «Ejemplos prácticos» (7.4): el flujo común con las reglas de los ejemplos resueltos. */
final class GeneradorEjemplos extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, EjemplosPracticos $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
