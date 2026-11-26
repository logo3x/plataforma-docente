<?php

namespace Tests\Feature;

use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\Rubricas;
use App\Material\PreguntasDebate;
use App\Models\Artefacto;
use App\Models\ConfigMotorIa;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Material;
use App\Models\Rubrica;
use App\Models\UnidadDidactica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Historial de actividades de la materia y del periodo (7.3): origen, periodos, reutilización. */
class HistorialTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Dictado $siguiente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-02-01 08:00');
        $this->crearEscenario(estudiantes: 0);                     // dictado 2026A
        $this->actingAs($this->docente);

        // Creado en 2026A: un banco aplicado y una rúbrica generada con IA a partir de un material
        Carbon::setTestNow('2026-03-01 08:00');
        $banco = app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-HIS-Q1', 'titulo' => 'Quiz de pilas',
            'valor' => '5', 'items' => [['tipo' => 'vf', 'peso' => '5', 'enunciado' => 'Una pila es LIFO.', 'correcta' => true]]]);
        $act = $this->parcial->actividades()->create(['artefacto_id' => $banco->id, 'nombre' => 'Quiz de pilas', 'valor_max' => '5']);
        $act->grupos()->attach($this->grupo->id);

        $motor = ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama', 'base_url' => 'http://x', 'modelo' => 'q', 'es_local' => true]);
        $r = Rubrica::query()->create(['materia_id' => $this->materia->id, 'titulo' => 'Rúbrica de sustentación', 'criterios' => [
            ['nombre' => 'Claridad', 'descripcion' => '', 'peso' => '100', 'niveles' => [['nombre' => 'Sí', 'descripcion' => '', 'porcentaje' => '100'], ['nombre' => 'No', 'descripcion' => '', 'porcentaje' => '0']]]]]);
        $g = GeneracionIa::query()->create(['motor_ia_id' => $motor->id, 'user_id' => $this->docente->id, 'materia_id' => $this->materia->id, 'tarea' => 'rubrica',
            'prompt_final' => 'x', 'respuesta' => '{}', 'estado' => 'aprobado', 'resultado_type' => $r->getMorphClass(), 'resultado_id' => $r->id,
            'contenidos_contexto' => [['id' => 1, 'titulo' => 'Guía de la asignatura', 'sha256' => 'x', 'caracteres' => 10]]]);
        $r->update(['generacion_ia_id' => $g->id]);

        // Periodo siguiente: hereda todo; aquí se crea un debate a mano
        Carbon::setTestNow('2026-08-01 08:00');
        $this->siguiente = Dictado::query()->create(['materia_id' => $this->materia->id, 'periodo' => '2026B', 'origen_dictado_id' => $this->dictado->id]);
        Carbon::setTestNow('2026-08-10 08:00');
        Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'debate', 'titulo' => 'Debate de colas', 'contenido' => app(PreguntasDebate::class)->normalizar([
            'titulo' => 'Debate de colas', 'preguntas' => [['pregunta' => '¿Es justo atender por orden de llegada?', 'enfoque' => 'dilema', 'dinamica' => 'grupos', 'minutos' => '15', 'cierre' => 'Depende del costo.',
                'posturas' => [['postura' => 'Sí', 'argumentos' => ['Equidad']], ['postura' => 'No', 'argumentos' => ['Urgencias']]]]]])]);
        UnidadDidactica::query()->create(['materia_id' => $this->materia->id, 'titulo' => 'Unidad de colas', 'contenido' => ['titulo' => 'Unidad de colas', 'sesiones' => [['titulo' => 'S1', 'objetivo' => 'O', 'desarrollo' => 'D', 'duracion' => '60']]]]);
        Carbon::setTestNow();
    }

    #[Test]
    public function muestra_todo_con_su_origen_y_los_periodos_en_que_se_creo_y_se_aplico(): void
    {
        $r = $this->get(route('historial.index', $this->materia))->assertOk()
            ->assertSee('Quiz de pilas')->assertSee('ED-HIS-Q1')->assertSee('Aplicada en:')->assertSee('2026A › Corte 1 (Diurno)')
            ->assertSee('Rúbrica de sustentación')->assertSee('con IA (Ollama local), a partir de: Guía de la asignatura')
            ->assertSee('Debate de colas')->assertSee('Unidad de colas')
            ->assertSee('Evaluación · 1')->assertSee('Rúbrica · 1')->assertSee('Preguntas de debate · 1');
        $html = $r->getContent();
        $this->assertLessThan(strpos($html, 'Quiz de pilas'), strpos($html, 'Debate de colas'), 'Lo más reciente va primero');
        $this->assertStringContainsString('(periodo 2026B)', $html);
        $this->assertStringContainsString('(periodo 2026A)', $html);
    }

    #[Test]
    public function filtra_por_periodo_tipo_origen_y_titulo(): void
    {
        // 2026B: lo creado en ese periodo; el banco no, porque solo se aplicó en 2026A
        $this->get(route('historial.index', ['materia' => $this->materia, 'dictado' => $this->siguiente->id]))->assertOk()
            ->assertSee('periodo 2026B')->assertSee('Debate de colas')->assertDontSee('Quiz de pilas')->assertDontSee('Rúbrica de sustentación');
        $this->get(route('historial.index', ['materia' => $this->materia, 'dictado' => $this->dictado->id]))
            ->assertSee('Quiz de pilas')->assertSee('Rúbrica de sustentación')->assertDontSee('Debate de colas');

        $this->get(route('historial.index', ['materia' => $this->materia, 'tipo' => 'rubrica']))->assertSee('Rúbrica de sustentación')->assertDontSee('Debate de colas');
        $this->get(route('historial.index', ['materia' => $this->materia, 'origen' => 'ia']))->assertSee('Rúbrica de sustentación')->assertDontSee('Unidad de colas');
        $this->get(route('historial.index', ['materia' => $this->materia, 'q' => 'COLAS']))->assertSee('Debate de colas')->assertSee('Unidad de colas')->assertDontSee('Quiz de pilas');
        $this->get(route('historial.index', ['materia' => $this->materia, 'q' => 'nada así']))->assertSee('Ninguna actividad coincide');

        // Desde el aplicar en otro periodo: la evaluación se reutiliza, y queda como aplicada también en 2026B
        $this->post(route('artefactos.aplicar', Artefacto::query()->sole()), ['componente_id' => $this->siguiente->divisiones()->create(['nombre' => 'Corte 1', 'peso' => '1', 'orden' => 0])
            ->componentes()->create(['nombre' => 'Quices', 'peso' => '1', 'orden' => 0])->id, 'grupos' => [$this->siguiente->grupos()->create(['nombre' => 'Nocturno'])->id]])->assertRedirect();
        $this->get(route('historial.index', ['materia' => $this->materia, 'dictado' => $this->siguiente->id]))->assertSee('Quiz de pilas')->assertSee('2026B › Corte 1 (Nocturno)');
    }

    #[Test]
    public function copiar_para_ajustar_no_toca_la_original(): void
    {
        $debate = Material::query()->sole();
        $this->post(route('historial.copiar', $this->materia), ['tipo' => 'debate', 'id' => $debate->id])
            ->assertRedirect(route('debates.edit', $copia = Material::query()->latest('id')->first()));
        $this->assertSame('Debate de colas (copia)', $copia->titulo);
        $this->assertSame('Debate de colas (copia)', $copia->contenido['titulo']);
        $this->assertSame($debate->contenido['preguntas'], $copia->contenido['preguntas']);
        $this->assertSame('Debate de colas', $debate->fresh()->titulo);

        $rub = Rubrica::query()->sole();
        $this->post(route('historial.copiar', $this->materia), ['tipo' => 'rubrica', 'id' => $rub->id])->assertRedirect();
        $copiaR = Rubrica::query()->latest('id')->first();
        $this->assertSame('Rúbrica de sustentación (copia)', $copiaR->titulo);
        $this->assertNull($copiaR->generacion_ia_id, 'La copia es del docente, no de la IA');
        $this->assertSame([], app(Rubricas::class)->validar($copiaR->only('titulo', 'criterios')));

        $this->post(route('historial.copiar', $this->materia), ['tipo' => 'unidad', 'id' => UnidadDidactica::query()->sole()->id])->assertRedirect();
        $this->assertSame(2, UnidadDidactica::query()->count());

        // Las evaluaciones se vuelven a aplicar, no se copian; y nada de otra materia se copia
        $this->post(route('historial.copiar', $this->materia), ['tipo' => 'evaluacion', 'id' => Artefacto::query()->sole()->id])->assertStatus(422);
        $this->post(route('historial.copiar', $this->materia), ['tipo' => 'apuntes', 'id' => $debate->id])->assertNotFound();
    }

    #[Test]
    public function solo_el_docente_de_la_materia_ve_su_historial(): void
    {
        $this->get(route('dictados.show', $this->dictado))->assertSee('Historial de actividades del periodo');
        $this->actingAs(User::factory()->create());
        $this->get(route('historial.index', $this->materia))->assertForbidden();
        $this->post(route('historial.copiar', $this->materia), ['tipo' => 'debate', 'id' => Material::query()->sole()->id])->assertForbidden();
    }
}
