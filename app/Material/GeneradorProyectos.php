<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador «Ideas de proyectos» (7.4): el flujo común con las reglas de los proyectos. */
final class GeneradorProyectos extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, IdeasProyecto $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
