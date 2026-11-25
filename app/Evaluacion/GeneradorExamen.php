<?php

namespace App\Evaluacion;

use App\Models\Actividad;
use Brick\Math\BigDecimal;
use RuntimeException;

/**
 * Genera el examen del estudiante como un único HTML autocontenido (1.3): funciona desde el
 * archivo, sin servidor ni internet. Lleva enunciados y la clave PÚBLICA del periodo; nunca
 * claves de respuesta, pesos internos de verificaciones ni casos reservados (5.4.5, 9.1).
 */
final class GeneradorExamen
{
    public function __construct(private readonly ClavesDictado $claves) {}

    public function html(Actividad $actividad): string
    {
        $artefacto = $actividad->artefacto()->with('items.pregunta')->firstOrFail();
        $dictado = $actividad->componente->division->dictado()->with('materia')->first();
        $config = $artefacto->configuracion;

        $datos = [
            'sigla' => $artefacto->sigla,
            'uuid' => $artefacto->uuid,
            'titulo' => $artefacto->titulo,
            'materia' => $dictado->materia->nombre,
            'periodo' => $dictado->periodo,
            'valor' => $config['valor'] ?? (string) $actividad->valor_max,
            'instrucciones' => $config['instrucciones'] ?? null,
            'reloj_minutos' => $config['reloj_minutos'] ?? null,
            'modo_desconectado' => (bool) ($config['modo_desconectado'] ?? false),
            'sonda' => $config['sonda'] ?? 'https://www.google.com/generate_204',
            'intervalo_sonda' => $config['intervalo_sonda'] ?? 20,
            'tiempo_ms' => (int) ($config['tiempo_ejecucion_ms'] ?? 2000),
            'grupal' => (bool) $actividad->es_grupal,
            'anexo' => (bool) ($config['anexo'] ?? false),
            'grupos' => $actividad->grupos()->pluck('nombre')->all() ?: $dictado->grupos()->pluck('nombre')->all(),
            'clave_publica' => $this->claves->publicaSpkiBase64($dictado),
            'items' => $artefacto->items->map(fn ($ap) => array_merge(
                $ap->pregunta->enunciado,
                ['id' => $ap->id, 'tipo' => $ap->pregunta->tipo, 'peso' => $this->peso($ap->peso)],
            ))->values()->all(),
        ];

        // JSON_HEX_TAG convierte < y > en </>: ningún texto puede cerrar la etiqueta <script> (4.5.5)
        $json = json_encode($datos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $js = file_get_contents(resource_path('examen/examen.js'));
        if (stripos($js, '</script') !== false) {
            throw new RuntimeException('El código del examen contiene una etiqueta de cierre de script.');
        }

        return view('examen.estudiante', [
            'datos' => $datos,
            'json' => $json,
            'css' => file_get_contents(resource_path('examen/examen.css')),
            'js' => $js,
        ])->render();
    }

    private function peso(string $p): string
    {
        return (string) BigDecimal::of($p)->strippedOfTrailingZeros();
    }
}
