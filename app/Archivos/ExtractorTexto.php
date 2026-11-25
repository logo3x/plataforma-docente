<?php

namespace App\Archivos;

use InvalidArgumentException;
use Smalot\PdfParser\Parser;
use ZipArchive;

/** Texto plano de un archivo del repositorio, para usarlo como contexto de la IA (7.4). */
final class ExtractorTexto
{
    public const EXTENSIONES = ['pdf', 'docx', 'pptx', 'txt', 'md', 'html', 'htm', 'js', 'php', 'css', 'sql', 'json', 'csv'];

    public function extraer(string $ruta, string $extension): string
    {
        $texto = match (strtolower($extension)) {
            'pdf' => (new Parser)->parseFile($ruta)->getText(),
            'docx' => $this->desdeZip($ruta, ['word/document.xml'], '</w:p>'),
            'pptx' => $this->desdeZip($ruta, $this->diapositivas($ruta), '</a:p>'),
            'html', 'htm' => html_entity_decode(strip_tags((string) preg_replace('~<(script|style)[^>]*>.*?</\1>~is', '', (string) file_get_contents($ruta)))),
            'txt', 'md', 'js', 'php', 'css', 'sql', 'json', 'csv' => (string) file_get_contents($ruta),
            default => throw new InvalidArgumentException("No se extrae texto de archivos .{$extension}."),
        };

        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }

        // Espacios repetidos fuera, pero conservando párrafos
        return trim(preg_replace(["/[ \t\x{A0}]+/u", "/\n{3,}/"], [' ', "\n\n"], $texto));
    }

    /** @param array<int, string> $partes */
    private function desdeZip(string $ruta, array $partes, string $finParrafo): string
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new InvalidArgumentException('El archivo de Office está dañado.');
        }
        $texto = '';
        foreach ($partes as $p) {
            $xml = $zip->getFromName($p);
            if ($xml !== false) {
                $texto .= html_entity_decode(strip_tags(str_replace($finParrafo, "\n", $xml)))."\n";
            }
        }
        $zip->close();

        return $texto;
    }

    /** @return array<int, string> */
    private function diapositivas(string $ruta): array
    {
        $zip = new ZipArchive;
        $zip->open($ruta);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $n, $m)) {
                $nombres[(int) $m[1]] = $n;
            }
        }
        $zip->close();
        ksort($nombres);

        return array_values($nombres);
    }
}
