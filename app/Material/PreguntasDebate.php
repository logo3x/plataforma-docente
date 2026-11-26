<?php

namespace App\Material;

use App\Models\Materia;
use Illuminate\Support\Str;

/**
 * Reglas de un conjunto de preguntas de debate (7.4, «Ayuda al alumno»): preguntas abiertas para
 * discutir en clase, con el caso que las sitúa, al menos dos posturas defendibles con sus
 * argumentos (la guía del docente), preguntas de seguimiento y la idea que debe quedar al cierre.
 */
final class PreguntasDebate implements TipoMaterial
{
    public const TIPO = 'debate';

    public const FORMATO = 'plataforma-debate';

    public const MAX_PREGUNTAS = 12;

    public const ENFOQUES = ['dilema' => 'Dilema', 'caso' => 'Análisis de caso', 'controversia' => 'Controversia', 'prediccion' => 'Predicción', 'comparacion' => 'Comparación'];

    public const DINAMICAS = ['individual' => 'Individual', 'parejas' => 'En parejas', 'grupos' => 'En grupos', 'plenaria' => 'Plenaria'];

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
        return 'preguntas';
    }

    public function nombreElemento(array $elemento): string
    {
        return (string) ($elemento['pregunta'] ?? '');
    }

    public function reglasGeneracion(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'between:1,'.self::MAX_PREGUNTAS],
            'enfoque' => ['required', 'in:mixto,'.implode(',', array_keys(self::ENFOQUES))],
            'dinamica' => ['required', 'in:mixta,'.implode(',', array_keys(self::DINAMICAS))],
            'minutos' => ['required', 'integer', 'between:5,120'],
            'contexto' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function variables(Materia $materia, array $op): array
    {
        $enfoque = $op['enfoque'] ?? 'mixto';
        $dinamica = $op['dinamica'] ?? 'mixta';

        return [
            'cantidad' => (string) max(1, min(self::MAX_PREGUNTAS, (int) ($op['cantidad'] ?? 4))),
            'enfoque' => $enfoque === 'mixto' ? 'variado: '.implode(', ', array_keys(self::ENFOQUES)) : $enfoque,
            'dinamica' => $dinamica === 'mixta' ? 'la que mejor sirva a cada pregunta' : $dinamica,
            'minutos' => (string) max(5, min(120, (int) ($op['minutos'] ?? 15))),
            'contexto' => filled($op['contexto'] ?? null) ? trim($op['contexto']) : 'situaciones reales del entorno profesional del programa',
        ];
    }

    /** @return array{titulo: string, proposito: string, preguntas: array<int, array>} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/\R/', $t($v))), fn ($x) => $x !== ''));

        $preguntas = [];
        foreach (array_values(is_array($d['preguntas'] ?? null) ? $d['preguntas'] : []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $posturas = [];
            foreach (array_values(is_array($p['posturas'] ?? null) ? $p['posturas'] : []) as $po) {
                $po = is_array($po) ? $po : ['postura' => $po];
                if ($t($po['postura'] ?? '') !== '' || $lista($po['argumentos'] ?? []) !== []) {
                    $posturas[] = ['postura' => $t($po['postura'] ?? ''), 'argumentos' => $lista($po['argumentos'] ?? [])];
                }
            }
            $p = [
                'pregunta' => $t($p['pregunta'] ?? ''),
                'tema' => $t($p['tema'] ?? ''),
                'enfoque' => Str::lower(Str::ascii($t($p['enfoque'] ?? ''))) ?: 'caso',
                'contexto' => $t($p['contexto'] ?? ''),
                'dinamica' => Str::lower(Str::ascii($t($p['dinamica'] ?? ''))) ?: 'grupos',
                'minutos' => preg_replace('/\D/', '', $t($p['minutos'] ?? '')),
                'posturas' => $posturas,
                'seguimiento' => $lista($p['seguimiento'] ?? []),
                'cierre' => $t($p['cierre'] ?? ''),
            ];
            if ($p['pregunta'] === '' && $p['contexto'] === '' && $posturas === []) {
                continue;                                                    // pregunta vacía del editor
            }
            $preguntas[] = $p;
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'preguntas' => $preguntas];
    }

    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título del conjunto de preguntas.';
        }
        if ($d['preguntas'] === []) {
            return [...$e, 'Debe haber al menos una pregunta.'];
        }
        if (count($d['preguntas']) > self::MAX_PREGUNTAS) {
            $e[] = 'Tiene '.count($d['preguntas']).' preguntas; el máximo es '.self::MAX_PREGUNTAS.'.';
        }
        foreach ($d['preguntas'] as $i => $p) {
            $n = 'Pregunta '.($i + 1);
            if ($p['pregunta'] === '') {
                $e[] = "{$n}: falta la pregunta.";
            }
            if ($p['cierre'] === '') {
                $e[] = "{$n}: falta la idea que debe quedar al cierre; sin ella el debate no lleva a ningún lado.";
            }
            if (count($p['posturas']) < 2) {
                $e[] = "{$n}: un debate necesita al menos dos posturas defendibles (tiene ".count($p['posturas']).').';
            }
            foreach ($p['posturas'] as $j => $po) {
                if ($po['postura'] === '' || $po['argumentos'] === []) {
                    $e[] = "{$n}, postura ".($j + 1).': debe decir qué sostiene y con al menos un argumento.';
                }
            }
            if (! array_key_exists($p['enfoque'], self::ENFOQUES)) {
                $e[] = "{$n}: el enfoque debe ser ".implode(', ', array_keys(self::ENFOQUES)).'.';
            }
            if (! array_key_exists($p['dinamica'], self::DINAMICAS)) {
                $e[] = "{$n}: la dinámica debe ser ".implode(', ', array_keys(self::DINAMICAS)).'.';
            }
            if ($p['minutos'] === '' || (int) $p['minutos'] < 5 || (int) $p['minutos'] > 120) {
                $e[] = "{$n}: el tiempo debe estar entre 5 y 120 minutos.";
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
        foreach ($d['preguntas'] as $i => $p) {
            $n = 'Pregunta '.($i + 1);
            if ($temas && $p['tema'] !== '' && ! in_array($clave($p['tema']), $temas, true)) {
                $a[] = "{$n} trata «{$p['tema']}», que no está en el temario de la materia.";
            }
            // Una pregunta de definición o de dato tiene una sola respuesta: no se debate
            if (preg_match('/^¿?\s*(qu[eé] es|qu[eé] son|cu[aá]l es la definici[oó]n|cu[aá]ntos?|cu[aá]ndo|qui[eé]n|d[oó]nde)\b/iu', $p['pregunta'])) {
                $a[] = "{$n} parece tener una sola respuesta correcta («{$p['pregunta']}»); reformúlela para que admita posturas.";
            }
        }
        $total = array_sum(array_map(fn ($p) => (int) $p['minutos'], $d['preguntas']));
        if ($total > 180) {
            $a[] = "Las preguntas suman {$total} minutos: probablemente no caben en una sesión.";
        }

        return $a;
    }

    public function plantillaVacia(array $temas): array
    {
        $temas = $temas ?: [''];

        return ['titulo' => '', 'proposito' => '', 'preguntas' => array_map(fn ($t) => [
            'pregunta' => '', 'tema' => $t, 'enfoque' => 'caso', 'contexto' => '', 'dinamica' => 'grupos', 'minutos' => '15',
            'posturas' => [['postura' => '', 'argumentos' => []], ['postura' => '', 'argumentos' => []]], 'seguimiento' => [], 'cierre' => '',
        ], $temas)];
    }
}
