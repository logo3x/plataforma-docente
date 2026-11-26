<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador «Lista de vocabulario» (7.4): el flujo común con las reglas del vocabulario. */
final class GeneradorVocabulario extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, ListaVocabulario $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
