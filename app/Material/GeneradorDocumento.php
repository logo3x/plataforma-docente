<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\GestorIA;

/** Generador de documentos configurable (7.4.3): el flujo común con las reglas del documento por secciones. */
final class GeneradorDocumento extends GeneradorMaterial
{
    public function __construct(ConstructorPrompt $prompts, GestorIA $ia, DocumentoGenerado $tipo)
    {
        parent::__construct($prompts, $ia, $tipo);
    }
}
