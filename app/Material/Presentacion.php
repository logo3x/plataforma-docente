<?php

namespace App\Material;

use App\Models\Materia;
use Illuminate\Support\Str;

/**
 * Reglas de una presentación de clase (7.2, catálogo 7.4): diapositivas cortas para proyectar y, solo para el
 * docente, las notas del orador con lo que se dice en cada una. Se genera dentro de la aplicación, sin servicio
 * externo, y se descarga como PowerPoint editable (decisión pendiente 10, en lo que toca a presentaciones).
 */
final class Presentacion implements TipoMaterial
{
    public const TIPO = 'presentacion';

    public const FORMATO = 'plataforma-presentacion';

    public const MAX_DIAPOSITIVAS = 40;

    /** Lo que se proyecta debe poder leerse desde el fondo del salón. */
    public const MAX_VINETAS = 6;

    public const MAX_PALABRAS_VINETA = 15;

    public const TIPOS_DIAPOSITIVA = ['seccion' => 'Sección', 'contenido' => 'Contenido', 'codigo' => 'Código', 'pregunta' => 'Pregunta al grupo', 'cierre' => 'Cierre'];

    public const ENFOQUES = ['expositiva' => 'Expositiva', 'preguntas' => 'Con preguntas al grupo', 'taller' => 'Para un taller', 'repaso' => 'Repaso'];

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
        return 'diapositivas';
    }

    public function nombreElemento(array $elemento): string
    {
        return (string) ($elemento['titulo'] ?? '');
    }

    public function reglasGeneracion(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'between:3,'.self::MAX_DIAPOSITIVAS],
            'minutos' => ['required', 'integer', 'between:5,240'],
            'enfoque' => ['required', 'in:'.implode(',', array_keys(self::ENFOQUES))],
            'codigo' => ['nullable', 'boolean'],
            'contexto' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function variables(Materia $materia, array $op): array
    {
        return [
            'cantidad' => (string) max(3, min(self::MAX_DIAPOSITIVAS, (int) ($op['cantidad'] ?? 12))),
            'minutos' => (string) max(5, min(240, (int) ($op['minutos'] ?? 45))),
            'enfoque' => self::ENFOQUES[$op['enfoque'] ?? 'expositiva'] ?? 'Expositiva',
            'codigo' => ! empty($op['codigo']) ? 'sí: incluya diapositivas de tipo «codigo» con ejemplos cortos' : 'no: no incluya diapositivas de código',
            'contexto' => filled($op['contexto'] ?? null) ? trim($op['contexto']) : 'situaciones reales del entorno profesional del programa',
            'max_vinetas' => (string) self::MAX_VINETAS,
            'max_palabras' => (string) self::MAX_PALABRAS_VINETA,
        ];
    }

    /** @return array{titulo: string, subtitulo: string, proposito: string, diapositivas: array<int, array>} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/\R/', $t($v))), fn ($x) => $x !== ''));

        $diapositivas = [];
        foreach (array_values(is_array($d['diapositivas'] ?? null) ? $d['diapositivas'] : []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $tipo = Str::lower(Str::ascii($t($s['tipo'] ?? ''))) ?: 'contenido';
            $s = [
                'tipo' => $tipo,
                'titulo' => $t($s['titulo'] ?? ''),
                'puntos' => $lista($s['puntos'] ?? []),
                'codigo' => is_string($s['codigo'] ?? null) ? rtrim($s['codigo']) : '',
                'notas' => $t($s['notas'] ?? ''),
                'tema' => $t($s['tema'] ?? ''),
            ];
            if ($s['titulo'] === '' && $s['puntos'] === [] && $s['codigo'] === '' && $s['notas'] === '') {
                continue;                                                    // diapositiva vacía del editor
            }
            $diapositivas[] = $s;
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'subtitulo' => $t($d['subtitulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'diapositivas' => $diapositivas];
    }

    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título de la presentación.';
        }
        if (count($d['diapositivas']) < 2) {
            return [...$e, 'Una presentación necesita al menos dos diapositivas además de la portada.'];
        }
        if (count($d['diapositivas']) > self::MAX_DIAPOSITIVAS) {
            $e[] = 'Tiene '.count($d['diapositivas']).' diapositivas; el máximo es '.self::MAX_DIAPOSITIVAS.'.';
        }
        foreach ($d['diapositivas'] as $i => $s) {
            $n = 'Diapositiva '.($i + 2);                                    // la 1 es la portada
            if ($s['titulo'] === '') {
                $e[] = "{$n}: falta el título.";
            }
            if (! array_key_exists($s['tipo'], self::TIPOS_DIAPOSITIVA)) {
                $e[] = "{$n}: el tipo debe ser ".implode(', ', array_keys(self::TIPOS_DIAPOSITIVA)).'.';
            }
            if (in_array($s['tipo'], ['contenido', 'cierre'], true) && $s['puntos'] === []) {
                $e[] = "{$n}: una diapositiva de {$s['tipo']} necesita al menos una viñeta.";
            }
            if ($s['tipo'] === 'codigo' && $s['codigo'] === '') {
                $e[] = "{$n}: una diapositiva de código necesita el código.";
            }
            if (count($s['puntos']) > 10) {
                $e[] = "{$n}: tiene ".count($s['puntos']).' viñetas; con más de 10 no se lee proyectada. Divídala.';
            }
        }

        return $e;
    }

    public function advertencias(array $d, Materia $materia): array
    {
        $d = $this->normalizar($d);
        $clave = fn ($s) => Str::of($s)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
        $temas = $materia->temario()->where('tipo', 'tema')->pluck('titulo')->map($clave)->all();
        $a = [];
        foreach ($d['diapositivas'] as $i => $s) {
            $n = 'Diapositiva '.($i + 2);
            if (count($s['puntos']) > self::MAX_VINETAS) {
                $a[] = "{$n} tiene ".count($s['puntos']).' viñetas: más de '.self::MAX_VINETAS.' es mucho texto para proyectar.';
            }
            foreach ($s['puntos'] as $p) {
                if (str_word_count(Str::ascii($p)) > self::MAX_PALABRAS_VINETA) {
                    $a[] = "{$n}: «".Str::limit($p, 60).'» parece un párrafo; en la diapositiva va la idea, lo demás en las notas.';
                    break;
                }
            }
            if (in_array($s['tipo'], ['contenido', 'codigo'], true) && $s['notas'] === '') {
                $a[] = "{$n} no tiene notas del orador.";
            }
            if (substr_count($s['codigo'], "\n") > 14) {
                $a[] = "{$n}: el código tiene más de 15 líneas; proyectado se lee mal.";
            }
            if ($temas && $s['tema'] !== '' && ! in_array($clave($s['tema']), $temas, true)) {
                $a[] = "{$n} trata «{$s['tema']}», que no está en el temario de la materia.";
            }
        }

        return $a;
    }

    public function plantillaVacia(array $temas): array
    {
        $temas = $temas ?: [''];

        return ['titulo' => '', 'subtitulo' => '', 'proposito' => '', 'diapositivas' => [
            ['tipo' => 'seccion', 'titulo' => 'Qué vamos a ver', 'puntos' => [], 'codigo' => '', 'notas' => '', 'tema' => ''],
            ...array_map(fn ($t) => ['tipo' => 'contenido', 'titulo' => $t, 'puntos' => [''], 'codigo' => '', 'notas' => '', 'tema' => $t], $temas),
            ['tipo' => 'cierre', 'titulo' => 'Para llevarse', 'puntos' => [''], 'codigo' => '', 'notas' => '', 'tema' => ''],
        ]];
    }
}
