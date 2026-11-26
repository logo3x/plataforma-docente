<?php

namespace Tests\Feature;

use App\Evaluacion\ConfiguracionActividad;
use App\Evaluacion\ImportadorBanco;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Verificaciones reservadas en el informe del estudiante (decisión pendiente 8). */
class ReservadasInformeTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Estudiante $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
    }

    /** Un reto con una verificación visible y dos reservadas, ya calificado por el navegador del docente. */
    private function calificado(string $tipo, ?string $sigla = null): Actividad
    {
        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => $sigla ?? 'ED-'.strtoupper($tipo), 'titulo' => 'Total', 'valor' => '5', 'tipo' => $tipo,
            'items' => [['tipo' => 'reto', 'peso' => '5', 'enunciado' => 'Escriba total().', 'plantilla_codigo' => 'function total(p) {}',
                'verificaciones' => [
                    ['id' => 'v1', 'nombre' => 'Suma dos precios', 'fraccion' => '0.5', 'prueba' => 'total([1,2]) === 3'],
                    ['id' => 'v2', 'nombre' => 'Un carrito vacío suma cero', 'fraccion' => '0.3', 'prueba' => 'total([]) === 0', 'reservada' => true],
                    ['id' => 'v3', 'nombre' => 'Acepta precios con decimales', 'fraccion' => '0.2', 'prueba' => 'total([0.5,0.25]) === 0.75', 'reservada' => true],
                ]]]]);
        $art = Artefacto::query()->latest('id')->first();
        $a = ($tipo === 'parcial' ? $this->parcial : $this->trabajos)->actividades()->create(['artefacto_id' => $art->id, 'nombre' => "Reto {$tipo}", 'valor_max' => '5']);
        $e = $this->crearEntrega($a, $this->ana);
        $item = $art->items()->first();
        $e->respuestas()->create(['artefacto_pregunta_id' => $item->id, 'respuesta' => ['valor' => 'function total(p) { return p.reduce((a, b) => a + b, 0); }'], 'puntaje_max' => '5']);
        $this->postJson(route('calificador.entregas.resultados-navegador', $e), ['resultados' => [['item' => $item->id, 'verificaciones' => ['v1' => true, 'v2' => true, 'v3' => false]]]])->assertOk();

        return $a;
    }

    #[Test]
    public function en_un_parcial_el_informe_solo_dice_cuantas_reservadas_hubo(): void
    {
        $a = $this->calificado('parcial');
        $this->assertSame('cantidad', ConfiguracionActividad::efectiva($a->artefacto)['reservadas_informe']);

        $this->get(route('calificador.devolucion.ver', [$a, $this->ana]))->assertOk()
            ->assertSee('Suma dos precios')
            ->assertDontSee('Un carrito vacío suma cero')->assertDontSee('Acepta precios con decimales')
            ->assertSee('2 verificaciones reservadas')->assertSee('Superó 1 de 2')->assertSee('1.5 de 2.5');

        // El docente las ve completas en el detalle, marcadas
        $this->get(route('calificador.detalle', $a->entregas()->first()))->assertOk()
            ->assertSee('Un carrito vacío suma cero')->assertSee('Acepta precios con decimales')->assertSee('reservada');
    }

    #[Test]
    public function en_un_taller_las_muestra_completas_y_el_docente_puede_cambiarlo(): void
    {
        $a = $this->calificado('taller');
        $ver = fn () => $this->get(route('calificador.devolucion.ver', [$a, $this->ana]));
        $ver()->assertSee('Un carrito vacío suma cero')->assertSee('Acepta precios con decimales')->assertDontSee('verificaciones reservadas');

        $datos = ConfiguracionActividad::efectiva($a->artefacto);
        $this->put(route('configuracion.update', $a->artefacto), array_merge($datos, ['reservadas_informe' => 'cantidad', 'con_reloj' => '0',
            'modo_desconectado' => $datos['modo_desconectado'] ? '1' : '0', 'contar_salidas' => '1', 'anexo' => '0']))->assertSessionHasNoErrors();
        $ver()->assertDontSee('Un carrito vacío suma cero')->assertSee('2 verificaciones reservadas');
        $this->get(route('configuracion.edit', $a->artefacto))->assertSee('Verificaciones reservadas en el informe del estudiante');
    }

    #[Test]
    public function las_entregas_calificadas_antes_de_la_marca_se_reconocen_por_el_nombre(): void
    {
        $a = $this->calificado('parcial', 'ED-VIEJO');
        $r = $a->entregas()->first()->respuestas()->first();
        $d = $r->desglose;
        $d['verificaciones'] = array_map(fn ($v) => array_diff_key($v, ['reservada' => 1]), $d['verificaciones']);
        $r->update(['desglose' => $d]);

        $this->get(route('calificador.devolucion.ver', [$a, $this->ana]))->assertDontSee('Un carrito vacío suma cero')->assertSee('2 verificaciones reservadas');
    }
}
