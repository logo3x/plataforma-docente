<?php

namespace App\Evaluacion;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Reglas de una rúbrica analítica, iguales para la hecha a mano y la generada por IA:
 * criterios con peso (porcentajes que suman 100) y niveles con el porcentaje del criterio que
 * otorgan (el más alto da 100). El puntaje de una respuesta es determinista: nunca lo pone la IA.
 */
final class Rubricas
{
    public const FORMATO = 'plataforma-rubrica';

    public const MAX_CRITERIOS = 12;

    public const NIVELES_POR_DEFECTO = ['Excelente', 'Bueno', 'Básico', 'Insuficiente'];

    /**
     * Deja la rúbrica en su forma canónica: textos recortados, números como texto y los
     * niveles de cada criterio de mayor a menor porcentaje.
     *
     * @return array{titulo: string, proposito: ?string, criterios: array<int, array>}
     */
    public function normalizar(array $r): array
    {
        $texto = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $numero = function ($v) {
            try {
                return is_scalar($v) && trim((string) $v) !== '' ? (string) BigDecimal::of(str_replace(',', '.', trim((string) $v)))->strippedOfTrailingZeros() : '';
            } catch (MathException) {
                return trim((string) $v);
            }
        };

        $criterios = [];
        foreach (array_values(is_array($r['criterios'] ?? null) ? $r['criterios'] : []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $niveles = [];
            foreach (array_values(is_array($c['niveles'] ?? null) ? $c['niveles'] : []) as $n) {
                if (is_array($n) && ($texto($n['nombre'] ?? '') !== '' || $texto($n['descripcion'] ?? '') !== '' || $texto($n['porcentaje'] ?? '') !== '')) {
                    $niveles[] = ['nombre' => $texto($n['nombre'] ?? ''), 'descripcion' => $texto($n['descripcion'] ?? ''), 'porcentaje' => $numero($n['porcentaje'] ?? '')];
                }
            }
            usort($niveles, fn ($a, $b) => is_numeric($a['porcentaje']) && is_numeric($b['porcentaje'])
                ? BigDecimal::of($b['porcentaje'])->compareTo($a['porcentaje']) : 0);
            if ($texto($c['nombre'] ?? '') === '' && $niveles === [] && $texto($c['peso'] ?? '') === '') {
                continue;                                                    // fila vacía del editor
            }
            $criterios[] = ['nombre' => $texto($c['nombre'] ?? ''), 'descripcion' => $texto($c['descripcion'] ?? ''), 'peso' => $numero($c['peso'] ?? ''), 'niveles' => $niveles];
        }

        return ['titulo' => $texto($r['titulo'] ?? ''), 'proposito' => $texto($r['proposito'] ?? '') ?: null, 'criterios' => $criterios];
    }

    /** @return array<int, string> observaciones en lenguaje del docente; vacío si es válida */
    public function validar(array $r): array
    {
        $r = $this->normalizar($r);
        $e = [];
        if ($r['titulo'] === '') {
            $e[] = 'Falta el título de la rúbrica.';
        }
        if ($r['criterios'] === []) {
            return [...$e, 'La rúbrica necesita al menos un criterio.'];
        }
        if (count($r['criterios']) > self::MAX_CRITERIOS) {
            $e[] = 'Tiene '.count($r['criterios']).' criterios; el máximo es '.self::MAX_CRITERIOS.'. Agrupe los que evalúan lo mismo.';
        }

        $suma = BigDecimal::zero();
        $nombres = [];
        foreach ($r['criterios'] as $i => $c) {
            $n = $i + 1;
            $etq = $c['nombre'] !== '' ? "Criterio {$n} («{$c['nombre']}»)" : "Criterio {$n}";
            if ($c['nombre'] === '') {
                $e[] = "{$etq}: falta el nombre.";
            } elseif (in_array(mb_strtolower($c['nombre']), $nombres, true)) {
                $e[] = "{$etq}: hay otro criterio con el mismo nombre.";
            }
            $nombres[] = mb_strtolower($c['nombre']);

            if (! is_numeric($c['peso']) || BigDecimal::of($c['peso'])->isLessThanOrEqualTo(0)) {
                $e[] = "{$etq}: el peso debe ser un porcentaje mayor que cero.";
            } else {
                $suma = $suma->plus($c['peso']);
            }

            if (count($c['niveles']) < 2 || count($c['niveles']) > 6) {
                $e[] = "{$etq}: debe tener entre 2 y 6 niveles (tiene ".count($c['niveles']).').';
            }
            $vistos = [];
            foreach ($c['niveles'] as $j => $nv) {
                $m = $j + 1;
                if ($nv['nombre'] === '') {
                    $e[] = "{$etq}, nivel {$m}: falta el nombre.";
                }
                if (! is_numeric($nv['porcentaje']) || BigDecimal::of($nv['porcentaje'])->isNegative() || BigDecimal::of($nv['porcentaje'])->isGreaterThan(100)) {
                    $e[] = "{$etq}, nivel «{$nv['nombre']}»: el porcentaje debe estar entre 0 y 100.";

                    continue;
                }
                if (in_array($nv['porcentaje'], $vistos, true)) {
                    $e[] = "{$etq}: dos niveles otorgan el mismo porcentaje ({$nv['porcentaje']} %).";
                }
                $vistos[] = $nv['porcentaje'];
            }
            if ($c['niveles'] !== [] && is_numeric($c['niveles'][0]['porcentaje']) && ! BigDecimal::of($c['niveles'][0]['porcentaje'])->isEqualTo(100)) {
                $e[] = "{$etq}: el nivel más alto debe otorgar el 100 % del criterio; si no, nadie puede obtener la nota completa.";
            }
        }
        if (! $suma->isEqualTo(100) && collect($r['criterios'])->every(fn ($c) => is_numeric($c['peso']))) {
            $e[] = 'Los pesos de los criterios suman '.$suma->strippedOfTrailingZeros().' %; deben sumar 100 %.';
        }

        return $e;
    }

    /**
     * Puntaje de una respuesta según los niveles elegidos: máximo × Σ(peso × porcentaje) / 10 000.
     * Devuelve null mientras falte algún criterio por evaluar.
     *
     * @param  array<int, int|null>  $seleccion  índice del nivel elegido por criterio
     */
    public function puntaje(array $criterios, array $seleccion, string $maximo): ?string
    {
        $total = BigDecimal::zero();
        foreach ($criterios as $i => $c) {
            $j = $seleccion[$i] ?? null;
            if ($j === null || ! isset($c['niveles'][$j])) {
                return null;
            }
            $total = $total->plus(BigDecimal::of($c['peso'])->multipliedBy($c['niveles'][$j]['porcentaje']));
        }

        return (string) BigDecimal::of($maximo)->multipliedBy($total)->dividedBy(10000, 4, RoundingMode::HalfUp);
    }

    /**
     * Lo que queda en el desglose de la respuesta: copia de los criterios y del nivel elegido, para
     * que el informe del estudiante no cambie si después se edita la rúbrica (6.4).
     *
     * @param  array<int, int|null>  $seleccion
     */
    public function desglose(array $rubrica, array $seleccion, string $maximo): array
    {
        return [
            'rubrica' => [
                'titulo' => $rubrica['titulo'],
                'seleccion' => array_map(fn ($j) => $j === null ? null : (int) $j, $seleccion),
                'criterios' => array_map(function ($c, $i) use ($seleccion, $maximo) {
                    $j = $seleccion[$i] ?? null;
                    $nivel = $j !== null ? ($c['niveles'][$j] ?? null) : null;

                    return [
                        'nombre' => $c['nombre'],
                        'peso' => $c['peso'],
                        'nivel' => $nivel['nombre'] ?? null,
                        'descripcion' => $nivel['descripcion'] ?? null,
                        'porcentaje' => $nivel['porcentaje'] ?? null,
                        'valor' => $nivel ? (string) BigDecimal::of($maximo)->multipliedBy($c['peso'])->multipliedBy($nivel['porcentaje'])->dividedBy(10000, 4, RoundingMode::HalfUp) : null,
                        'maximo' => (string) BigDecimal::of($maximo)->multipliedBy($c['peso'])->dividedBy(100, 4, RoundingMode::HalfUp),
                    ];
                }, $rubrica['criterios'], array_keys($rubrica['criterios'])),
            ],
        ];
    }

    /** Rúbrica vacía para el editor manual, con los niveles habituales. */
    public function plantillaVacia(int $criterios = 3): array
    {
        $pct = ['100', '75', '50', '0'];

        return ['titulo' => '', 'proposito' => null, 'criterios' => array_map(fn ($i) => [
            'nombre' => '', 'descripcion' => '',
            'peso' => $i === $criterios - 1 ? (string) (100 - intdiv(100, $criterios) * ($criterios - 1)) : (string) intdiv(100, $criterios),
            'niveles' => array_map(fn ($n, $k) => ['nombre' => $n, 'descripcion' => '', 'porcentaje' => $pct[$k]], self::NIVELES_POR_DEFECTO, array_keys(self::NIVELES_POR_DEFECTO)),
        ], range(0, $criterios - 1))];
    }
}
