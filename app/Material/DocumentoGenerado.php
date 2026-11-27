<?php

namespace App\Material;

use App\Models\Materia;
use Illuminate\Support\Str;

/**
 * Documento por secciones (7.4.3, 7.5, 7.10): la salida común de los generadores configurables —guías de
 * laboratorio, manuales interactivos, boletines y los que cree el docente—. Una sección tiene título y texto
 * y, si el generador lo pide, código de ejemplo y un ejercicio con su prueba automática, que se resuelve en
 * el entorno de prueba de la plataforma (sección 4). La solución del ejercicio no sale en la versión para estudiantes.
 */
final class DocumentoGenerado implements TipoMaterial
{
    public const TIPO = 'documento';

    public const FORMATO = 'plataforma-documento';

    public const MAX_SECCIONES = 30;

    /** Lenguajes que el entorno de prueba de la plataforma ejecuta. */
    public const LENGUAJES = ['javascript' => 'JavaScript', 'html' => 'HTML y CSS', 'sql' => 'SQL (solo se muestra)', 'otro' => 'Otro (solo se muestra)'];

    public const EJECUTABLES = ['javascript', 'html'];

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
        return ['generador' => ['required', 'string', 'max:20'], 'campos' => ['nullable', 'array'], 'campos.*' => ['nullable', 'string', 'max:1000']];
    }

    /** $op['definicion'] lo pone el controlador al resolver el generador del docente. */
    public function variables(Materia $materia, array $op): array
    {
        $def = $op['definicion'];

        return [
            'nombre_generador' => $def['nombre'],
            'instrucciones' => app(Generadores::class)->instrucciones($def, $op['campos'] ?? []),
            'reglas_codigo' => match ($def['codigo']) {
                'interactivo' => "- Cada sección enseña un paso: un ejemplo corto en «codigo» (lenguaje «javascript», o «html» si el tema es de páginas web) que se ejecuta tal cual y muestra algo con console.log, y un «ejercicio».\n"
                    ."- El ejercicio tiene «enunciado», «plantilla» (el código para empezar, incompleto), «solucion» (la que usted propone) y «prueba»: una expresión de JavaScript que da true solo si el código resuelve el ejercicio, por ejemplo «sumar(2, 3) === 5 && sumar(-1, 1) === 0». En HTML la prueba se evalúa sobre la página, por ejemplo «document.querySelectorAll('li').length === 3».\n"
                    .'- La plantilla NO debe pasar la prueba; la solución sí.',
                'ejemplos' => '- Ponga código en «codigo» solo donde ayude, con su «lenguaje» (javascript, html, sql u otro). Deje «ejercicio» en null.',
                default => '- No incluya código: deje «codigo» vacío y «ejercicio» en null.',
            },
            'glosario' => filled($materia->estilo['glosario'] ?? null) ? "- Use estos términos tal como la materia los define:\n".$materia->estilo['glosario'] : '',
        ];
    }

    /** @return array{titulo: string, proposito: string, generador: string, secciones: array, fuentes: array} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim(str_replace("\r\n", "\n", (string) $v)) : '';
        $c = fn ($v) => is_scalar($v) ? rtrim(str_replace("\r\n", "\n", (string) $v)) : '';
        $secciones = [];
        foreach (array_values(is_array($d['secciones'] ?? null) ? $d['secciones'] : []) as $s) {
            $s = is_array($s) ? $s : ['texto' => $s];
            $codigo = $c($s['codigo'] ?? '');
            $lenguaje = Str::lower($t($s['lenguaje'] ?? ''));
            $e = is_array($s['ejercicio'] ?? null) ? $s['ejercicio'] : null;
            $ejercicio = $e ? ['enunciado' => $t($e['enunciado'] ?? ''), 'plantilla' => $c($e['plantilla'] ?? ''), 'solucion' => $c($e['solucion'] ?? ''), 'prueba' => $t($e['prueba'] ?? '')] : null;
            if ($ejercicio && implode('', $ejercicio) === '') {
                $ejercicio = null;
            }
            $s = ['titulo' => $t($s['titulo'] ?? ''), 'texto' => $t($s['texto'] ?? ''), 'codigo' => $codigo,
                'lenguaje' => $codigo === '' && ! $ejercicio ? '' : (array_key_exists($lenguaje, self::LENGUAJES) ? $lenguaje : ($lenguaje === '' ? 'javascript' : 'otro')),
                'ejercicio' => $ejercicio];
            if ($s['titulo'] !== '' || $s['texto'] !== '' || $codigo !== '' || $ejercicio) {
                $secciones[] = $s;
            }
        }
        $fuentes = array_values(array_filter(array_map($t, is_array($d['fuentes'] ?? null) ? $d['fuentes'] : preg_split('/\R/', $t($d['fuentes'] ?? ''))), fn ($x) => $x !== ''));

        return ['titulo' => $t($d['titulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'generador' => $t($d['generador'] ?? ''),
            'secciones' => $secciones, 'fuentes' => $fuentes];
    }

    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título del documento.';
        }
        if ($d['secciones'] === []) {
            return [...$e, 'El documento necesita al menos una sección.'];
        }
        if (count($d['secciones']) > self::MAX_SECCIONES) {
            $e[] = 'Tiene '.count($d['secciones']).' secciones; el máximo es '.self::MAX_SECCIONES.'.';
        }
        foreach ($d['secciones'] as $i => $s) {
            $n = 'Sección '.($i + 1).($s['titulo'] !== '' ? " («{$s['titulo']}»)" : '');
            if ($s['titulo'] === '') {
                $e[] = "{$n}: falta el título.";
            }
            if ($s['texto'] === '') {
                $e[] = "{$n}: falta el texto.";
            }
            if ($x = $s['ejercicio']) {
                if ($x['enunciado'] === '') {
                    $e[] = "{$n}: el ejercicio necesita su enunciado.";
                }
                if (in_array($s['lenguaje'], self::EJECUTABLES, true) && $x['prueba'] === '') {
                    $e[] = "{$n}: el ejercicio necesita su prueba (una expresión que dé true si el código lo resuelve).";
                }
            }
        }

        return $e;
    }

    public function advertencias(array $d, Materia $materia): array
    {
        $d = $this->normalizar($d);
        $a = [];
        foreach ($d['secciones'] as $i => $s) {
            $n = 'Sección '.($i + 1)." («{$s['titulo']}»)";
            if (str_word_count(Str::ascii($s['texto'])) > 500) {
                $a[] = "{$n} es larga: divídala para que se lea mejor.";
            }
            if ($s['ejercicio'] && $s['ejercicio']['solucion'] === '' && in_array($s['lenguaje'], self::EJECUTABLES, true)) {
                $a[] = "{$n}: el ejercicio no tiene solución; sin ella no se puede comprobar que su prueba funciona.";
            }
            if ($s['ejercicio'] && ! in_array($s['lenguaje'], self::EJECUTABLES, true)) {
                $a[] = "{$n}: el ejercicio es de {$s['lenguaje']}; la plataforma no lo ejecuta y el estudiante lo resuelve fuera.";
            }
        }
        if ($this->interactivas($d) > 0) {
            $a[] = 'Antes de compartirlo, pulse «Comprobar los ejercicios» en la vista del documento: cada solución debe pasar su prueba y cada plantilla no.';
        }

        return $a;
    }

    public function interactivas(array $d): int
    {
        return count(array_filter($d['secciones'] ?? [], fn ($s) => ($s['ejercicio'] ?? null) && in_array($s['lenguaje'] ?? '', self::EJECUTABLES, true)));
    }

    public function plantillaVacia(array $temas): array
    {
        return ['titulo' => $temas ? implode(' y ', $temas) : '', 'proposito' => '', 'generador' => '',
            'secciones' => [['titulo' => '', 'texto' => '', 'codigo' => '', 'lenguaje' => '', 'ejercicio' => null]], 'fuentes' => []];
    }
}
