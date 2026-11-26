<?php

namespace Tests\Feature;

use App\Evaluacion\ConfiguracionActividad;
use App\Evaluacion\ImportadorBanco;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Panel de configuración de la actividad (5.7), nivel de ayuda (4.4) y respaldo imprimible (5.4.12). */
class ConfiguracionActividadTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Artefacto $a;

    private Actividad $actividad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->a = app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-CFG-P1', 'titulo' => 'Parcial', 'tipo' => 'parcial', 'valor' => '5', 'items' => [
            ['tipo' => 'unica', 'peso' => '2', 'enunciado' => '¿Qué estructura es LIFO?', 'opciones' => ['a' => 'Cola', 'b' => 'Pila'], 'correcta' => 'b'],
            ['tipo' => 'reto', 'peso' => '3', 'enunciado' => 'Escriba sumar(l).', 'plantilla_codigo' => "function sumar(l) {\n}\n",
                'casos_visibles' => [['entrada' => 'sumar([1,2])', 'esperada' => '3']],
                'verificaciones' => [['id' => 'v1', 'nombre' => 'Suma', 'fraccion' => '1', 'prueba' => 'sumar([1,2]) === 3']]],
        ]]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => $this->a->id, 'nombre' => 'Parcial', 'valor_max' => '5']);
    }

    private function datos(): array
    {
        preg_match('~<script type="application/json" id="datos-examen">(.*?)</script>~s', $this->get(route('calificador.examen', $this->actividad))->getContent(), $m);

        return json_decode($m[1], true);
    }

    private function formulario(array $cambios = []): array
    {
        return array_replace(['instrucciones' => 'Lea todo antes de empezar.', 'modo_desconectado' => '1', 'sonda' => 'https://example.org/204', 'intervalo_sonda' => 30,
            'con_reloj' => '1', 'reloj_minutos' => 80, 'contar_salidas' => '1', 'umbral_salidas' => 2, 'umbral_salidas_segundos' => 45, 'duracion_minima_minutos' => 10,
            'nivel_ayuda' => 'minimo', 'tiempo_ejecucion_ms' => 3000], $cambios);
    }

    #[Test]
    public function los_valores_por_defecto_dependen_del_tipo_de_actividad(): void
    {
        $c = ConfiguracionActividad::efectiva($this->a);
        $this->assertTrue($c['modo_desconectado'], 'Parcial: modo desconectado activo');
        $this->assertSame('intermedio', $c['nivel_ayuda']);
        $this->assertNull($c['reloj_minutos']);
        $this->assertTrue($c['contar_salidas']);

        $this->a->update(['tipo' => 'taller']);
        $c = ConfiguracionActividad::efectiva($this->a->fresh());
        $this->assertFalse($c['modo_desconectado']);
        $this->assertSame('completo', $c['nivel_ayuda']);
    }

    #[Test]
    public function el_panel_guarda_las_opciones_sin_tocar_el_valor_y_el_examen_las_recibe(): void
    {
        $this->get(route('configuracion.edit', $this->a))->assertOk()->assertSee('Nivel de ayuda de sintaxis')->assertSee('Modo desconectado');
        $this->put(route('configuracion.update', $this->a), $this->formulario())->assertRedirect()
            ->assertSessionHas('estado', fn ($s) => str_contains($s, 'vuelva a descargarlo'));

        $cfg = $this->a->fresh()->configuracion;
        $this->assertSame('5', $cfg['valor'], 'El valor del examen no se toca');
        $this->assertSame(80, $cfg['reloj_minutos']);
        $this->assertSame('minimo', $cfg['nivel_ayuda']);

        $d = $this->datos();
        $this->assertSame('Lea todo antes de empezar.', $d['instrucciones']);
        $this->assertSame(80, $d['reloj_minutos']);
        $this->assertTrue($d['modo_desconectado']);
        $this->assertSame('https://example.org/204', $d['sonda']);
        $this->assertSame(30, $d['intervalo_sonda']);
        $this->assertSame(3000, $d['tiempo_ms']);
        $this->assertSame('minimo', $d['nivel_ayuda']);
        $this->assertTrue($d['contar_salidas']);

        // Sin reloj y sin contar salidas
        $this->put(route('configuracion.update', $this->a), $this->formulario(['con_reloj' => null, 'contar_salidas' => null, 'modo_desconectado' => null]));
        $d = $this->datos();
        $this->assertNull($d['reloj_minutos']);
        $this->assertFalse($d['contar_salidas']);
        $this->assertFalse($d['modo_desconectado']);
    }

    #[Test]
    public function valida_las_opciones(): void
    {
        $this->put(route('configuracion.update', $this->a), $this->formulario(['nivel_ayuda' => 'total', 'reloj_minutos' => null, 'sonda' => 'ftp://x', 'umbral_salidas' => 0]))
            ->assertSessionHasErrors(['nivel_ayuda', 'reloj_minutos', 'sonda', 'umbral_salidas']);
        $this->assertArrayNotHasKey('nivel_ayuda', $this->a->fresh()->configuracion);
    }

    #[Test]
    public function el_examen_aplica_el_nivel_de_ayuda_y_el_contador_de_salidas(): void
    {
        $js = $this->get(route('calificador.examen', $this->actividad))->getContent();
        // Verificación de sintaxis al escribir, sin ejecutar, que no se muestra en el nivel mínimo
        $this->assertStringContainsString('function revisarSintaxis(codigo)', $js);
        $this->assertStringContainsString("if (nivel === 'minimo') return;", $js);
        // Catálogo explicado solo en el nivel completo; salida esperada solo en el completo
        $this->assertStringContainsString("if ((D.nivel_ayuda || 'completo') !== 'completo') return r;", $js);
        $this->assertStringContainsString("c.esperada !== undefined && nivel === 'completo'", $js);
        $this->assertStringContainsString('if (D.contar_salidas !== false) vigilarFoco();', $js);
    }

    #[Test]
    public function el_respaldo_imprimible_no_lleva_respuestas(): void
    {
        $this->put(route('configuracion.update', $this->a), $this->formulario());
        $html = $this->get(route('configuracion.imprimible', $this->a))->assertOk()
            ->assertSee('¿Qué estructura es LIFO?')->assertSee('○ a) Cola', false)->assertSee('Documento:')->assertSee('80 minutos')
            ->assertSee('Lea todo antes de empezar.')->getContent();
        $this->assertStringNotContainsString('sumar([1,2]) === 3', $html);
        $this->assertStringNotContainsString('✓', $html);
        $this->get(route('configuracion.imprimible', ['artefacto' => $this->a, 'version' => 'B']))->assertNotFound();
    }

    #[Test]
    public function solo_el_docente_de_la_materia_configura(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('configuracion.edit', $this->a))->assertForbidden();
        $this->put(route('configuracion.update', $this->a), $this->formulario())->assertForbidden();
        $this->get(route('configuracion.imprimible', $this->a))->assertForbidden();
    }
}
