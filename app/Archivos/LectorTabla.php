<?php

namespace App\Archivos;

use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as OpcionesCsv;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;

/**
 * Lee la primera hoja de un XLSX o un CSV como encabezados + filas de texto.
 * Detecta el separador (`,` o `;`) y la codificación (UTF-8 o Windows-1252) de los CSV
 * que exporta Excel con configuración regional en español.
 */
final class LectorTabla
{
    /**
     * @return array{encabezados: array<int, string>, filas: array<int, array<int, string>>}
     */
    public function leer(string $ruta, string $extension): array
    {
        $lector = match (strtolower($extension)) {
            'xlsx' => new LectorXlsx,
            'csv', 'txt' => new LectorCsv($this->opcionesCsv($ruta)),
            default => throw new InvalidArgumentException('Formato no admitido. Use un archivo .xlsx o .csv.'),
        };

        $lector->open($ruta);
        $encabezados = null;
        $filas = [];

        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $fila) {
                    $celdas = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $fila->toArray());

                    if ($encabezados === null) {
                        $encabezados = array_map(fn ($h) => trim($h, " \t\n\r\0\x0B\u{FEFF}"), $celdas);

                        continue;
                    }
                    if (implode('', $celdas) === '') {
                        continue;
                    }
                    $filas[] = array_pad($celdas, count($encabezados), '');
                }
                break; // solo la primera hoja
            }
        } finally {
            $lector->close();
        }

        if ($encabezados === null) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }

        return ['encabezados' => $encabezados, 'filas' => $filas];
    }

    /** "Número de Documento" → "numero_de_documento" */
    public static function normalizarEncabezado(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $h), '_');
    }

    private function opcionesCsv(string $ruta): OpcionesCsv
    {
        $muestra = (string) file_get_contents($ruta, length: 65536);
        $primera = strtok($muestra, "\n") ?: '';

        return new OpcionesCsv(
            FIELD_DELIMITER: substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',',
            ENCODING: mb_check_encoding($muestra, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        );
    }
}
