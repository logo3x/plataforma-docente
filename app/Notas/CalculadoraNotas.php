<?php

namespace App\Notas;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Reglas de cálculo del registro de notas (3.2) y del simulador de cierre (3.7).
 *
 * Clase pura: no consulta la base de datos, recibe valores decimales como texto y
 * devuelve texto. Así se prueba sin infraestructura y nunca usa float.
 *
 *   promedio del componente = media de las notas registradas, ignorando las vacías
 *   definitiva del corte    = Σ (promedio del componente × peso del componente)
 *   aporte al semestre      = definitiva del corte × peso del corte
 */
final class CalculadoraNotas
{
    public function __construct(private readonly Escala $escala) {}

    /**
     * @param  array<int, ?string>  $notas  null = celda vacía; no entra al promedio (3.1.6)
     */
    public function promedio(array $notas): ?string
    {
        $registradas = array_values(array_filter($notas, fn (?string $v) => $v !== null));

        if ($registradas === []) {
            return null;
        }

        $suma = BigDecimal::zero();
        foreach ($registradas as $v) {
            $suma = $suma->plus($v);
        }

        return Calculo::normalizar(
            $suma->dividedBy(count($registradas), Calculo::ESCALA_INTERNA, RoundingMode::HalfEven)
        );
    }

    /**
     * @param  array<int|string, array{peso: string, notas: array<int, ?string>, actividades: int}>  $componentes
     *                                                                                                             «actividades» es el número de columnas que tiene el componente.
     */
    public function definitivaDivision(array $componentes): ResultadoDivision
    {
        $this->validarPesos(array_column($componentes, 'peso'), 'componentes');

        $promedios = [];
        $hayActividades = false;
        $incompleto = false;
        $definitiva = BigDecimal::zero();

        foreach ($componentes as $clave => $c) {
            $promedio = $this->promedio($c['notas']);
            $promedios[$clave] = $promedio;

            if ($c['actividades'] > 0) {
                $hayActividades = true;
            }

            // 3.1.14: un componente con actividades y sin ninguna nota deja el corte incompleto
            if ($c['actividades'] > 0 && $promedio === null) {
                $incompleto = true;

                continue;
            }

            if ($promedio !== null) {
                $definitiva = $definitiva->plus(BigDecimal::of($promedio)->multipliedBy($c['peso']));
            }
        }

        if (! $hayActividades) {
            return new ResultadoDivision(EstadoCorte::SinActividades, null, $promedios);
        }

        $valor = Calculo::normalizar($definitiva);

        if ($incompleto) {
            return new ResultadoDivision(EstadoCorte::Incompleto, $valor, $promedios);
        }

        $estado = $this->escala->aprueba($valor) ? EstadoCorte::Aprobado : EstadoCorte::Reprobado;

        return new ResultadoDivision($estado, $valor, $promedios);
    }

    public function aporte(string $definitiva, string $pesoDivision): string
    {
        return Calculo::normalizar(BigDecimal::of($definitiva)->multipliedBy($pesoDivision));
    }

    /**
     * Simulador «qué necesita para pasar» (3.7.1–3.7.3). Solo calcula; no cambia ninguna nota.
     *
     * @param  string  $acumulado  suma de aportes ya consolidados, en la escala del dictado
     * @param  string  $pesoPendiente  fracción del total que aún falta (0..1)
     * @param  string  $margen  distancia a la aprobatoria que se considera «al margen»
     */
    public function simularCierre(string $acumulado, string $pesoPendiente, string $margen = '0.1'): ResultadoSimulacion
    {
        $acum = BigDecimal::of($acumulado);
        $pendiente = BigDecimal::of($pesoPendiente);
        $aprobatoria = BigDecimal::of($this->escala->aprobatoria);

        if ($pendiente->isZero()) {
            $alMargen = $acum->minus($aprobatoria)->abs()->isLessThanOrEqualTo($margen);

            $clasificacion = match (true) {
                $this->escala->aprueba($acumulado) => ClasificacionCierre::YaAprueba,
                $this->escala->apruebaSoloPorRedondeo($acumulado) => ClasificacionCierre::ApruebaPorRedondeo,
                default => ClasificacionCierre::NoAlcanza,
            };

            return new ResultadoSimulacion($clasificacion, null, $alMargen);
        }

        // Aun sacando el mínimo de la escala en lo pendiente, ya aprueba
        $conMinimo = $acum->plus($pendiente->multipliedBy($this->escala->minimo));
        if ($conMinimo->isGreaterThanOrEqualTo($aprobatoria)) {
            return new ResultadoSimulacion(ClasificacionCierre::YaAprueba, null, false);
        }

        // aprobatoria = acumulado + x · pesoPendiente  →  x = (aprobatoria − acumulado) / pesoPendiente
        $necesaria = $aprobatoria->minus($acum)->dividedBy($pendiente, Calculo::ESCALA_INTERNA, RoundingMode::Ceiling);

        if ($necesaria->isGreaterThan($this->escala->maximo)) {
            return new ResultadoSimulacion(ClasificacionCierre::NoAlcanza, Calculo::normalizar($necesaria), false);
        }

        // Al margen: lo que necesita está cerca del máximo (pierde por poco) — señal para segunda mirada
        $alMargen = BigDecimal::of($this->escala->maximo)->minus($necesaria)->isLessThanOrEqualTo($margen);

        return new ResultadoSimulacion(
            ClasificacionCierre::DependeDeLoPendiente,
            Calculo::normalizar($necesaria),
            $alMargen,
        );
    }

    /**
     * Los pesos de un nivel deben sumar exactamente 1 (2.7.1, 9.2.4).
     *
     * @param  array<int, string>  $pesos
     */
    public function validarPesos(array $pesos, string $que = 'divisiones'): void
    {
        if ($pesos === []) {
            return;
        }

        $suma = BigDecimal::zero();
        foreach ($pesos as $p) {
            $suma = $suma->plus($p);
        }

        if (! $suma->isEqualTo(1)) {
            throw new InvalidArgumentException("Los pesos de {$que} suman {$suma} y deben sumar exactamente 1.");
        }
    }
}
