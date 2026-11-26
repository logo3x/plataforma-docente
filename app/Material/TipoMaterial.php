<?php

namespace App\Material;

use App\Models\Materia;

/**
 * Un generador de material del catálogo (7.4). Cada tipo define solo sus reglas y sus variables de
 * prompt; el flujo de borrador, corrección y aprobación es común (GeneradorMaterial) y la
 * plantilla de prompt es configuración (regla 3 del catálogo).
 */
interface TipoMaterial
{
    /** Valor en materiales.tipo, tarea de la trazabilidad y clave de la plantilla de capa 3. */
    public function tipo(): string;

    public function formato(): string;

    /** Clave de la lista de elementos en el contenido («ejemplos», «preguntas»). */
    public function coleccion(): string;

    public function normalizar(array $d): array;

    /** @return array<int, string> lo que impide guardar */
    public function errores(array $d): array;

    /** @return array<int, string> lo que conviene revisar sin impedir guardar */
    public function advertencias(array $d, Materia $materia): array;

    /** @param  array<int, string>  $temas */
    public function plantillaVacia(array $temas): array;

    /** Reglas de validación propias del formulario de generación. */
    public function reglasGeneracion(): array;

    /** Variables de la capa 3 propias del tipo (además de tema, grupo y memoria). */
    public function variables(Materia $materia, array $op): array;

    /** Cómo se nombra un elemento en la memoria de lo ya creado (7.3). */
    public function nombreElemento(array $elemento): string;
}
