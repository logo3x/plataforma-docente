<?php

namespace Tests\Feature;

use App\Evaluacion\GeneradorRubrica;
use App\Evaluacion\Rubricas;
use App\Livewire\CalificarAbiertas;
use App\Models\ArtefactoPregunta;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Respuesta;
use App\Models\Rubrica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Generador de rúbricas (7.4) y calificación de abiertas con rúbrica (6.7.11). */
class RubricasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private ArtefactoPregunta $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        [, $items] = $this->crearArtefacto([['abierta', ['referencia' => 'Un requisito es lo que debe hacer; una restricción limita cómo.'], '2']]);
        $this->item = $items[0];
        $this->item->pregunta->update(['enunciado' => ['texto' => 'Explique la diferencia entre requisito y restricción.']]);
    }

    /** Rúbrica válida: 60 % + 40 %, niveles 100/50/0. */
    private function rubrica(array $cambios = []): array
    {
        $niveles = [['nombre' => 'Excelente', 'descripcion' => 'Distingue con un ejemplo propio', 'porcentaje' => '100'],
            ['nombre' => 'Básico', 'descripcion' => 'Distingue sin ejemplo', 'porcentaje' => '50'],
            ['nombre' => 'Insuficiente', 'descripcion' => 'Los confunde', 'porcentaje' => '0']];

        return array_replace(['titulo' => 'Requisitos y restricciones', 'proposito' => 'Pregunta 4 del parcial', 'criterios' => [
            ['nombre' => 'Precisión conceptual', 'descripcion' => '', 'peso' => '60', 'niveles' => $niveles],
            ['nombre' => 'Ejemplo', 'descripcion' => '', 'peso' => '40', 'niveles' => $niveles],
        ]], $cambios);
    }

    private function ollamaResponde(string ...$respuestas): void
    {
        $cola = Http::sequence();
        foreach ($respuestas as $r) {
            $cola->push(['message' => ['content' => $r], 'prompt_eval_count' => 500, 'eval_count' => 300]);
        }
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]), 'ollama.test:11434/api/chat' => $cola]);
    }

    private function respuesta(string $texto = 'Requisito: qué hace. Restricción: cómo.'): Respuesta
    {
        $this->actividad ??= $this->crearActividad(null, ['artefacto_id' => $this->item->artefacto_id]);
        $e = $this->crearEstudiante((string) random_int(2000, 9999), 'PEREZ', 'ANA');

        return Respuesta::query()->create(['entrega_id' => $this->crearEntrega($this->actividad, $e)->id, 'artefacto_pregunta_id' => $this->item->id,
            'respuesta' => ['valor' => $texto], 'puntaje_max' => '2']);
    }

    private $actividad = null;

    #[Test]
    public function valida_pesos_niveles_y_que_se_pueda_obtener_la_nota_completa(): void
    {
        $s = app(Rubricas::class);
        $this->assertSame([], $s->validar($this->rubrica()));

        $mal = $this->rubrica();
        $mal['criterios'][1]['peso'] = '30';
        $mal['criterios'][0]['niveles'][0]['porcentaje'] = '90';
        $mal['criterios'][1]['niveles'][2]['porcentaje'] = '50';
        $errores = implode(' | ', $s->validar($mal));
        $this->assertStringContainsString('suman 90 %', $errores);
        $this->assertStringContainsString('el nivel más alto debe otorgar el 100 %', $errores);
        $this->assertStringContainsString('dos niveles otorgan el mismo porcentaje (50 %)', $errores);

        // Los niveles quedan de mayor a menor y las filas vacías del editor se ignoran
        $desordenada = $this->rubrica();
        $desordenada['criterios'][0]['niveles'] = array_reverse($desordenada['criterios'][0]['niveles']);
        $desordenada['criterios'][] = ['nombre' => '', 'peso' => '', 'niveles' => []];
        $n = $s->normalizar($desordenada);
        $this->assertCount(2, $n['criterios']);
        $this->assertSame('Excelente', $n['criterios'][0]['niveles'][0]['nombre']);
    }

    #[Test]
    public function el_puntaje_sale_de_los_niveles_y_no_de_la_ia(): void
    {
        $s = app(Rubricas::class);
        $c = $this->rubrica()['criterios'];
        // 2 × (60 × 100 + 40 × 50) / 10 000 = 1.6
        $this->assertSame('1.6000', $s->puntaje($c, [0, 1], '2'));
        $this->assertSame('0.0000', $s->puntaje($c, [2, 2], '2'));
        $this->assertNull($s->puntaje($c, [0, null], '2'), 'Con un criterio sin evaluar no hay puntaje');
    }

    #[Test]
    public function la_rubrica_a_mano_se_guarda_y_se_asocia_a_la_pregunta(): void
    {
        $this->get(route('rubricas.create', $this->materia))->assertOk()->assertSee('Repartir los pesos por igual');

        $mal = $this->rubrica();
        $mal['criterios'][0]['peso'] = '10';
        $this->post(route('rubricas.store', $this->materia), ['rubrica' => json_encode($mal), 'preguntas' => [$this->item->pregunta_id]])
            ->assertSessionHas('observaciones');
        $this->assertSame(0, Rubrica::query()->count());

        $this->post(route('rubricas.store', $this->materia), ['rubrica' => json_encode($this->rubrica()), 'preguntas' => [$this->item->pregunta_id]])
            ->assertRedirect(route('rubricas.index', $this->materia));
        $r = Rubrica::query()->sole();
        $this->assertSame($r->id, $this->item->pregunta->fresh()->rubrica_id);

        // Editar sin marcar la pregunta la desasocia
        $this->put(route('rubricas.update', $r), ['rubrica' => json_encode($this->rubrica(['titulo' => 'Otra'])), 'preguntas' => []])->assertRedirect();
        $this->assertSame('Otra', $r->fresh()->titulo);
        $this->assertNull($this->item->pregunta->fresh()->rubrica_id);
        $this->get(route('rubricas.index', $this->materia))->assertOk()->assertSee('Otra')->assertSee('Precisión conceptual');
    }

    #[Test]
    public function genera_la_rubrica_de_una_pregunta_con_su_referencia_la_revisa_y_la_aprueba(): void
    {
        $this->ollamaResponde("Aquí está:\n```json\n".json_encode($this->rubrica() + ['formato' => 'otro', 'pregunta_id' => 999])."\n```");

        $this->get(route('rubricas.index', $this->materia))->assertOk()->assertSee('Generar una rúbrica con IA');
        $prompt = $this->post(route('rubricas.preparar', $this->materia), [
            'dictado_id' => $this->dictado->id, 'pregunta_id' => $this->item->pregunta_id, 'criterios' => 3, 'niveles' => 'Logrado, En proceso, No logrado', 'motor_id' => $this->motor->id,
        ])->assertOk();
        $prompt->assertSee('Explique la diferencia entre requisito y restricción.')
            ->assertSee('Un requisito es lo que debe hacer')
            ->assertSee('Logrado, En proceso, No logrado')
            ->assertSee(route('rubricas.generar', $this->materia), false);

        $this->post(route('rubricas.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U editado', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('rubricas.borrador', $g = GeneracionIa::query()->sole()));
        $this->assertSame('rubrica', $g->tarea);
        $b = json_decode($g->borrador, true);
        $this->assertSame('plataforma-rubrica', $b['formato']);
        $this->assertSame($this->item->pregunta_id, $b['pregunta_id'], 'La pregunta la fija la plataforma, no la IA');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U editado'));

        $this->get(route('rubricas.borrador', $g))->assertOk()->assertSee('El borrador es válido');
        $this->post(route('rubricas.aprobar', $g))->assertRedirect(route('rubricas.index', $this->materia));

        $r = Rubrica::query()->sole();
        $this->assertSame($g->id, $r->generacion_ia_id);
        $this->assertSame($r->id, $this->item->pregunta->fresh()->rubrica_id);
        $this->assertSame('aprobado', $g->fresh()->estado);
        $this->assertSame(Rubrica::class, $g->fresh()->resultado_type);
    }

    #[Test]
    public function un_borrador_con_errores_se_corrige_con_la_ia_o_a_mano_y_no_se_aprueba_mientras_tanto(): void
    {
        $mala = $this->rubrica();
        $mala['criterios'][0]['peso'] = '50';
        $this->ollamaResponde(json_encode($mala), json_encode($this->rubrica()));

        $this->post(route('rubricas.preparar', $this->materia), ['dictado_id' => $this->dictado->id, 'proposito' => 'Sustentación del proyecto', 'criterios' => 2, 'motor_id' => $this->motor->id])->assertOk();
        $this->post(route('rubricas.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();
        $this->get(route('rubricas.borrador', $g))->assertSee('suman 90 %')->assertSee('Pedir a la IA que la corrija');
        $this->post(route('rubricas.aprobar', $g))->assertSessionHas('error_ia');
        $this->assertSame(0, Rubrica::query()->count());

        $this->post(route('rubricas.corregir', $g))->assertRedirect();
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->assertSame('rubrica', $nueva->tarea);
        $this->assertSame('descartado', $g->fresh()->estado);
        $this->assertSame([], app(GeneradorRubrica::class)->errores($nueva->borrador));

        // Edición a mano del borrador y aprobación en el mismo paso
        $this->put(route('rubricas.borrador.editar', $nueva), ['rubrica' => json_encode($this->rubrica(['titulo' => 'Sustentación'])), 'preguntas' => [''], 'aprobar' => '1'])
            ->assertRedirect(route('rubricas.index', $this->materia));
        $this->assertSame('Sustentación', Rubrica::query()->sole()->titulo);
        $this->assertNull($this->item->pregunta->fresh()->rubrica_id);
    }

    #[Test]
    public function se_califica_eligiendo_niveles_y_el_informe_conserva_los_criterios(): void
    {
        $rub = Rubrica::query()->create(['materia_id' => $this->materia->id] + app(Rubricas::class)->normalizar($this->rubrica()));
        $this->item->pregunta->update(['rubrica_id' => $rub->id]);
        $resp = $this->respuesta();

        $lw = Livewire::test(CalificarAbiertas::class, ['actividad' => $this->actividad])
            ->assertSee('Precisión conceptual')->assertDontSee('>0.5<', false);
        $lw->call('nivel', $resp->id, 0, 0);
        $this->assertNull($resp->fresh()->puntaje, 'Falta un criterio: sigue pendiente');
        $lw->assertSee('Faltan criterios por evaluar');

        $lw->call('nivel', $resp->id, 1, 1);
        $this->assertSame('1.6000', $resp->fresh()->puntaje);
        $this->assertSame('docente', $resp->fresh()->calificado_por);

        // Editar después la rúbrica no cambia lo que ve el estudiante
        $rub->update(['criterios' => app(Rubricas::class)->normalizar($this->rubrica(['criterios' => [['nombre' => 'Nuevo', 'peso' => '100', 'niveles' => [['nombre' => 'Sí', 'porcentaje' => '100'], ['nombre' => 'No', 'porcentaje' => '0']]]]]))['criterios']]);
        $this->get(route('calificador.detalle', $resp->entrega))->assertOk()
            ->assertSee('Calificada con la rúbrica «Requisitos y restricciones»')
            ->assertSee('Distingue sin ejemplo')
            ->assertSee('0.4 / 0.8')
            ->assertDontSee('Nuevo');

        // Un número escrito a mano reemplaza la rúbrica
        $lw->call('puntuar', $resp->id, '1.9');
        $this->assertSame('1.9000', $resp->fresh()->puntaje);
        $this->assertNull($resp->fresh()->desglose);
    }

    #[Test]
    public function desde_el_calificador_se_asocia_o_quita_la_rubrica(): void
    {
        $rub = Rubrica::query()->create(['materia_id' => $this->materia->id] + app(Rubricas::class)->normalizar($this->rubrica()));
        $this->respuesta();
        Livewire::test(CalificarAbiertas::class, ['actividad' => $this->actividad])
            ->assertSee('Generar una con IA')
            ->call('asociarRubrica', $rub->id);
        $this->assertSame($rub->id, $this->item->pregunta->fresh()->rubrica_id);

        Livewire::test(CalificarAbiertas::class, ['actividad' => $this->actividad])->call('asociarRubrica', null);
        $this->assertNull($this->item->pregunta->fresh()->rubrica_id);
    }

    #[Test]
    public function la_plantilla_del_generador_es_editable_y_otro_docente_no_ve_nada(): void
    {
        $this->get(route('ia.plantillas'))->assertOk()->assertSee('Capa 3 — Tarea: generar rúbrica');
        $this->post(route('ia.plantillas.guardar'), ['clave' => 'rubrica', 'cuerpo' => 'Rúbrica para {{actividad}} con {{criterios}} criterios'])->assertRedirect();
        $p = $this->post(route('rubricas.preparar', $this->materia), ['dictado_id' => $this->dictado->id, 'proposito' => 'el ensayo final', 'criterios' => 5, 'motor_id' => $this->motor->id]);
        $p->assertSee('Rúbrica para el ensayo final con 5 criterios');

        $rub = Rubrica::query()->create(['materia_id' => $this->materia->id] + app(Rubricas::class)->normalizar($this->rubrica()));
        $this->actingAs(User::factory()->create());
        $this->get(route('rubricas.index', $this->materia))->assertForbidden();
        $this->get(route('rubricas.edit', $rub))->assertForbidden();
        $this->delete(route('rubricas.destroy', $rub))->assertForbidden();
    }
}
