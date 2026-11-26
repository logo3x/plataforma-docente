<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador «Texto informativo / apuntes» (7.4): el flujo común con las reglas del apunte. */
final class GeneradorTexto extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, TextoInformativo $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
