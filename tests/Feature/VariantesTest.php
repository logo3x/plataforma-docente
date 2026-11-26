<?php

namespace Tests\Feature;

use App\Evaluacion\GeneradorVariante;
use App\Evaluacion\ImportadorBanco;
use App\Models\Artefacto;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Versión B y segundo banco de un examen generados con IA (5.4.2). */
class VariantesTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Artefacto $a;

    private ConfigMotorIa $motor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 1);
        $this->actingAs($this->docente);
        $this->a = app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-VAR-P1', 'titulo' => 'Parcial', 'valor' => '5', 'items' => [
            ['tipo' => 'unica', 'peso' => '2', 'tema' => 'Pilas', 'enunciado' => 'En el casino, ¿qué plato se lava primero?', 'opciones' => ['a' => 'El primero', 'b' => 'El último'], 'correcta' => 'b', 'justificacion' => 'LIFO.'],
            ['tipo' => 'completar', 'peso' => '1', 'enunciado' => 'Complete.', 'plantilla' => 'pila.___(x)', 'huecos' => [['push', 'agregar']]],
            ['tipo' => 'reto', 'peso' => '2', 'enunciado' => 'Sume la lista [1,2,3].', 'plantilla_codigo' => "function sumar(l) {\n}\n",
                'casos_visibles' => [['entrada' => 'sumar([1,2,3])', 'esperada' => '6']],
                'verificaciones' => [['id' => 'v1', 'nombre' => 'Suma', 'fraccion' => '0.5', 'prueba' => 'sumar([1,2,3]) === 6'], ['id' => 'v2', 'nombre' => 'Vacía', 'fraccion' => '0.5', 'prueba' => 'sumar([]) === 0', 'reservada' => true]]],
        ]]);
        $this->motor = ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama', 'base_url' => 'http://ollama.test:11434',
            'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true]);
    }

    /** La versión que devolvería la IA: los mismos tipos y pesos con otros datos. */
    private function itemsNuevos(array $cambios = []): array
    {
        return array_replace([
            ['n' => 1, 'version' => 'A', 'tipo' => 'unica', 'peso' => '2', 'tema' => 'Pilas', 'enunciado' => 'En la bodega, ¿qué caja se despacha primero si se apilan?', 'opciones' => ['a' => 'La de abajo', 'b' => 'La de arriba'], 'correcta' => 'b'],
            ['n' => 2, 'tipo' => 'completar', 'peso' => '1', 'enunciado' => 'Complete.', 'plantilla' => 'cola.___(x)', 'huecos' => [['shift']]],
            ['n' => 3, 'tipo' => 'reto', 'peso' => '2', 'enunciado' => 'Sume la lista [4,5].', 'plantilla_codigo' => "function sumar(l) {\n}\n",
                'casos_visibles' => [['entrada' => 'sumar([4,5])', 'esperada' => '9']],
                'verificaciones' => [['id' => 'v1', 'nombre' => 'Suma', 'fraccion' => '0.5', 'prueba' => 'sumar([4,5]) === 9'], ['id' => 'v2', 'nombre' => 'Vacía', 'fraccion' => '0.5', 'prueba' => 'sumar([]) === 0', 'reservada' => true]]],
        ], $cambios);
    }

    private function ollamaResponde(string ...$r): void
    {
        $cola = Http::sequence();
        foreach ($r as $x) {
            $cola->push(['message' => ['content' => $x], 'prompt_eval_count' => 900, 'eval_count' => 700]);
        }
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]), 'ollama.test:11434/api/chat' => $cola]);
    }

    private function generar(string $version): GeneracionIa
    {
        $this->post(route('variantes.preparar', $this->a), ['version' => $version, 'dictado_id' => $this->dictado->id, 'motor_id' => $this->motor->id])->assertOk();
        $this->post(route('variantes.generar', $this->a), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id])->assertRedirect();

        return GeneracionIa::query()->where('estado', 'borrador')->latest('id')->first();
    }

    #[Test]
    public function el_item_guardado_vuelve_al_formato_del_banco_sin_perder_nada(): void
    {
        $items = $this->a->items()->with('pregunta')->orderBy('orden')->get()->map(fn ($ap) => ImportadorBanco::aBanco($ap))->all();
        $this->assertSame([], app(ImportadorBanco::class)->validar(['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'COPIA', 'titulo' => 'Copia', 'valor' => '5', 'items' => $items]));
        $this->assertSame('b', $items[0]['correcta']);
        $this->assertSame([['push', 'agregar']], $items[1]['huecos']);
        $this->assertTrue($items[2]['verificaciones'][1]['reservada']);
        $this->assertSame('LIFO.', $items[0]['justificacion']);
    }

    #[Test]
    public function genera_la_version_b_la_compara_con_la_original_y_la_agrega(): void
    {
        $this->assertSame(['B', '2A'], GeneradorVariante::posibles($this->a));
        $this->ollamaResponde(json_encode(['items' => $this->itemsNuevos()]));

        $this->get(route('artefactos.index', $this->materia))->assertSee('Generar la versión B o un segundo banco con IA');
        $this->get(route('variantes.create', $this->a))->assertOk()->assertSee('segunda versión para el mismo salón');
        $prompt = $this->post(route('variantes.preparar', $this->a), ['version' => 'B', 'dictado_id' => $this->dictado->id, 'motor_id' => $this->motor->id])->assertOk();
        $prompt->assertSee('versión B de un examen ya aprobado, a partir de su versión A')->assertSee('En el casino, ¿qué plato se lava primero?')
            ->assertSee('Exactamente 3 ítems')->assertSee('recalcúlelas');
        $this->post(route('variantes.generar', $this->a), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])->assertRedirect();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $g = GeneracionIa::query()->sole();
        $this->assertSame('variante', $g->tarea);
        $b = json_decode($g->borrador, true);
        $this->assertSame('B', $b['items'][0]['version'], 'La versión la fija la plataforma, no la IA');
        $this->assertArrayNotHasKey('n', $b['items'][0]);

        $this->get(route('variantes.borrador', $g))->assertOk()->assertSee('Mismos tipos y pesos que la versión A')
            ->assertSee('En el casino')->assertSee('En la bodega');
        $this->post(route('variantes.aprobar', $g))->assertRedirect(route('artefactos.index', $this->materia));

        $a = $this->a->fresh();
        $this->assertSame(['A', 'B'], $a->versiones());
        $this->assertSame(3, $a->items()->where('banco', 'B')->count());
        $this->assertSame(['2A'], GeneradorVariante::posibles($a));
        $html = $this->get(route('calificador.examen', $this->parcial->actividades()->create(['artefacto_id' => $a->id, 'nombre' => 'P', 'valor_max' => '5'])))->getContent();
        $this->assertStringContainsString('En la bodega', $html);
        $this->assertStringContainsString('"versiones":["A","B"]', $html);
        $this->assertStringNotContainsString('sumar([4,5]) === 9', $html, 'Las verificaciones nuevas tampoco salen en el examen');
    }

    #[Test]
    public function no_se_agrega_si_cambia_tipos_o_pesos_y_se_puede_corregir(): void
    {
        $mal = $this->itemsNuevos();
        $mal[1]['peso'] = '2';
        unset($mal[2]);
        $this->ollamaResponde(json_encode(['items' => array_values($mal)]), json_encode(['items' => $this->itemsNuevos()]));
        $g = $this->generar('B');

        $this->get(route('variantes.borrador', $g))->assertSee('debe tener los mismos tipos de ítem con los mismos pesos que la A')
            ->assertSee('Falta este ítem')->assertSee('Cambió el tipo o el peso');
        $this->post(route('variantes.aprobar', $g))->assertSessionHas('error_ia');
        $this->assertSame(['A'], $this->a->fresh()->versiones());

        $this->post(route('variantes.corregir', $g))->assertRedirect();
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->assertSame([], app(GeneradorVariante::class)->errores($nueva));
        $this->post(route('variantes.aprobar', $nueva))->assertRedirect();
        $this->assertSame(['A', 'B'], $this->a->fresh()->versiones());
    }

    #[Test]
    public function el_segundo_banco_sale_de_a_y_de_b(): void
    {
        $this->ollamaResponde(json_encode(['items' => $this->itemsNuevos()]), json_encode(['items' => $this->itemsNuevos()]), json_encode(['items' => $this->itemsNuevos()]));
        $this->post(route('variantes.aprobar', $this->generar('B')));
        $this->post(route('variantes.aprobar', $this->generar('2A')))->assertSessionHas('estado', fn ($s) => str_contains($s, 'El banco 2 ya se puede descargar'));
        $this->assertSame(['2B'], GeneradorVariante::posibles($this->a->fresh()));
        $this->post(route('variantes.preparar', $this->a), ['version' => '2B', 'dictado_id' => $this->dictado->id, 'motor_id' => $this->motor->id])
            ->assertSee('versión 2B de un examen ya aprobado, a partir de su versión B')->assertSee('En la bodega');
        $this->generar('2B');
        $this->post(route('variantes.aprobar', GeneracionIa::query()->where('estado', 'borrador')->sole()));
        $this->assertSame(['A', 'B', '2A', '2B'], $this->a->fresh()->versiones());
        $this->assertSame([1, 2], $this->a->fresh()->bancos());
    }

    #[Test]
    public function advierte_si_la_version_nueva_repite_la_respuesta_o_el_enunciado(): void
    {
        $orig = $this->a->items()->with('pregunta')->orderBy('orden')->get()->map(fn ($ap) => ImportadorBanco::aBanco($ap))->all();
        $copia = $this->itemsNuevos();
        $copia[0] = ['enunciado' => 'En el casino, ¿qué plato se lava primero?'] + $orig[0];
        $a = GeneradorVariante::advertencias($orig, $copia, 'A');
        $this->assertContains('Ítem 1: el enunciado es casi igual al de la versión A.', $a);
        $this->assertContains('Ítem 1: tiene las mismas opciones y la misma respuesta que en la versión A; quien la vio acierta sin pensar.', $a);
        $this->assertSame([], GeneradorVariante::advertencias($orig, $this->itemsNuevos(), 'A'));
    }

    #[Test]
    public function con_entregas_no_se_agregan_versiones_y_otro_docente_no_ve_nada(): void
    {
        $act = $this->parcial->actividades()->create(['artefacto_id' => $this->a->id, 'nombre' => 'P', 'valor_max' => '5']);
        $this->crearEntrega($act, $this->dictado->estudiantes()->first());
        $this->get(route('variantes.create', $this->a))->assertSee('Este examen ya tiene entregas');
        $this->assertContains('El examen ya tiene entregas: una versión nueva cambiaría a qué estudiante le toca cada una. Hágala sobre una copia del examen.',
            app(ImportadorBanco::class)->validarVersion($this->a, $this->itemsNuevos(), 'B'));

        $this->actingAs(User::factory()->create());
        $this->get(route('variantes.create', $this->a))->assertForbidden();
        $this->post(route('variantes.preparar', $this->a), ['version' => 'B', 'dictado_id' => $this->dictado->id])->assertForbidden();
    }
}
