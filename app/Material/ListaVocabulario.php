<?php

namespace App\Material;

use App\Models\Materia;
use Illuminate\Support\Str;

/**
 * Reglas de una lista de vocabulario (7.4, «Ayuda al alumno»): términos clave del tema con su
 * definición, un ejemplo de uso y, si se pide, el equivalente en inglés. Los términos aprobados
 * pueden pasar al vocabulario propio de la materia (capa 2 del prompt), para que la IA use las
 * mismas palabras en todo lo que genere.
 */
final class ListaVocabulario implements TipoMaterial
{
    public const TIPO = 'vocabulario';

    public const FORMATO = 'plataforma-vocabulario';

    public const MAX_TERMINOS = 40;

    /** Tope del campo «Vocabulario propio» del estilo de la materia. */
    public const MAX_GLOSARIO = 2000;

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
        return 'terminos';
    }

    public function nombreElemento(array $elemento): string
    {
        return (string) ($elemento['termino'] ?? '');
    }

    public function reglasGeneracion(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'between:3,'.self::MAX_TERMINOS],
            'nivel' => ['required', 'in:'.implode(',', array_keys(self::NIVELES))],
            'ingles' => ['nullable', 'boolean'],
            'contexto' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function variables(Materia $materia, array $op): array
    {
        return [
            'cantidad' => (string) max(3, min(self::MAX_TERMINOS, (int) ($op['cantidad'] ?? 12))),
            'nivel' => $op['nivel'] ?? 'introductorio',
            'contexto' => filled($op['contexto'] ?? null) ? trim($op['contexto']) : 'situaciones del entorno profesional del programa',
            'reglas_ingles' => ! empty($op['ingles'])
                ? '- En «ingles» escriba el término equivalente en inglés, tal como aparece en la documentación técnica.'
                : '- Deje «ingles» vacío.',
            'glosario_actual' => filled($materia->estilo['glosario'] ?? null)
                ? "- Términos que la materia ya definió; no los repita y sea coherente con ellos:\n".$materia->estilo['glosario']
                : '',
        ];
    }

    /** @return array{titulo: string, proposito: string, terminos: array<int, array>} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/[,;\n]/', $t($v))), fn ($x) => $x !== ''));

        $terminos = [];
        foreach (array_values(is_array($d['terminos'] ?? null) ? $d['terminos'] : []) as $x) {
            if (! is_array($x)) {
                continue;
            }
            $x = [
                'termino' => $t($x['termino'] ?? ''),
                'tema' => $t($x['tema'] ?? ''),
                'definicion' => $t($x['definicion'] ?? ''),
                'ejemplo' => $t($x['ejemplo'] ?? ''),
                'ingles' => $t($x['ingles'] ?? ''),
                'relacionados' => $lista($x['relacionados'] ?? []),
            ];
            if ($x['termino'] === '' && $x['definicion'] === '') {
                continue;                                                    // fila vacía del editor
            }
            $terminos[] = $x;
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'terminos' => $terminos];
    }

    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título de la lista.';
        }
        if ($d['terminos'] === []) {
            return [...$e, 'La lista necesita al menos un término.'];
        }
        if (count($d['terminos']) > self::MAX_TERMINOS) {
            $e[] = 'Tiene '.count($d['terminos']).' términos; el máximo es '.self::MAX_TERMINOS.'.';
        }
        $vistos = [];
        foreach ($d['terminos'] as $i => $x) {
            $n = 'Término '.($i + 1).($x['termino'] !== '' ? " («{$x['termino']}»)" : '');
            if ($x['termino'] === '') {
                $e[] = "{$n}: falta el término.";
            } elseif (in_array($this->clave($x['termino']), $vistos, true)) {
                $e[] = "{$n}: está repetido en la lista.";
            }
            $vistos[] = $this->clave($x['termino']);
            if ($x['definicion'] === '') {
                $e[] = "{$n}: falta la definición.";
            }
        }

        return $e;
    }

    public function advertencias(array $d, Materia $materia): array
    {
        $d = $this->normalizar($d);
        $temas = $materia->temario()->where('tipo', 'tema')->pluck('titulo')->map(fn ($s) => $this->clave($s))->all();
        $a = [];
        foreach ($d['terminos'] as $i => $x) {
            $n = 'Término '.($i + 1)." («{$x['termino']}»)";
            if ($temas && $x['tema'] !== '' && ! in_array($this->clave($x['tema']), $temas, true)) {
                $a[] = "{$n}: el tema «{$x['tema']}» no está en el temario de la materia.";
            }
            // Definición circular: usa la misma palabra que define (más de tres letras, para no saltar con «de» o «la»)
            $palabras = array_filter(explode(' ', $this->clave($x['termino'])), fn ($p) => mb_strlen($p) > 3);
            if ($palabras && $x['definicion'] !== '' && collect($palabras)->every(fn ($p) => preg_match('/\b'.preg_quote($p, '/').'\b/', $this->clave($x['definicion'])))) {
                $a[] = "{$n}: la definición usa el mismo término que define; explíquelo con otras palabras.";
            }
            if (str_word_count(Str::ascii($x['definicion'])) > 60) {
                $a[] = "{$n}: la definición es larga para un vocabulario; déjela en una o dos frases.";
            }
        }

        return $a;
    }

    public function plantillaVacia(array $temas): array
    {
        $temas = $temas ?: [''];
        $filas = [];
        foreach ($temas as $t) {
            foreach (range(1, 3) as $_) {
                $filas[] = ['termino' => '', 'tema' => $t, 'definicion' => '', 'ejemplo' => '', 'ingles' => '', 'relacionados' => []];
            }
        }

        return ['titulo' => '', 'proposito' => '', 'terminos' => $filas];
    }

    /**
     * Agrega al vocabulario propio de la materia los términos que aún no tiene, uno por línea
     * («término: definición»), sin pasar el tope del campo.
     *
     * @return array{agregados: int, ya_estaban: int, sin_espacio: int}
     */
    public function pasarAlGlosario(Materia $materia, array $d): array
    {
        $d = $this->normalizar($d);
        $estilo = $materia->estilo ?? [];
        $glosario = trim((string) ($estilo['glosario'] ?? ''));
        $existentes = collect(preg_split('/\R/', $glosario))->map(fn ($l) => $this->clave(Str::before($l, ':')))->filter()->all();

        $r = ['agregados' => 0, 'ya_estaban' => 0, 'sin_espacio' => 0];
        foreach ($d['terminos'] as $x) {
            if (in_array($this->clave($x['termino']), $existentes, true)) {
                $r['ya_estaban']++;

                continue;
            }
            $linea = $x['termino'].': '.Str::limit(rtrim($x['definicion'], '.'), 160, '…');
            $nuevo = $glosario === '' ? $linea : $glosario."\n".$linea;
            if (mb_strlen($nuevo) > self::MAX_GLOSARIO) {
                $r['sin_espacio']++;

                continue;
            }
            $glosario = $nuevo;
            $existentes[] = $this->clave($x['termino']);
            $r['agregados']++;
        }
        $materia->update(['estilo' => ['glosario' => $glosario] + $estilo]);

        return $r;
    }

    private function clave(string $s): string
    {
        return Str::of($s)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }
}
