<?php

namespace Tests\Feature;

use App\Calificacion\CalificadorEntrega;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/**
 * Calificación híbrida (revisión A1): el servidor califica lo determinista y el navegador
 * del docente devuelve qué verificaciones de código pasaron; el puntaje lo calcula el servidor.
 */
class CalificacionHibridaTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private const RETO = [
        'verificaciones' => [
            ['id' => 'suma', 'nombre' => 'La función suma dos números', 'fraccion' => '0.5'],
            ['id' => 'tabla', 'nombre' => 'La tabla muestra una fila por producto', 'fraccion' => '0.5'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario();
    }

    /** Parcial de 5.0: única 1.0 + ordenar 1.5 + reto 2.5 */
    private function prepararParcial(): array
    {
        [$artefacto, $items] = $this->crearArtefacto([
            ['unica', ['correcta' => 'b'], '1'],
            ['ordenar', ['orden' => ['p1', 'p2', 'p3']], '1.5'],
            ['reto', self::RETO, '2.5'],
        ]);
        $actividad = $this->crearActividad($this->parcial, ['artefacto_id' => $artefacto->id, 'nombre' => 'Parcial 1']);
        $entrega = $this->crearEntrega($actividad, $this->dictado->estudiantes()->first());

        app(CalificadorEntrega::class)->calificarServidor($entrega, [
            $items[0]->id => 'b',
            $items[1]->id => ['p1', 'p3', 'p2'],
            $items[2]->id => ['codigo' => 'function suma(a,b){return a+b}'],
        ]);

        return [$entrega, $items];
    }

    #[Test]
    public function el_servidor_califica_lo_determinista_y_deja_el_reto_pendiente(): void
    {
        [$entrega] = $this->prepararParcial();

        $this->assertSame('pendiente', $entrega->fresh()->estado_codigo);
        $this->assertNull(app(CalificadorEntrega::class)->nota($entrega), 'No hay nota mientras el reto esté pendiente.');
    }

    #[Test]
    public function el_navegador_reporta_verificaciones_y_el_servidor_calcula_la_nota(): void
    {
        [$entrega, $items] = $this->prepararParcial();

        $this->actingAs($this->docente)
            ->postJson(route('calificador.entregas.resultados-navegador', $entrega), [
                'resultados' => [[
                    'item' => $items[2]->id,
                    'verificaciones' => ['suma' => true, 'tabla' => false],
                    'casos' => [['entrada' => '[2,3]', 'salida' => '5', 'esperada' => '5', 'consola' => ['5'], 'estado' => 'ejecuta_con_salida']],
                ]],
            ])
            ->assertOk()
            ->assertJson(['estado_codigo' => 'calificado']);

        $reto = $entrega->respuestas()->where('artefacto_pregunta_id', $items[2]->id)->sole();
        $this->assertSame('1.2500', $reto->puntaje);
        $this->assertSame('navegador', $reto->calificado_por);
        $this->assertSame('La tabla muestra una fila por producto', $reto->desglose['verificaciones'][1]['nombre']);

        // 1.0 (única) + 0.5 (1 de 3 posiciones de 1.5) + 1.25 (reto) = 2.75
        $this->assertSame('2.75', app(CalificadorEntrega::class)->nota($entrega));
    }

    #[Test]
    public function el_navegador_no_puede_inventar_verificaciones_ni_calificar_items_del_servidor(): void
    {
        [$entrega, $items] = $this->prepararParcial();
        $url = route('calificador.entregas.resultados-navegador', $entrega);

        $this->actingAs($this->docente)->postJson($url, ['resultados' => [[
            'item' => $items[2]->id,
            'verificaciones' => ['suma' => true, 'tabla' => true, 'extra' => true],
        ]]])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'sobran: extra'));

        $this->actingAs($this->docente)->postJson($url, ['resultados' => [[
            'item' => $items[0]->id,
            'verificaciones' => ['x' => true],
        ]]])->assertStatus(422);

        $this->assertNull($entrega->respuestas()->where('artefacto_pregunta_id', $items[2]->id)->sole()->puntaje);
    }

    #[Test]
    public function solo_el_docente_dueno_de_la_materia_puede_enviar_resultados(): void
    {
        [$entrega, $items] = $this->prepararParcial();
        $intruso = User::factory()->create();

        $this->actingAs($intruso)
            ->postJson(route('calificador.entregas.resultados-navegador', $entrega), ['resultados' => [[
                'item' => $items[2]->id, 'verificaciones' => ['suma' => true, 'tabla' => true],
            ]]])
            ->assertForbidden();
    }

    #[Test]
    public function anular_un_item_dandolo_por_bueno_o_retirandolo_recalcula_la_nota(): void
    {
        [$entrega, $items] = $this->prepararParcial();
        $this->actingAs($this->docente)->postJson(route('calificador.entregas.resultados-navegador', $entrega), [
            'resultados' => [['item' => $items[2]->id, 'verificaciones' => ['suma' => true, 'tabla' => false]]],
        ])->assertOk();
        $calificador = app(CalificadorEntrega::class);

        // Ordenar (0.5 de 1.5) se da por bueno: 1 + 1.5 + 1.25 = 3.75
        $items[1]->update(['anulada_en' => now(), 'regla_anulacion' => 'dar_por_buena', 'motivo_anulacion' => 'Enunciado ambiguo']);
        $this->assertSame('3.75', $calificador->nota($entrega->fresh()));

        // Se retira y se reescala: (1 + 1.25) / 3.5 × 5 = 3.2142857143
        $items[1]->update(['regla_anulacion' => 'retirar_y_reescalar']);
        $this->assertSame('3.2142857143', $calificador->nota($entrega->fresh()));
    }
}
