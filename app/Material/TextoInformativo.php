<?php

namespace App\Material;

use App\Models\Contenido;
use App\Models\Materia;
use App\Models\Material;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reglas de un texto informativo o apunte (7.4, «Material»): una lectura explicativa de un tema,
 * por secciones, con las ideas clave, preguntas de comprensión y las fuentes del material usado.
 * Formaliza lo que ya hace el repositorio (módulo 7): el apunte aprobado puede guardarse ahí y
 * servir de material de referencia para las siguientes generaciones.
 */
final class TextoInformativo implements TipoMaterial
{
    public const TIPO = 'apuntes';

    public const FORMATO = 'plataforma-apunte';

    public const MAX_SECCIONES = 20;

    /** Palabras por minuto de lectura de un texto académico. */
    public const PALABRAS_MINUTO = 180;

    public const NIVELES = ['introductorio' => 'Introductorio', 'intermedio' => 'Intermedio', 'avanzado' => 'Avanzado'];

    public function tipo(): string
    {
        return self::TIPO;
    }

    public function formato(): string
    {
        return self::FORMATO;
    }

    public function coleccion(): string
    {
        return 'secciones';
    }

    public function nombreElemento(array $elemento): string
    {
        return (string) ($elemento['titulo'] ?? '');
    }

    public function reglasGeneracion(): array
    {
        return [
            'extension' => ['required', 'in:corta,media,larga'],
            'nivel' => ['required', 'in:'.implode(',', array_keys(self::NIVELES))],
            'enfoque' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function variables(Materia $materia, array $op): array
    {
        return [
            'extension' => ['corta' => 'corta: unas 600 palabras, 3 o 4 secciones', 'media' => 'media: unas 1.200 palabras, 4 a 6 secciones',
                'larga' => 'larga: unas 2.000 palabras, 6 a 8 secciones'][$op['extension'] ?? 'media'] ?? 'media: unas 1.200 palabras, 4 a 6 secciones',
            'nivel' => $op['nivel'] ?? 'introductorio',
            'enfoque' => filled($op['enfoque'] ?? null) ? trim($op['enfoque']) : 'explicar con ejemplos del entorno profesional del programa',
            'glosario' => filled($materia->estilo['glosario'] ?? null) ? "- Use estos términos tal como la materia los define:\n".$materia->estilo['glosario'] : '',
        ];
    }

    /** @return array{titulo: string, proposito: string, secciones: array, resumen: array, preguntas: array, fuentes: array} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim(str_replace("\r\n", "\n", (string) $v)) : '';
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/\R/', $t($v))), fn ($x) => $x !== ''));

        $secciones = [];
        foreach (array_values(is_array($d['secciones'] ?? null) ? $d['secciones'] : []) as $s) {
            $s = is_array($s) ? $s : ['texto' => $s];
            $s = ['titulo' => $t($s['titulo'] ?? ''), 'texto' => $t($s['texto'] ?? ''), 'idea_clave' => $t($s['idea_clave'] ?? ''),
                'codigo' => is_scalar($s['codigo'] ?? null) ? rtrim(str_replace("\r\n", "\n", (string) $s['codigo'])) : ''];
            if ($s['titulo'] !== '' || $s['texto'] !== '') {
                $secciones[] = $s;
            }
        }
        $preguntas = [];
        foreach (array_values(is_array($d['preguntas'] ?? null) ? $d['preguntas'] : []) as $p) {
            $p = is_array($p) ? $p : ['pregunta' => $p];
            if ($t($p['pregunta'] ?? '') !== '' || $t($p['respuesta'] ?? '') !== '') {
                $preguntas[] = ['pregunta' => $t($p['pregunta'] ?? ''), 'respuesta' => $t($p['respuesta'] ?? '')];
            }
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'secciones' => $secciones,
            'resumen' => $lista($d['resumen'] ?? []), 'preguntas' => $preguntas, 'fuentes' => $lista($d['fuentes'] ?? [])];
    }

    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título del texto.';
        }
        if ($d['secciones'] === []) {
            return [...$e, 'El texto necesita al menos una sección.'];
        }
        if (count($d['secciones']) > self::MAX_SECCIONES) {
            $e[] = 'Tiene '.count($d['secciones']).' secciones; el máximo es '.self::MAX_SECCIONES.'.';
        }
        foreach ($d['secciones'] as $i => $s) {
            if ($s['titulo'] === '') {
                $e[] = 'Sección '.($i + 1).': falta el título.';
            }
            if ($s['texto'] === '') {
                $e[] = 'Sección '.($i + 1).($s['titulo'] !== '' ? " («{$s['titulo']}»)" : '').': falta el texto.';
            }
        }
        if ($d['resumen'] === []) {
            $e[] = 'Falta el resumen con las ideas clave.';
        }
        foreach ($d['preguntas'] as $i => $p) {
            if ($p['pregunta'] === '' || $p['respuesta'] === '') {
                $e[] = 'Pregunta de comprensión '.($i + 1).': necesita la pregunta y su respuesta.';
            }
        }

        return $e;
    }

    public function advertencias(array $d, Materia $materia): array
    {
        $d = $this->normalizar($d);
        $a = [];
        foreach ($d['secciones'] as $i => $s) {
            if (($n = $this->palabras($s['texto'])) > 500) {
                $a[] = 'Sección '.($i + 1)." («{$s['titulo']}») tiene {$n} palabras; divídala para que se lea mejor.";
            }
        }
        if (($m = $this->minutos($d)) > 30) {
            $a[] = "El texto toma unos {$m} minutos de lectura: considere dividirlo en dos.";
        }
        if ($d['preguntas'] === []) {
            $a[] = 'No tiene preguntas de comprensión: el estudiante no podrá comprobar si entendió.';
        }
        if ($d['fuentes'] === []) {
            $a[] = 'No cita fuentes: verifique que lo dicho coincide con su material.';
        }

        return $a;
    }

    public function plantillaVacia(array $temas): array
    {
        return ['titulo' => $temas ? implode(' y ', $temas) : '', 'proposito' => '',
            'secciones' => [['titulo' => '', 'texto' => '', 'idea_clave' => '', 'codigo' => ''], ['titulo' => '', 'texto' => '', 'idea_clave' => '', 'codigo' => '']],
            'resumen' => [], 'preguntas' => [['pregunta' => '', 'respuesta' => '']], 'fuentes' => []];
    }

    public function minutos(array $d): int
    {
        $d = $this->normalizar($d);
        $n = array_sum(array_map(fn ($s) => $this->palabras($s['texto']), $d['secciones']));

        return max(1, (int) ceil($n / self::PALABRAS_MINUTO));
    }

    /**
     * Bloques de un texto para mostrarlo: párrafos separados por una línea en blanco y listas cuyas
     * líneas empiezan con «- ». El texto se escapa en la vista; aquí no se genera HTML.
     *
     * @return array<int, array{tipo: string, contenido: string|array<int, string>}>
     */
    public static function bloques(string $texto): array
    {
        $bloques = [];
        foreach (preg_split('/\n\s*\n/', trim($texto)) as $b) {
            // Dentro de un bloque, las líneas «- » seguidas forman una lista y las demás, un párrafo
            $tramo = null;
            foreach (array_filter(array_map('trim', explode("\n", $b)), fn ($l) => $l !== '') as $l) {
                $tipo = str_starts_with($l, '- ') ? 'lista' : 'parrafo';
                if ($tramo === null || $tramo['tipo'] !== $tipo) {
                    if ($tramo !== null) {
                        $bloques[] = $tramo;
                    }
                    $tramo = ['tipo' => $tipo, 'lineas' => []];
                }
                $tramo['lineas'][] = $tipo === 'lista' ? substr($l, 2) : $l;
            }
            if ($tramo !== null) {
                $bloques[] = $tramo;
            }
        }

        return array_map(fn ($t) => ['tipo' => $t['tipo'], 'contenido' => $t['tipo'] === 'lista' ? $t['lineas'] : implode("\n", $t['lineas'])], $bloques);
    }

    /** Texto plano del apunte, tal como queda en el repositorio para servir de contexto a la IA. */
    public function textoPlano(array $d): string
    {
        $d = $this->normalizar($d);
        $partes = ["# {$d['titulo']}", $d['proposito']];
        foreach ($d['secciones'] as $s) {
            $partes[] = "## {$s['titulo']}\n\n{$s['texto']}".($s['codigo'] !== '' ? "\n\n```\n{$s['codigo']}\n```" : '').($s['idea_clave'] !== '' ? "\n\nIdea clave: {$s['idea_clave']}" : '');
        }
        $partes[] = "## Ideas clave\n\n- ".implode("\n- ", $d['resumen']);
        if ($d['fuentes']) {
            $partes[] = "## Fuentes\n\n- ".implode("\n- ", $d['fuentes']);
        }

        return trim(implode("\n\n", array_filter($partes)));
    }

    /**
     * Guarda el apunte en el repositorio de contenido de la materia (módulo 7) como lectura.
     * Si ese mismo texto ya está, no lo duplica.
     *
     * @return array{contenido: Contenido, nuevo: bool}
     */
    public function guardarEnRepositorio(Material $m): array
    {
        $texto = $this->textoPlano($m->contenido);
        $sha = hash('sha256', $texto);
        $existente = Contenido::query()->where('materia_id', $m->materia_id)->where('sha256', $sha)->first();
        if ($existente) {
            return ['contenido' => $existente, 'nuevo' => false];
        }
        $ruta = "contenido/{$m->materia_id}/{$sha}.md";
        Storage::disk('local')->put($ruta, $texto);

        return ['contenido' => Contenido::query()->create([
            'materia_id' => $m->materia_id, 'titulo' => Str::limit($m->titulo, 250, ''), 'tipo' => 'lectura',
            'ruta' => $ruta, 'mime' => 'text/markdown', 'sha256' => $sha, 'texto_extraido' => $texto,
        ]), 'nuevo' => true];
    }

    private function palabras(string $texto): int
    {
        return str_word_count(Str::ascii($texto));
    }
}
