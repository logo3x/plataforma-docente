<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador «Presentación» (7.2, 7.4): el flujo común con las reglas de las diapositivas de clase. */
final class GeneradorPresentacion extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, Presentacion $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
