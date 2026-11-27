<?php

/*
 * Publicación institucional del material (7.8, decisión pendiente 2).
 *
 * «campos» es la ficha de metadatos del recurso. Mientras la institución no tenga una plantilla propia, se usa
 * esta, basada en Dublin Core y en el núcleo de LOM (IEEE 1484.12.1), que entienden los repositorios y las
 * plataformas con SCORM. Si la institución adopta otra, se reemplaza esta lista: cada campo con su etiqueta,
 * si es obligatorio, su tipo (texto, area o lista) y, si se quiere, a qué elemento de LOM o Dublin Core va.
 */
return [
    'institucion' => env('PUBLICACION_INSTITUCION') ?: env('INSTITUCION', ''),
    'licencia' => env('PUBLICACION_LICENCIA', 'CC BY-NC-SA 4.0'),

    'campos' => [
        'titulo' => ['etiqueta' => 'Título', 'obligatorio' => true, 'tipo' => 'texto', 'lom' => 'general.title', 'dc' => 'title'],
        'descripcion' => ['etiqueta' => 'Descripción', 'obligatorio' => true, 'tipo' => 'area', 'lom' => 'general.description', 'dc' => 'description'],
        'autor' => ['etiqueta' => 'Autor', 'obligatorio' => true, 'tipo' => 'texto', 'lom' => 'lifeCycle.contribute.author', 'dc' => 'creator'],
        'institucion' => ['etiqueta' => 'Institución', 'obligatorio' => true, 'tipo' => 'texto', 'lom' => 'lifeCycle.contribute.publisher', 'dc' => 'publisher'],
        'programa' => ['etiqueta' => 'Programa académico', 'obligatorio' => false, 'tipo' => 'texto'],
        'materia' => ['etiqueta' => 'Asignatura', 'obligatorio' => true, 'tipo' => 'texto', 'dc' => 'subject'],
        'palabras_clave' => ['etiqueta' => 'Palabras clave (separadas por coma)', 'obligatorio' => true, 'tipo' => 'texto', 'lom' => 'general.keyword', 'dc' => 'subject'],
        'nivel' => ['etiqueta' => 'Nivel educativo', 'obligatorio' => false, 'tipo' => 'lista', 'opciones' => ['Pregrado', 'Tecnología', 'Técnico profesional', 'Posgrado', 'Educación media'], 'lom' => 'educational.context'],
        'resultados' => ['etiqueta' => 'Resultados de aprendizaje', 'obligatorio' => false, 'tipo' => 'area', 'lom' => 'educational.description'],
        'tiempo' => ['etiqueta' => 'Tiempo de estudio estimado (minutos)', 'obligatorio' => false, 'tipo' => 'texto', 'lom' => 'educational.typicalLearningTime'],
        'idioma' => ['etiqueta' => 'Idioma', 'obligatorio' => true, 'tipo' => 'texto', 'lom' => 'general.language', 'dc' => 'language'],
        'licencia' => ['etiqueta' => 'Licencia', 'obligatorio' => true, 'tipo' => 'texto', 'lom' => 'rights.description', 'dc' => 'rights'],
        'version' => ['etiqueta' => 'Versión', 'obligatorio' => false, 'tipo' => 'texto', 'lom' => 'lifeCycle.version'],
    ],
];
