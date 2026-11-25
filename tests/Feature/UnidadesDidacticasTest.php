<?php

namespace Tests\Feature;

use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\TemarioItem;
use App\Models\UnidadDidactica;
use App\Models\User;
use App\Planificacion\GeneradorUnidad;
use App\Planificacion\UnidadesDidacticas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Generador «Unidad didáctica» (7.4) a partir del temario (7.1). */
class UnidadesDidacticasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $unidad;

    private TemarioItem $pilas;

    private TemarioItem $colas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        $m = $this->materia->id;
        $this->unidad = TemarioItem::query()->create(['materia_id' => $m, 'tipo' => 'unidad', 'titulo' => 'Unidad 2: Estructuras lineales', 'orden' => 1]);
        $this->pilas = TemarioItem::query()->create(['materia_id' => $m, 'parent_id' => $this->unidad->id, 'tipo' => 'tema', 'titulo' => 'Pilas', 'orden' => 0,
            'sugerencia' => 'Empezar con la pila de platos y validar paréntesis']);
        $this->colas = TemarioItem::query()->create(['materia_id' => $m, 'parent_id' => $this->unidad->id, 'tipo' => 'tema', 'titulo' => 'Colas', 'orden' => 1]);
        TemarioItem::query()->create(['materia_id' => $m, 'parent_id' => $this->unidad->id, 'tipo' => 'resultado', 'titulo' => 'Implementa estructuras lineales con arreglos', 'orden' => 2]);
    }

    private function sesion(string $titulo, array $temas, array $cambios = []): array
    {
        return array_replace(['titulo' => $titulo, 'temas' => $temas, 'objetivo' => 'Implementar '.$titulo, 'duracion' => '120',
            'inicio' => 'Pregunta detonante (10 min)', 'desarrollo' => 'Práctica guiada en parejas (90 min)', 'cierre' => 'Ticket de salida (20 min)',
            'recursos' => ['Guía de laboratorio'], 'evaluacion' => ['tipo' => 'formativa', 'descripcion' => 'Ticket de salida']], $cambios);
    }

    private function unidadIA(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-unidad', 'version' => 1, 'titulo' => 'Estructuras lineales', 'proposito' => 'Modelar problemas con pilas y colas.',
            'resultados' => ['Implementa estructuras lineales'], 'previos' => ['Arreglos'],
            'sesiones' => [$this->sesion('Pilas con arreglos', ['pilas']), $this->sesion('Colas y simulación', ['Colas'])],
            'evaluacion' => [['actividad' => 'Quiz de pilas y colas', 'tipo' => 'quiz', 'descripcion' => 'Operaciones básicas']]], $cambios);
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
        return array_replace(['dictado_id' => $this->dictado->id, 'unidad_id' => $this->unidad->id, 'sesiones' => 2, 'duracion' => 120,
            'grupo' => 'Diurno', 'enfasis' => 'más práctica', 'motor_id' => $this->motor->id], $cambios);
    }

    #[Test]
    public function distingue_errores_que_impiden_guardar_de_advertencias(): void
    {
        $s = app(UnidadesDidacticas::class);
        $this->assertSame([], $s->errores($this->unidadIA()));
        $this->assertSame(240, $s->minutos($this->unidadIA()));

        $mala = $this->unidadIA(['sesiones' => [$this->sesion('', [], ['objetivo' => '', 'duracion' => '5', 'evaluacion' => ['tipo' => 'examen']])],
            'evaluacion' => [['actividad' => 'Algo', 'tipo' => 'concurso']]]);
        $e = implode(' | ', $s->errores($mala));
        foreach (['falta el título', 'falta el objetivo', 'entre 10 y 600 minutos', 'diagnóstica, formativa, sumativa o ninguna', 'el tipo debe ser'] as $f) {
            $this->assertStringContainsString($f, $e);
        }

        // «pilas» en minúscula sí es el tema «Pilas»; un tema inventado y uno sin cubrir solo se advierten
        $a = $s->advertencias($this->unidadIA(['sesiones' => [$this->sesion('Pilas', ['pilas', 'Árboles AVL'])]]), $this->materia, $this->unidad);
        $this->assertCount(2, $a);
        $this->assertStringContainsString('«Árboles AVL», que no está en el temario', $a[0]);
        $this->assertStringContainsString('Ninguna sesión cubre estos temas de la unidad: Colas', $a[1]);
        $this->assertSame([], $s->advertencias($this->unidadIA(), $this->materia, $this->unidad));
    }

    #[Test]
    public function planea_con_ia_una_unidad_del_temario_y_la_aprueba(): void
    {
        $this->ollamaResponde("```json\n".json_encode($this->unidadIA(['unidad_id' => 999]))."\n```");

        $this->get(route('unidades.index', ['materia' => $this->materia, 'unidad' => $this->unidad->id]))->assertOk()->assertSee('Planear una unidad con IA');
        $this->post(route('unidades.preparar', $this->materia), $this->formulario())->assertOk()
            ->assertSee('Unidad 2: Estructuras lineales')
            ->assertSee('- Pilas')
            ->assertSee('Empezar con la pila de platos')
            ->assertSee('Implementa estructuras lineales con arreglos')
            ->assertSee('2, de 120 minutos cada una')
            ->assertSee('más práctica')
            ->assertSee(route('unidades.generar', $this->materia), false);

        $this->post(route('unidades.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('unidades.borrador', $g = GeneracionIa::query()->sole()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $b = json_decode($g->borrador, true);
        $this->assertSame($this->unidad->id, $b['unidad_id'], 'La unidad la fija la plataforma, no la IA');
        $this->assertSame('unidad', $g->tarea);

        $this->get(route('unidades.borrador', $g))->assertOk()->assertSee('El borrador es válido');
        $this->post(route('unidades.aprobar', $g))->assertRedirect();

        $u = UnidadDidactica::query()->sole();
        $this->assertSame($this->unidad->id, $u->temario_item_id);
        $this->assertSame($g->id, $u->generacion_ia_id);
        $this->assertSame('aprobado', $g->fresh()->estado);

        // La vista imprimible enlaza cada sesión con el generador de quices, con sus temas ya marcados
        $html = $this->get(route('unidades.show', $u))->assertOk()->assertSee('Pilas con arreglos')->assertSee('240 minutos')->getContent();
        $this->assertStringContainsString(e(route('generacion.create', ['materia' => $this->materia, 'temas' => [$this->pilas->id]])), $html);
        $this->get(route('generacion.create', ['materia' => $this->materia, 'temas' => [$this->colas->id]]))
            ->assertSee('value="'.$this->colas->id.'" checked', false)
            ->assertDontSee('value="'.$this->pilas->id.'" checked', false);
    }

    #[Test]
    public function un_borrador_invalido_se_corrige_con_la_ia_o_a_mano(): void
    {
        $this->ollamaResponde(json_encode($this->unidadIA(['sesiones' => [$this->sesion('Pilas', ['Pilas'], ['duracion' => ''])]])), json_encode($this->unidadIA()));
        $this->post(route('unidades.preparar', $this->materia), $this->formulario(['unidad_id' => null, 'tema' => 'Pilas y colas']))->assertOk()->assertSee('Pilas y colas');
        $this->post(route('unidades.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();

        $this->get(route('unidades.borrador', $g))->assertSee('entre 10 y 600 minutos')->assertSee('Pedir a la IA que la corrija');
        $this->post(route('unidades.aprobar', $g))->assertSessionHas('error_ia');
        $this->assertSame(0, UnidadDidactica::query()->count());

        $this->post(route('unidades.corregir', $g))->assertRedirect();
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->assertSame('descartado', $g->fresh()->estado);
        $this->assertSame([], app(GeneradorUnidad::class)->errores($nueva->borrador));

        $this->put(route('unidades.borrador.editar', $nueva), ['unidad' => json_encode($this->unidadIA(['titulo' => 'Pilas y colas en la práctica'])),
            'temario_item_id' => $this->unidad->id, 'aprobar' => '1'])->assertRedirect();
        $u = UnidadDidactica::query()->sole();
        $this->assertSame('Pilas y colas en la práctica', $u->titulo);
        $this->assertSame($this->unidad->id, $u->temario_item_id);
    }

    #[Test]
    public function la_unidad_a_mano_arranca_con_una_sesion_por_tema(): void
    {
        $this->get(route('unidades.create', ['materia' => $this->materia, 'unidad' => $this->unidad->id]))->assertOk()
            ->assertSee('Agregar sesión')->assertSee('\\u0022temas\\u0022:[\\u0022Pilas\\u0022]', false)->assertSee('Implementa estructuras lineales con arreglos');

        $this->post(route('unidades.store', $this->materia), ['unidad' => json_encode($this->unidadIA(['sesiones' => []])), 'temario_item_id' => $this->unidad->id])
            ->assertSessionHas('observaciones');
        $this->post(route('unidades.store', $this->materia), ['unidad' => json_encode($this->unidadIA()), 'temario_item_id' => $this->unidad->id])->assertRedirect();
        $u = UnidadDidactica::query()->sole();
        $this->assertNull($u->generacion_ia_id);

        $this->put(route('unidades.update', $u), ['unidad' => json_encode($this->unidadIA(['titulo' => 'Otra'])), 'temario_item_id' => ''])->assertRedirect(route('unidades.show', $u));
        $this->assertSame('Otra', $u->fresh()->titulo);
        $this->assertNull($u->fresh()->temario_item_id);
        $this->get(route('unidades.index', $this->materia))->assertSee('Otra');

        $this->delete(route('unidades.destroy', $u))->assertRedirect();
        $this->assertSame(0, UnidadDidactica::query()->count());
    }

    #[Test]
    public function la_plantilla_es_editable_y_otro_docente_no_ve_nada(): void
    {
        $this->get(route('ia.plantillas'))->assertSee('Capa 3 — Tarea: planear una unidad didáctica');
        $this->post(route('ia.plantillas.guardar'), ['clave' => 'unidad', 'cuerpo' => 'Plan de {{unidad}} en {{sesiones}} clases'])->assertRedirect();
        $this->post(route('unidades.preparar', $this->materia), $this->formulario(['sesiones' => 6]))->assertSee('Plan de Unidad 2: Estructuras lineales en 6 clases');

        $u = UnidadDidactica::query()->create(['materia_id' => $this->materia->id, 'titulo' => 'X', 'contenido' => app(UnidadesDidacticas::class)->normalizar($this->unidadIA())]);
        $this->actingAs(User::factory()->create());
        $this->get(route('unidades.index', $this->materia))->assertForbidden();
        $this->get(route('unidades.show', $u))->assertForbidden();
        $this->delete(route('unidades.destroy', $u))->assertForbidden();
    }

    #[Test]
    public function un_borrador_de_otra_tarea_no_se_abre_como_unidad(): void
    {
        $g = GeneracionIa::query()->create(['motor_ia_id' => $this->motor->id, 'user_id' => $this->docente->id, 'materia_id' => $this->materia->id,
            'tarea' => 'rubrica', 'prompt_final' => 'x', 'respuesta' => '{}', 'borrador' => '{}']);
        $this->get(route('unidades.borrador', $g))->assertNotFound();
        $this->post(route('unidades.aprobar', $g))->assertNotFound();
    }
}
