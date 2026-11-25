<?php

namespace App\IA;

use InvalidArgumentException;

/**
 * Extrae el objeto JSON de la respuesta de un modelo: tolera bloques ```json, texto antes o
 * después y comillas tipográficas en las claves. Nunca «adivina» contenido: si no hay un JSON
 * válido, lo dice.
 */
final class ExtractorJson
{
    /** @return array<string, mixed> */
    public static function extraer(string $texto): array
    {
        $t = trim($texto);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $t, $m)) {
            $t = $m[1];
        }
        $inicio = strpos($t, '{');
        $fin = strrpos($t, '}');
        if ($inicio === false || $fin === false || $fin < $inicio) {
            throw new InvalidArgumentException('La respuesta del modelo no contiene un objeto JSON.');
        }
        $candidato = substr($t, $inicio, $fin - $inicio + 1);

        $datos = json_decode($candidato, true);
        if (! is_array($datos)) {
            $datos = json_decode(strtr($candidato, ['“' => '"', '”' => '"']), true);
        }
        if (! is_array($datos)) {
            throw new InvalidArgumentException('El JSON de la respuesta está mal formado: '.json_last_error_msg().'.');
        }

        return $datos;
    }
}
