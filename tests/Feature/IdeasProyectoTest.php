<?php

namespace Tests\Feature;

use App\Material\IdeasProyecto;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Material;
use App\Models\TemarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Generador «Ideas de proyectos» según el temario cubierto por el grupo (7.4, 7.1.5). */
class IdeasProyectoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $pilas;

    private TemarioItem $colas;

    private TemarioItem $grafos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        $u = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'unidad', 'titulo' => 'Estructuras']);
        foreach (['pilas' => 'Pilas', 'colas' => 'Colas', 'grafos' => 'Grafos'] as $p => $t) {
            $this->{$p} = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'parent_id' => $u->id, 'tipo' => 'tema', 'titulo' => $t]);
        }
    }

    private function proyecto(array $cambios = []): array
    {
        return array_replace(['titulo' => 'Turnero para el taller', 'resumen' => 'Una cola de atención con prioridades.', 'problema' => 'El taller de mantenimiento atiende sin orden.',
            'temas' => ['pilas', 'Colas'], 'dificultad' => 'media', 'integrantes' => '2-3', 'semanas' => '4',
            'entregables' => ['Aplicación funcionando', 'Informe de diseño'], 'hitos' => [['semana' => '4', 'entrega' => 'Versión final'], ['semana' => '2', 'entrega' => 'Prototipo']],
            'criterios' => ['Uso correcto de la cola', 'Pruebas'], 'extension' => 'Agregar prioridades.', 'notas_docente' => 'Suelen olvidar la cola vacía.'], $cambios);
    }

    private function conjunto(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-proyectos', 'version' => 1, 'titulo' => 'Proyectos del corte 2', 'proposito' => 'Integrar estructuras lineales.',
            'proyectos' => [$this->proyecto()]], $cambios);
    }

    private function ollamaResponde(string ...$respuestas): void
    {
        $cola = Http::sequence();
        foreach ($respuestas as $r) {
            $cola->push(['message' => ['content' => $r], 'prompt_eval_count' => 900, 'eval_count' => 700]);
        }
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]), 'ollama.test:11434/api/chat' => $cola]);
    }

    private function formulario(array $cambios = []): array
    {
        return array_replace(['dictado_id' => $this->dictado->id, 'grupo' => 'Diurno', 'vistos' => '1', 'cantidad' => 3, 'semanas' => 4, 'integrantes' => '2-3',
            'contexto' => 'contratistas del sector petrolero', 'motor_id' => $this->motor->id], $cambios);
    }

    private function marcarVisto(TemarioItem ...$temas): void
    {
        foreach ($temas as $t) {
            DB::table('avances_temario')->insert(['dictado_id' => $this->dictado->id, 'grupo_id' => $this->grupo->id, 'temario_item_id' => $t->id, 'cubierto_en' => now()->toDateString()]);
        }
    }

    #[Test]
    public function valida_entregables_criterios_integrantes_e_hitos(): void
    {
        $s = app(IdeasProyecto::class);
        $this->assertSame([], $s->errores($this->conjunto()));
        $this->assertSame('2', $s->normalizar($this->conjunto())['proyectos'][0]['hitos'][0]['semana'], 'Los hitos quedan en orden');

        $malo = $this->conjunto(['proyectos' => [$this->proyecto(['problema' => '', 'entregables' => [], 'criterios' => [], 'integrantes' => '3-2', 'semanas' => '30',
            'hitos' => [['semana' => '9', 'entrega' => 'Tarde']]])]]);
        $e = implode(' | ', $s->errores($malo));
        foreach (['falta el problema', 'qué se entrega', 'con qué criterios', 'entre 1 y 18 semanas', 'número o un rango'] as $f) {
            $this->assertStringContainsString($f, $e);
        }
        $this->assertStringContainsString('debe caer entre la semana 1 y la 4', implode(' | ', $s->errores($this->conjunto(['proyectos' => [$this->proyecto(['hitos' => [['semana' => '9', 'entrega' => 'X']]])]]))));

        $a = implode(' | ', $s->advertencias($this->conjunto(['proyectos' => [$this->proyecto(['temas' => ['Árboles B'], 'hitos' => [['semana' => '2', 'entrega' => 'Prototipo']]])]]), $this->materia));
        $this->assertStringContainsString('«Árboles B», que no está en el temario', $a);
        $this->assertStringContainsString('falta la entrega final', $a);
        $this->assertSame([], $s->advertencias($this->conjunto(), $this->materia));
    }

    #[Test]
    public function usa_los_temas_que_el_grupo_ya_vio(): void
    {
        $this->post(route('proyectos.preparar', $this->materia), $this->formulario())->assertSessionHasErrors(['tema' => 'El grupo Diurno aún no tiene temas marcados como vistos en la lista de chequeo.']);
        $this->post(route('proyectos.preparar', $this->materia), $this->formulario(['grupo' => '']))->assertSessionHasErrors('tema');

        $this->marcarVisto($this->pilas, $this->colas);
        $r = $this->post(route('proyectos.preparar', $this->materia), $this->formulario())->assertOk()
            ->assertSee('Temas que integra: Pilas; Colas')->assertSee('3 proyectos')->assertSee('4 semanas')->assertSee('2-3')
            ->assertSee('contratistas del sector petrolero')->assertSee(route('proyectos.generar', $this->materia), false);
        // El alcance del grupo (capa 2) dice además qué no ha visto
        $r->assertSee('Aún no ha visto (no evaluar): Grafos');

        // Sin «vistos», solo los temas marcados a mano
        $this->post(route('proyectos.preparar', $this->materia), $this->formulario(['vistos' => null, 'temas' => [$this->grafos->id]]))->assertSee('Temas que integra: Grafos');
    }

    #[Test]
    public function genera_aprueba_y_entrega_el_enunciado_sin_las_notas_del_docente(): void
    {
        $this->marcarVisto($this->pilas, $this->colas);
        $this->ollamaResponde("```json\n".json_encode($this->conjunto())."\n```");
        $this->post(route('proyectos.preparar', $this->materia), $this->formulario())->assertOk();
        $this->post(route('proyectos.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('proyectos.borrador', $g = GeneracionIa::query()->sole()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $this->assertSame('proyectos', $g->tarea);
        $this->post(route('proyectos.aprobar', $g))->assertRedirect();
        $m = Material::query()->sole();

        $guia = $this->get(route('proyectos.show', $m))->assertOk()->assertSee('Suelen olvidar la cola vacía')->assertSee('Semana 2: Prototipo')
            ->assertSee('Generar su rúbrica')->getContent();
        // La rúbrica sale con el proyecto ya descrito
        preg_match('/href="([^"]+)"[^>]*>Generar su rúbrica/', $guia, $enlace);
        $this->get(html_entity_decode($enlace[1]))->assertOk()->assertSee('Proyecto «Turnero para el taller»: El taller de mantenimiento atiende sin orden.');

        $this->get(route('proyectos.show', ['material' => $m, 'version' => 'estudiante']))->assertOk()
            ->assertSee('El taller de mantenimiento atiende sin orden.')->assertSee('Uso correcto de la cola')->assertSee('Agregar prioridades.')
            ->assertDontSee('Suelen olvidar la cola vacía')->assertDontSee('Generar su rúbrica')->assertDontSee('dificultad');
    }

    #[Test]
    public function un_borrador_invalido_se_corrige_y_a_mano_se_crea_y_otro_docente_no_ve_nada(): void
    {
        $this->ollamaResponde(json_encode($this->conjunto(['proyectos' => [$this->proyecto(['criterios' => []])]])), json_encode($this->conjunto(['titulo' => 'Corregido'])));
        $this->post(route('proyectos.preparar', $this->materia), $this->formulario(['vistos' => null, 'temas' => [$this->pilas->id]]));
        $this->post(route('proyectos.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();
        $this->get(route('proyectos.borrador', $g))->assertSee('con qué criterios')->assertSee('Pedir a la IA que las corrija');
        $this->post(route('proyectos.aprobar', $g))->assertSessionHas('error_ia');
        $this->post(route('proyectos.corregir', $g))->assertRedirect();
        $this->assertSame('Corregido', json_decode(GeneracionIa::query()->where('estado', 'borrador')->sole()->borrador, true)['titulo']);

        $this->get(route('proyectos.create', ['materia' => $this->materia, 'temas' => [$this->colas->id]]))->assertOk()
            ->assertSee('Agregar proyecto')->assertSee('\\u0022temas\\u0022:[\\u0022Colas\\u0022]', false);
        $this->post(route('proyectos.store', $this->materia), ['contenido' => json_encode($this->conjunto())])->assertRedirect();
        $m = Material::query()->where('tipo', 'proyectos')->sole();
        $this->get(route('ia.plantillas'))->assertSee('Capa 3 — Tarea: ideas de proyectos');
        $this->get(route('vocabularios.show', $m))->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get(route('proyectos.index', $this->materia))->assertForbidden();
        $this->get(route('proyectos.show', $m))->assertForbidden();
    }
}
