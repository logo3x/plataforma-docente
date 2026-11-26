<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador «Preguntas de debate» (7.4): el flujo común con las reglas del debate en clase. */
final class GeneradorDebate extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, PreguntasDebate $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
