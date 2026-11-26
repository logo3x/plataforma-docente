<?php

namespace App\Material;

use App\Models\Materia;
use Illuminate\Support\Str;

/**
 * Reglas de un conjunto de ejemplos prácticos resueltos paso a paso (7.4), iguales para los
 * escritos a mano y los generados por IA. En las materias con práctica de código en el navegador,
 * cada ejemplo puede traer su código final y una comprobación que se ejecuta para verificar que
 * el código realmente funciona: la IA se equivoca con código que «parece» correcto.
 */
final class EjemplosPracticos implements TipoMaterial
{
    public const TIPO = 'ejemplos';

    public const FORMATO = 'plataforma-ejemplos';

    public const MAX_EJEMPLOS = 15;

    public const DIFICULTADES = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'];

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
        return 'ejemplos';
    }

    public function nombreElemento(array $elemento): string
    {
        return (string) ($elemento['titulo'] ?? '');
    }

    public function reglasGeneracion(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'between:1,'.self::MAX_EJEMPLOS],
            'dificultad' => ['required', 'in:mixta,baja,media,alta'],
            'contexto' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function variables(Materia $materia, array $op): array
    {
        return [
            'cantidad' => (string) max(1, min(self::MAX_EJEMPLOS, (int) ($op['cantidad'] ?? 3))),
            'dificultad' => ['mixta' => 'mixta: de menor a mayor', 'baja' => 'baja', 'media' => 'media', 'alta' => 'alta'][$op['dificultad'] ?? 'mixta'] ?? 'mixta: de menor a mayor',
            'contexto' => filled($op['contexto'] ?? null) ? trim($op['contexto']) : 'situaciones cotidianas o del entorno profesional del programa',
            'reglas_codigo' => $materia->codigoVerificable()
                ? "- Si el ejemplo es de programación, escriba el código en JavaScript. En «codigo» ponga el programa final completo y en «comprobacion»\n"
                  ."  una expresión de JavaScript que dé exactamente true cuando ese código es correcto (por ejemplo: sumar([1,2,3]) === 6).\n"
                  .'  La comprobación se ejecutará de verdad: no use DOM, red ni archivos.'
                : '- Esta materia no verifica código en el navegador: deje «codigo» y «comprobacion» vacíos; si hace falta código, póngalo en el paso.',
        ];
    }

    /** @return array{titulo: string, proposito: string, ejemplos: array<int, array>} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $c = fn ($v) => is_scalar($v) ? rtrim(str_replace("\r\n", "\n", (string) $v)) : '';    // el código conserva la sangría
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/\R/', $t($v))), fn ($x) => $x !== ''));

        $ejemplos = [];
        foreach (array_values(is_array($d['ejemplos'] ?? null) ? $d['ejemplos'] : []) as $e) {
            if (! is_array($e)) {
                continue;
            }
            $pasos = [];
            foreach (array_values(is_array($e['pasos'] ?? null) ? $e['pasos'] : []) as $p) {
                $p = is_array($p) ? $p : ['explicacion' => $p];
                if ($t($p['explicacion'] ?? '') !== '' || trim($c($p['codigo'] ?? '')) !== '') {
                    $pasos[] = ['explicacion' => $t($p['explicacion'] ?? ''), 'codigo' => $c($p['codigo'] ?? '')];
                }
            }
            $e = [
                'titulo' => $t($e['titulo'] ?? ''),
                'tema' => $t($e['tema'] ?? ''),
                'dificultad' => Str::lower(Str::ascii($t($e['dificultad'] ?? ''))) ?: 'media',
                'enunciado' => $t($e['enunciado'] ?? ''),
                'pasos' => $pasos,
                'respuesta' => $t($e['respuesta'] ?? ''),
                'errores' => $lista($e['errores'] ?? []),
                'practica' => $t($e['practica'] ?? ''),
                'codigo' => $c($e['codigo'] ?? ''),
                'comprobacion' => $t($e['comprobacion'] ?? ''),
            ];
            if ($e['titulo'] === '' && $e['enunciado'] === '' && $pasos === []) {
                continue;                                                    // ejemplo vacío del editor
            }
            $ejemplos[] = $e;
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'ejemplos' => $ejemplos];
    }

    /** @return array<int, string> lo que impide guardar */
    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título del conjunto de ejemplos.';
        }
        if ($d['ejemplos'] === []) {
            return [...$e, 'Debe haber al menos un ejemplo.'];
        }
        if (count($d['ejemplos']) > self::MAX_EJEMPLOS) {
            $e[] = 'Tiene '.count($d['ejemplos']).' ejemplos; el máximo es '.self::MAX_EJEMPLOS.'.';
        }
        foreach ($d['ejemplos'] as $i => $x) {
            $n = 'Ejemplo '.($i + 1).($x['titulo'] !== '' ? " («{$x['titulo']}»)" : '');
            foreach (['titulo' => 'el título', 'enunciado' => 'el enunciado', 'respuesta' => 'la respuesta final'] as $campo => $nombre) {
                if ($x[$campo] === '') {
                    $e[] = "{$n}: falta {$nombre}.";
                }
            }
            if (count($x['pasos']) < 2) {
                $e[] = "{$n}: un ejemplo resuelto paso a paso necesita al menos dos pasos.";
            }
            foreach ($x['pasos'] as $j => $p) {
                if ($p['explicacion'] === '') {
                    $e[] = "{$n}, paso ".($j + 1).': falta la explicación; el código solo no enseña el porqué.';
                }
            }
            if (! array_key_exists($x['dificultad'], self::DIFICULTADES)) {
                $e[] = "{$n}: la dificultad debe ser baja, media o alta.";
            }
            if ($x['comprobacion'] !== '' && trim($x['codigo']) === '') {
                $e[] = "{$n}: tiene comprobación pero no el código final que se comprueba.";
            }
        }

        return $e;
    }

    /** @return array<int, string> lo que conviene revisar sin impedir guardar */
    public function advertencias(array $d, Materia $materia): array
    {
        $d = $this->normalizar($d);
        $a = [];
        $temario = $materia->temario()->where('tipo', 'tema')->pluck('titulo');
        $clave = fn ($s) => Str::of($s)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
        $temas = $temario->map($clave)->all();
        foreach ($d['ejemplos'] as $i => $x) {
            if ($temas && $x['tema'] !== '' && ! in_array($clave($x['tema']), $temas, true)) {
                $a[] = 'Ejemplo '.($i + 1)." trata «{$x['tema']}», que no está en el temario de la materia.";
            }
            if ($materia->codigoVerificable() && trim($x['codigo']) !== '' && $x['comprobacion'] === '') {
                $a[] = 'Ejemplo '.($i + 1).' tiene código sin comprobación: no se podrá verificar que funciona.';
            }
        }

        return $a;
    }

    /** Ejemplos con código comprobable, para verificarlos en el navegador. */
    public function verificables(array $d): array
    {
        return collect($this->normalizar($d)['ejemplos'])
            ->map(fn ($x, $i) => ['indice' => $i, 'codigo' => $x['codigo'], 'comprobacion' => $x['comprobacion']])
            ->filter(fn ($x) => trim($x['codigo']) !== '' && $x['comprobacion'] !== '')->values()->all();
    }

    public function plantillaVacia(array $temas = []): array
    {
        $temas = $temas ?: [''];

        return ['titulo' => '', 'proposito' => '', 'ejemplos' => array_map(fn ($t) => [
            'titulo' => '', 'tema' => $t, 'dificultad' => 'media', 'enunciado' => '',
            'pasos' => [['explicacion' => '', 'codigo' => ''], ['explicacion' => '', 'codigo' => '']],
            'respuesta' => '', 'errores' => [], 'practica' => '', 'codigo' => '', 'comprobacion' => '',
        ], $temas)];
    }
}
