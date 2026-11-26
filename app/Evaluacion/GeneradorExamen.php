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

    /**
     * Con $ensayo, el mismo examen marcado como ensayo (5.6): verifica el equipo y su entrega no cuenta.
     * $banco elige el banco (5.4.2): el archivo lleva sus versiones (A y B, o 2A y 2B) y el navegador
     * le asigna a cada estudiante la suya según su documento.
     */
    public function html(Actividad $actividad, bool $ensayo = false, int $banco = 1): string
    {
        $artefacto = $actividad->artefacto()->with('items.pregunta')->firstOrFail();
        $versiones = $artefacto->versionesDelBanco($banco);
        if ($versiones === []) {
            throw new RuntimeException("El examen no tiene banco {$banco}.");
        }
        $dictado = $actividad->componente->division->dictado()->with('materia')->first();
        $config = ConfiguracionActividad::efectiva($artefacto);
        $valor = $artefacto->configuracion['valor'] ?? (string) $actividad->valor_max;

        $datos = [
            'sigla' => $artefacto->sigla,
            'uuid' => $artefacto->uuid,
            'titulo' => $artefacto->titulo,
            'materia' => $dictado->materia->nombre,
            'periodo' => $dictado->periodo,
            'valor' => $valor,
            'instrucciones' => $config['instrucciones'],
            'reloj_minutos' => $config['reloj_minutos'],
            'modo_desconectado' => $config['modo_desconectado'],
            'sonda' => $config['sonda'],
            'intervalo_sonda' => $config['intervalo_sonda'],
            'tiempo_ms' => $config['tiempo_ejecucion_ms'],
            'contar_salidas' => $config['contar_salidas'],
            'nivel_ayuda' => $config['nivel_ayuda'],
            'grupal' => (bool) $actividad->es_grupal,
            'ensayo' => $ensayo,
            'anexo' => $config['anexo'],
            'grupos' => $actividad->grupos()->pluck('nombre')->all() ?: $dictado->grupos()->pluck('nombre')->all(),
            'clave_publica' => $this->claves->publicaSpkiBase64($dictado),
            'banco' => $banco,
            'versiones' => $versiones,
            'items' => $artefacto->items->filter(fn ($ap) => in_array($ap->banco, $versiones, true))->map(fn ($ap) => array_merge(
                $ap->pregunta->enunciado,
                ['id' => $ap->id, 'tipo' => $ap->pregunta->tipo, 'peso' => $this->peso($ap->peso), 'version' => $ap->banco],
            ))->values()->all(),
        ];

        // JSON_HEX_TAG convierte < y > en </>: ningún texto puede cerrar la etiqueta <script> (4.5.5)
        $json = json_encode($datos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // El entorno de los retos de HTML es el mismo de la plataforma: un solo código (4.6)
        $js = file_get_contents(public_path('js/ejecutor-dom.js'))."\n".file_get_contents(public_path('js/ejecutor-sql.js'))
            ."\n".file_get_contents(resource_path('examen/examen.js'));
        // Dentro de <script>, «<!--» y «<script» cambian cómo el navegador busca el cierre (4.5.5)
        foreach (['</script' => 'una etiqueta de cierre de script', '<script' => 'una etiqueta de apertura de script', '<!--' => 'una apertura de comentario HTML'] as $sec => $que) {
            if (stripos($js, $sec) !== false) {
                throw new RuntimeException("El código del examen contiene {$que}.");
            }
        }

        return view('examen.estudiante', [
            'datos' => $datos,
            'json' => $json,
            'motorSql' => $this->motorSql($datos['items']),
            'css' => file_get_contents(resource_path('examen/examen.css')),
            'js' => $js,
        ])->render();
    }

    /**
     * El motor SQLite (sql.js) viaja dentro del archivo, en base64, solo si el examen tiene retos de SQL:
     * el examen funciona sin internet y los demás no cargan con su peso (unos 900 KB).
     */
    private function motorSql(array $items): ?string
    {
        if (! collect($items)->contains(fn ($it) => $it['tipo'] === 'reto' && ($it['lenguaje'] ?? null) === 'sql')) {
            return null;
        }

        return json_encode([
            'codigo' => base64_encode(file_get_contents(public_path('vendor/sql.js/sql-wasm.js'))),
            'wasm' => base64_encode(file_get_contents(public_path('vendor/sql.js/sql-wasm.wasm'))),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function peso(string $p): string
    {
        return (string) BigDecimal::of($p)->strippedOfTrailingZeros();
    }
}
