<?php

namespace Tests\Unit;

use App\Calificacion\CalificadorDeterminista;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Tipos de pregunta y reglas de calificación validadas en aula (5.3). */
class CalificadorDeterministaTest extends TestCase
{
    private CalificadorDeterminista $c;

    protected function setUp(): void
    {
        $this->c = new CalificadorDeterminista;
    }

    #[Test]
    public function seleccion_unica(): void
    {
        $this->assertSame('0.25', $this->c->calificar('unica', ['correcta' => 'c'], 'c', '0.25')->puntaje);
        $this->assertSame('0', $this->c->calificar('unica', ['correcta' => 'c'], 'a', '0.25')->puntaje);
        $this->assertSame('0', $this->c->calificar('unica', ['correcta' => 'c'], null, '0.25')->puntaje);
    }

    #[Test]
    public function verdadero_falso_exige_booleano(): void
    {
        $this->assertSame('0.1', $this->c->calificar('vf', ['correcta' => false], false, '0.1')->puntaje);
        $this->assertSame('0', $this->c->calificar('vf', ['correcta' => false], null, '0.1')->puntaje);
    }

    #[Test]
    public function multiple_da_credito_parcial_por_decision(): void
    {
        $clave = ['opciones' => ['a', 'b', 'c', 'd'], 'correctas' => ['a', 'c']];

        // Marca a (bien), no marca b (bien), no marca c (mal), no marca d (bien) → 3/4
        $r = $this->c->calificar('multiple', $clave, ['a'], '0.2');

        $this->assertSame('0.15', $r->puntaje);
        $this->assertCount(4, $r->desglose['decisiones']);
    }

    #[Test]
    public function multiple_sin_marcar_nada_no_regala_las_opciones_falsas(): void
    {
        $clave = ['opciones' => ['a', 'b', 'c', 'd'], 'correctas' => ['a']];

        $r = $this->c->calificar('multiple', $clave, [], '0.2');

        $this->assertSame('0', $r->puntaje);
        $this->assertTrue($r->desglose['sin_responder']);
    }

    #[Test]
    public function emparejar_par_por_par(): void
    {
        $r = $this->c->calificar('emparejar', ['pares' => ['1' => 'b', '2' => 'a', '3' => 'c']], ['1' => 'b', '2' => 'c', '3' => 'c'], '0.3');

        $this->assertSame('0.2', $r->puntaje);
    }

    #[Test]
    public function ordenar_posicion_por_posicion(): void
    {
        $r = $this->c->calificar('ordenar', ['orden' => ['p1', 'p2', 'p3', 'p4', 'p5']], ['p1', 'p3', 'p2', 'p4', 'p5'], '0.4');

        $this->assertSame('0.24', $r->puntaje);
    }

    #[Test]
    public function huecos_ignoran_mayusculas_espacios_y_arroba_de_directiva(): void
    {
        $clave = ['huecos' => ['csrf', 'extends', ['foreach', 'for']]];

        $r = $this->c->calificar('completar', $clave, ['@CSRF', ' @ extends ', 'for'], '0.45');

        $this->assertSame('0.45', $r->puntaje);
        $this->assertStringContainsString('mayúsculas', $r->desglose['nota']);
    }

    #[Test]
    public function credito_parcial_en_tercios_no_pierde_precision(): void
    {
        $r = $this->c->calificar('ordenar', ['orden' => ['a', 'b', 'c']], ['a', 'x', 'y'], '0.25');

        $this->assertSame('0.0833333333', $r->puntaje);
    }

    #[Test]
    public function retos_y_abiertas_no_los_califica_el_servidor(): void
    {
        $reto = $this->c->calificar('reto', [], ['codigo' => 'x'], '1');
        $abierta = $this->c->calificar('abierta', [], 'texto', '1');

        $this->assertNull($reto->puntaje);
        $this->assertSame('navegador', $reto->pendienteDe);
        $this->assertNull($abierta->puntaje);
        $this->assertSame('docente', $abierta->pendienteDe);
    }
}
