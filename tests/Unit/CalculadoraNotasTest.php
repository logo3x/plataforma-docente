<?php

namespace Tests\Unit;

use App\Notas\CalculadoraNotas;
use App\Notas\ClasificacionCierre;
use App\Notas\Escala;
use App\Notas\EstadoCorte;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Reglas de cálculo de la sección 3.2 y simulador de cierre de la 3.7. */
class CalculadoraNotasTest extends TestCase
{
    private CalculadoraNotas $calc;

    protected function setUp(): void
    {
        $this->calc = new CalculadoraNotas(Escala::porDefecto());
    }

    #[Test]
    public function las_celdas_vacias_no_entran_al_promedio(): void
    {
        $this->assertSame('4', $this->calc->promedio(['4.0', null, '4.0']));
        $this->assertNull($this->calc->promedio([null, null]));
    }

    #[Test]
    public function un_cero_si_entra_al_promedio(): void
    {
        $this->assertSame('2', $this->calc->promedio(['4.0', '0', null]));
    }

    #[Test]
    public function el_promedio_no_arrastra_error_de_punto_flotante(): void
    {
        // Con float, 0.1 + 0.2 = 0.30000000000000004
        $this->assertSame('0.15', $this->calc->promedio(['0.1', '0.2']));
        $this->assertSame('3.3333333333', $this->calc->promedio(['3', '3', '4']));
    }

    #[Test]
    public function definitiva_del_corte_con_pesos_50_50(): void
    {
        $r = $this->calc->definitivaDivision([
            'trabajos' => ['peso' => '0.5', 'notas' => ['4.0', '3.0'], 'actividades' => 2],
            'parcial' => ['peso' => '0.5', 'notas' => ['2.0'], 'actividades' => 1],
        ]);

        $this->assertSame('2.75', $r->definitiva);
        $this->assertSame(EstadoCorte::Reprobado, $r->estado);
    }

    #[Test]
    public function un_componente_con_actividades_sin_notas_deja_el_corte_incompleto(): void
    {
        $r = $this->calc->definitivaDivision([
            'trabajos' => ['peso' => '0.5', 'notas' => ['5.0'], 'actividades' => 1],
            'parcial' => ['peso' => '0.5', 'notas' => [null], 'actividades' => 1],
        ]);

        $this->assertSame(EstadoCorte::Incompleto, $r->estado);
        $this->assertFalse($r->esFinal());
    }

    #[Test]
    public function un_corte_sin_columnas_queda_sin_actividades(): void
    {
        $r = $this->calc->definitivaDivision([
            'trabajos' => ['peso' => '0.5', 'notas' => [], 'actividades' => 0],
            'parcial' => ['peso' => '0.5', 'notas' => [], 'actividades' => 0],
        ]);

        $this->assertSame(EstadoCorte::SinActividades, $r->estado);
    }

    #[Test]
    public function los_pesos_deben_sumar_exactamente_uno(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->definitivaDivision([
            'a' => ['peso' => '0.5', 'notas' => ['3'], 'actividades' => 1],
            'b' => ['peso' => '0.4', 'notas' => ['3'], 'actividades' => 1],
        ]);
    }

    #[Test]
    public function semestre_30_30_40(): void
    {
        $this->calc->validarPesos(['0.3', '0.3', '0.4']);

        $aportes = [
            $this->calc->aporte('3.5', '0.3'),
            $this->calc->aporte('2.8', '0.3'),
            $this->calc->aporte('3.2', '0.4'),
        ];

        $this->assertSame(['1.05', '0.84', '1.28'], $aportes);
    }

    #[Test]
    public function el_redondeo_solo_afecta_lo_que_se_muestra(): void
    {
        $escala = Escala::porDefecto();

        $this->assertSame('3.0', $escala->mostrar('2.95'));
        $this->assertFalse($escala->aprueba('2.95'));
        $this->assertTrue($escala->apruebaSoloPorRedondeo('2.95'));
        $this->assertFalse($escala->apruebaSoloPorRedondeo('3.0'));
    }

    #[Test]
    public function reglas_de_redondeo_configurables(): void
    {
        $this->assertSame('2.9', (new Escala(redondeo: 'abajo'))->mostrar('2.99'));
        $this->assertSame('3.0', (new Escala(redondeo: 'arriba'))->mostrar('2.91'));
        $this->assertSame('3.46', (new Escala(decimales: 2))->mostrar('3.455'));
    }

    #[Test]
    public function conversion_de_escala_al_importar(): void
    {
        $escala = Escala::porDefecto();

        $this->assertSame('4', $escala->convertirDesde('80', '100'));
        $this->assertSame('3.5', $escala->convertirDesde('7', '10'));
        $this->assertSame('2.5', $escala->convertirDesde('25', '50'));
    }

    #[Test]
    public function escala_de_colegio_de_1_a_10(): void
    {
        $escala = new Escala('1', '10', '6', 1);
        $calc = new CalculadoraNotas($escala);

        $r = $calc->definitivaDivision(['unico' => ['peso' => '1', 'notas' => ['6.5'], 'actividades' => 1]]);

        $this->assertSame(EstadoCorte::Aprobado, $r->estado);
    }

    #[Test]
    public function simulador_nota_necesaria_en_lo_pendiente(): void
    {
        // Lleva 1.89 de 3.0 con el 60 % del semestre; falta el corte 3 (40 %).
        $r = $this->calc->simularCierre('1.89', '0.4');

        $this->assertSame(ClasificacionCierre::DependeDeLoPendiente, $r->clasificacion);
        $this->assertSame('2.775', $r->notaNecesaria);
    }

    #[Test]
    public function simulador_no_alcanza_ni_con_la_nota_maxima(): void
    {
        $r = $this->calc->simularCierre('0.6', '0.4');

        $this->assertSame(ClasificacionCierre::NoAlcanza, $r->clasificacion);
    }

    #[Test]
    public function simulador_ya_aprueba_aun_con_cero_en_lo_pendiente(): void
    {
        $r = $this->calc->simularCierre('3.1', '0.4');

        $this->assertSame(ClasificacionCierre::YaAprueba, $r->clasificacion);
    }

    #[Test]
    public function simulador_sin_pendientes_detecta_aprobacion_por_redondeo_y_margen(): void
    {
        $r = $this->calc->simularCierre('2.96', '0');

        $this->assertSame(ClasificacionCierre::ApruebaPorRedondeo, $r->clasificacion);
        $this->assertTrue($r->alMargen);
    }
}
