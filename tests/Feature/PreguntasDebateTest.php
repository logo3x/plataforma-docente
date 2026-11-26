<?php

namespace Tests\Feature;

use App\Material\PreguntasDebate;
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

/** Generador «Preguntas de debate» (7.4): guía del docente, versión para estudiantes y modo proyector. */
class PreguntasDebateTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $datos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        $u = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'unidad', 'titulo' => 'Ética profesional']);
        $this->datos = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'parent_id' => $u->id, 'tipo' => 'tema', 'titulo' => 'Privacidad de datos']);
    }

    private function pregunta(array $cambios = []): array
    {
        return array_replace(['pregunta' => '¿Debe una app de salud vender datos anonimizados para financiarse?', 'tema' => 'privacidad de datos',
            'enfoque' => 'dilema', 'contexto' => 'Una startup de Barrancabermeja ofrece su app gratis a cambio de los datos.', 'dinamica' => 'grupos', 'minutos' => '20',
            'posturas' => [['postura' => 'Sí, con consentimiento', 'argumentos' => ['Sostiene el servicio gratuito']],
                ['postura' => 'No', 'argumentos' => ['La anonimización se puede revertir']]],
            'seguimiento' => ['¿Qué cambia si los datos son de menores?'], 'cierre' => 'El consentimiento informado y la minimización de datos.'], $cambios);
    }

    private function conjunto(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-debate', 'version' => 1, 'titulo' => 'Datos y ética', 'proposito' => 'Argumentar con los principios de privacidad.',
            'preguntas' => [$this->pregunta()]], $cambios);
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
        return array_replace(['dictado_id' => $this->dictado->id, 'temas' => [$this->datos->id], 'cantidad' => 4, 'enfoque' => 'mixto', 'dinamica' => 'parejas',
            'minutos' => 15, 'contexto' => 'el sector energético', 'grupo' => 'Diurno', 'motor_id' => $this->motor->id], $cambios);
    }

    #[Test]
    public function exige_posturas_y_cierre_y_advierte_las_preguntas_de_respuesta_unica(): void
    {
        $s = app(PreguntasDebate::class);
        $this->assertSame([], $s->errores($this->conjunto()));

        $malo = $this->conjunto(['preguntas' => [$this->pregunta(['cierre' => '', 'minutos' => '2', 'dinamica' => 'mesa redonda',
            'posturas' => [['postura' => 'Sí', 'argumentos' => []]]])]]);
        $e = implode(' | ', $s->errores($malo));
        foreach (['idea que debe quedar al cierre', 'al menos dos posturas', 'al menos un argumento', 'la dinámica debe ser', 'entre 5 y 120 minutos'] as $f) {
            $this->assertStringContainsString($f, $e);
        }

        $a = $s->advertencias($this->conjunto(['preguntas' => [$this->pregunta(['pregunta' => '¿Qué es la anonimización?', 'tema' => 'Blockchain', 'minutos' => '120']),
            $this->pregunta(['minutos' => '90'])]]), $this->materia);
        $a = implode(' | ', $a);
        $this->assertStringContainsString('«Blockchain», que no está en el temario', $a);
        $this->assertStringContainsString('parece tener una sola respuesta correcta', $a);
        $this->assertStringContainsString('suman 210 minutos', $a);
        $this->assertSame([], $s->advertencias($this->conjunto(), $this->materia));
    }

    #[Test]
    public function genera_revisa_y_aprueba_las_preguntas(): void
    {
        $this->ollamaResponde("```json\n".json_encode($this->conjunto())."\n```");
        $this->get(route('debates.index', ['materia' => $this->materia, 'temas' => [$this->datos->id]]))->assertOk()
            ->assertSee('value="'.$this->datos->id.'" checked', false);
        $this->post(route('debates.preparar', $this->materia), $this->formulario())->assertOk()
            ->assertSee('Privacidad de datos')->assertSee('4 preguntas')->assertSee('parejas')->assertSee('unos 15 minutos')
            ->assertSee('el sector energético')->assertSee(route('debates.generar', $this->materia), false);

        $this->post(route('debates.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('debates.borrador', $g = GeneracionIa::query()->sole()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $this->assertSame('debate', $g->tarea);

        $this->get(route('debates.borrador', $g))->assertOk()->assertSee('El formato es válido');
        $this->post(route('debates.aprobar', $g))->assertRedirect();
        $m = Material::query()->sole();
        $this->assertSame('debate', $m->tipo);

        // La guía del docente trae posturas y cierre; la versión para estudiantes y el proyector, no
        $this->get(route('debates.show', $m))->assertOk()->assertSee('La anonimización se puede revertir')->assertSee('Al cierre debe quedar')
            ->assertSee('Proyectar en clase')->assertSee('proyector-pregunta', false);
        $estudiante = $this->get(route('debates.show', ['material' => $m, 'version' => 'estudiante']))->assertOk()
            ->assertSee('Una startup de Barrancabermeja')->assertDontSee('La anonimización se puede revertir')->assertDontSee('Al cierre debe quedar')
            ->assertDontSee('¿Qué cambia si los datos son de menores?')->getContent();
        $proyector = substr($estudiante, strpos($estudiante, 'class="proyector"'));
        $this->assertStringNotContainsString('Sostiene el servicio gratuito', $proyector);
    }

    #[Test]
    public function un_borrador_invalido_se_corrige_con_la_ia_o_a_mano(): void
    {
        $this->ollamaResponde(json_encode($this->conjunto(['preguntas' => [$this->pregunta(['posturas' => []])]])), json_encode($this->conjunto(['titulo' => 'Corregido'])));
        $this->post(route('debates.preparar', $this->materia), $this->formulario(['temas' => [], 'tema' => 'Software libre']))->assertOk()->assertSee('Software libre');
        $this->post(route('debates.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();

        $this->get(route('debates.borrador', $g))->assertSee('al menos dos posturas')->assertSee('Pedir a la IA que las corrija');
        $this->post(route('debates.aprobar', $g))->assertSessionHas('error_ia');
        $this->post(route('debates.corregir', $g))->assertRedirect();
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->assertSame('debate', $nueva->tarea);
        $this->assertSame('Corregido', json_decode($nueva->borrador, true)['titulo']);

        $this->put(route('debates.borrador.editar', $nueva), ['contenido' => json_encode($this->conjunto(['titulo' => 'A mano'])), 'aprobar' => '1'])->assertRedirect();
        $this->assertSame('A mano', Material::query()->sole()->titulo);
    }

    #[Test]
    public function a_mano_se_crean_editan_y_eliminan_y_otro_docente_no_ve_nada(): void
    {
        $this->get(route('debates.create', ['materia' => $this->materia, 'temas' => [$this->datos->id]]))->assertOk()
            ->assertSee('Agregar pregunta')->assertSee('\\u0022tema\\u0022:\\u0022Privacidad de datos\\u0022', false);
        $this->post(route('debates.store', $this->materia), ['contenido' => json_encode($this->conjunto(['titulo' => '']))])->assertSessionHas('observaciones');
        $this->post(route('debates.store', $this->materia), ['contenido' => json_encode($this->conjunto())])->assertRedirect();
        $m = Material::query()->sole();
        $this->put(route('debates.update', $m), ['contenido' => json_encode($this->conjunto(['titulo' => 'Otro']))])->assertRedirect(route('debates.show', $m));
        $this->get(route('debates.index', $this->materia))->assertSee('Otro')->assertSee('privacidad de datos');

        // Un material de otro tipo no se abre como debate, ni un ejemplo como debate
        $this->get(route('ejemplos.show', $m))->assertNotFound();

        $this->get(route('ia.plantillas'))->assertSee('Capa 3 — Tarea: preguntas de debate');
        $this->actingAs(User::factory()->create());
        $this->get(route('debates.index', $this->materia))->assertForbidden();
        $this->get(route('debates.show', $m))->assertForbidden();
        $this->delete(route('debates.destroy', $m))->assertForbidden();
        $this->actingAs($this->docente)->delete(route('debates.destroy', $m))->assertRedirect();
        $this->assertSame(0, Material::query()->count());
    }
}
