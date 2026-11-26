<?php

namespace Tests\Feature;

use App\Material\EjemplosPracticos;
use App\Material\GeneradorEjemplos;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Material;
use App\Models\TemarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Generador «Ejemplos prácticos» (7.4): resueltos paso a paso, con el código verificable en el navegador. */
class EjemplosPracticosTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $pilas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);           // Estructura de Datos: perfil de código y práctica integrada
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        $u = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'unidad', 'titulo' => 'Estructuras lineales']);
        $this->pilas = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'parent_id' => $u->id, 'tipo' => 'tema', 'titulo' => 'Pilas', 'descripcion' => 'LIFO']);
    }

    private function ejemplo(array $cambios = []): array
    {
        return array_replace(['titulo' => 'Invertir una palabra', 'tema' => 'pilas', 'dificultad' => 'baja',
            'enunciado' => 'Un cajero apila los recibos del día. ¿Cómo leerlos del último al primero?',
            'pasos' => [['explicacion' => 'Apilamos cada letra.', 'codigo' => "const pila = [];\nfor (const c of t) pila.push(c);"],
                ['explicacion' => 'Sacamos hasta vaciar.', 'codigo' => '']],
            'respuesta' => 'invertir("hola") devuelve "aloh".', 'errores' => ['Usar shift en lugar de pop'], 'practica' => 'Verifique si una palabra es palíndromo.',
            'codigo' => "function invertir(t) {\n  const p = [];\n  for (const c of t) p.push(c);\n  let r = '';\n  while (p.length) r += p.pop();\n  return r;\n}",
            'comprobacion' => 'invertir("hola") === "aloh"'], $cambios);
    }

    private function conjunto(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-ejemplos', 'version' => 1, 'titulo' => 'Pilas en problemas cotidianos', 'proposito' => 'Usar una pila.',
            'ejemplos' => [$this->ejemplo()]], $cambios);
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
        return array_replace(['dictado_id' => $this->dictado->id, 'temas' => [$this->pilas->id], 'cantidad' => 3, 'dificultad' => 'mixta',
            'contexto' => 'una planta de energía', 'grupo' => 'Diurno', 'motor_id' => $this->motor->id], $cambios);
    }

    #[Test]
    public function valida_los_pasos_y_conserva_la_sangria_del_codigo(): void
    {
        $s = app(EjemplosPracticos::class);
        $this->assertSame([], $s->errores($this->conjunto()));
        $this->assertStringContainsString("\n  const p = [];", $s->normalizar($this->conjunto())['ejemplos'][0]['codigo']);

        $malo = $this->conjunto(['ejemplos' => [$this->ejemplo(['respuesta' => '', 'dificultad' => 'extrema', 'codigo' => '',
            'pasos' => [['explicacion' => '', 'codigo' => 'x()']]])]]);
        $e = implode(' | ', $s->errores($malo));
        foreach (['falta la respuesta final', 'al menos dos pasos', 'falta la explicación', 'baja, media o alta', 'tiene comprobación pero no el código'] as $f) {
            $this->assertStringContainsString($f, $e);
        }

        $a = $s->advertencias($this->conjunto(['ejemplos' => [$this->ejemplo(['tema' => 'Grafos', 'comprobacion' => ''])]]), $this->materia);
        $this->assertStringContainsString('«Grafos», que no está en el temario', $a[0]);
        $this->assertStringContainsString('código sin comprobación', $a[1]);
        $this->assertCount(1, $s->verificables($this->conjunto()));
    }

    #[Test]
    public function genera_ejemplos_de_los_temas_los_verifica_y_los_aprueba(): void
    {
        $this->ollamaResponde("```json\n".json_encode($this->conjunto())."\n```");

        $this->get(route('ejemplos.index', ['materia' => $this->materia, 'temas' => [$this->pilas->id]]))->assertOk()
            ->assertSee('value="'.$this->pilas->id.'" checked', false);
        $this->post(route('ejemplos.preparar', $this->materia), $this->formulario())->assertOk()
            ->assertSee('Pilas (LIFO)')->assertSee('3 ejemplos')->assertSee('una planta de energía')
            ->assertSee('escriba el código en JavaScript')
            ->assertSee(route('ejemplos.generar', $this->materia), false);

        $this->post(route('ejemplos.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('ejemplos.borrador', $g = GeneracionIa::query()->sole()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $this->assertSame('ejemplos', $g->tarea);

        $this->get(route('ejemplos.borrador', $g))->assertOk()->assertSee('El formato es válido')->assertSee('Verificar el código en el navegador')->assertSee('js/ejecutor.js');
        $this->post(route('ejemplos.aprobar', $g))->assertRedirect();

        $m = Material::query()->sole();
        $this->assertSame('ejemplos', $m->tipo);
        $this->assertSame($g->id, $m->generacion_ia_id);

        $this->get(route('ejemplos.show', $m))->assertOk()->assertSee('Apilamos cada letra.')->assertSee('Usar shift en lugar de pop')
            ->assertSee('Verificando el código en el navegador');
        // La versión para practicar no muestra pasos, respuesta ni errores
        $this->get(route('ejemplos.show', ['material' => $m, 'version' => 'estudiante']))->assertOk()
            ->assertSee('Un cajero apila los recibos')->assertSee('Verifique si una palabra es palíndromo')
            ->assertDontSee('Apilamos cada letra.')->assertDontSee('devuelve')->assertDontSee('Usar shift')->assertDontSee('Verificando el código');
    }

    #[Test]
    public function las_comprobaciones_que_fallan_en_el_navegador_vuelven_a_la_ia(): void
    {
        $this->ollamaResponde(json_encode($this->conjunto()), json_encode($this->conjunto(['titulo' => 'Corregido'])));
        $this->post(route('ejemplos.preparar', $this->materia), $this->formulario());
        $this->post(route('ejemplos.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();

        $this->post(route('ejemplos.corregir', $g), ['fallidos' => json_encode([['numero' => 1, 'error' => 'invertir is not defined']])])->assertRedirect();
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'La comprobación del ejemplo 1 no dio true')
            && str_contains($r['messages'][1]['content'], 'invertir is not defined'));
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->assertSame('ejemplos', $nueva->tarea);
        $this->assertSame('Corregido', json_decode($nueva->borrador, true)['titulo']);
        $this->assertSame('descartado', $g->fresh()->estado);
    }

    #[Test]
    public function un_borrador_invalido_no_se_aprueba_y_se_arregla_a_mano(): void
    {
        $this->ollamaResponde(json_encode($this->conjunto(['ejemplos' => [$this->ejemplo(['pasos' => []])]])));
        $this->post(route('ejemplos.preparar', $this->materia), $this->formulario(['temas' => [], 'tema' => 'Recursión']))->assertOk()->assertSee('Recursión');
        $this->post(route('ejemplos.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();

        $this->get(route('ejemplos.borrador', $g))->assertSee('al menos dos pasos');
        $this->post(route('ejemplos.aprobar', $g))->assertSessionHas('error_ia');
        $this->put(route('ejemplos.borrador.editar', $g), ['contenido' => json_encode($this->conjunto(['titulo' => 'Arreglado'])), 'aprobar' => '1'])->assertRedirect();
        $this->assertSame('Arreglado', Material::query()->sole()->titulo);
        $this->assertSame([], app(GeneradorEjemplos::class)->errores(GeneracionIa::query()->sole()->borrador));
    }

    #[Test]
    public function a_mano_se_crean_editan_y_eliminan(): void
    {
        $this->get(route('ejemplos.create', ['materia' => $this->materia, 'temas' => [$this->pilas->id]]))->assertOk()
            ->assertSee('Agregar ejemplo')->assertSee('\\u0022tema\\u0022:\\u0022Pilas\\u0022', false);
        $this->post(route('ejemplos.store', $this->materia), ['contenido' => json_encode($this->conjunto(['titulo' => '']))])->assertSessionHas('observaciones');
        $this->post(route('ejemplos.store', $this->materia), ['contenido' => json_encode($this->conjunto())])->assertRedirect();
        $m = Material::query()->sole();
        $this->assertNull($m->generacion_ia_id);

        $this->put(route('ejemplos.update', $m), ['contenido' => json_encode($this->conjunto(['titulo' => 'Otro']))])->assertRedirect(route('ejemplos.show', $m));
        $this->get(route('ejemplos.index', $this->materia))->assertSee('Otro')->assertSee('pilas');
        $this->delete(route('ejemplos.destroy', $m))->assertRedirect();
        $this->assertSame(0, Material::query()->count());
    }

    #[Test]
    public function sin_practica_de_codigo_no_se_pide_codigo_ni_se_verifica(): void
    {
        $this->materia->update(['perfil_codigo' => false, 'modalidad_practica' => 'ninguna']);
        $this->post(route('ejemplos.preparar', $this->materia), $this->formulario())->assertSee('no verifica código en el navegador');
        $this->get(route('ejemplos.create', $this->materia))->assertDontSee('Verificar el código en el navegador')->assertDontSee('Código final y comprobación');
    }

    #[Test]
    public function la_plantilla_es_editable_y_otro_docente_no_ve_nada(): void
    {
        $this->get(route('ia.plantillas'))->assertSee('Capa 3 — Tarea: ejemplos prácticos resueltos');
        $this->post(route('ia.plantillas.guardar'), ['clave' => 'ejemplos', 'cuerpo' => '{{cantidad}} ejemplos de {{tema}}'])->assertRedirect();
        $this->post(route('ejemplos.preparar', $this->materia), $this->formulario(['cantidad' => 4]))->assertSee('4 ejemplos de Pilas (LIFO)');

        $m = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'ejemplos', 'titulo' => 'X', 'contenido' => app(EjemplosPracticos::class)->normalizar($this->conjunto())]);
        $otro = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'debate', 'titulo' => 'Y', 'contenido' => []]);
        $this->get(route('ejemplos.show', $otro))->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get(route('ejemplos.index', $this->materia))->assertForbidden();
        $this->get(route('ejemplos.show', $m))->assertForbidden();
        $this->delete(route('ejemplos.destroy', $m))->assertForbidden();
    }
}
