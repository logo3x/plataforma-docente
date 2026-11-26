<?php

namespace Tests\Feature;

use App\Calificacion\ComentariosIA;
use App\Livewire\CalificarAbiertas;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\ComentarioFrecuente;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Respuesta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Comentarios frecuentes (6.7.13) y borradores de comentario en voz del docente (8.5). */
class ComentariosTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private ArtefactoPregunta $item;

    /** @var array<int, Respuesta> */
    private array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->docente->update(['perfil_ia' => ['idioma' => 'español de Colombia, trato de usted', 'reglas_escritura' => 'frases cortas, sin muletillas']]);
        [, $items] = $this->crearArtefacto([['abierta', ['referencia' => 'El requisito dice qué hace; la restricción limita cómo.'], '2']]);
        $this->item = $items[0];
        $this->item->pregunta->update(['enunciado' => ['texto' => 'Explique la diferencia entre requisito y restricción.']]);
        $this->actividad = $this->crearActividad(null, ['artefacto_id' => $this->item->artefacto_id]);
        foreach ([['ANA', 'El requisito es lo que hace el sistema y la restricción lo limita, como usar MySQL.', '2'],
            ['BETO', 'Son lo mismo.', '0.5'], ['CARLA', 'Requisito: registrar usuarios.', null]] as $i => [$n, $texto, $p]) {
            $e = $this->crearEstudiante((string) (100 + $i), "APELLIDO{$i}", $n);
            $this->r[] = Respuesta::query()->create(['entrega_id' => $this->crearEntrega($this->actividad, $e)->id, 'artefacto_pregunta_id' => $this->item->id,
                'respuesta' => ['valor' => $texto], 'puntaje' => $p, 'puntaje_max' => '2', 'calificado_por' => $p ? 'docente' : null]);
        }
        ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama', 'base_url' => 'http://ollama.test:11434',
            'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true]);
    }

    private function ollamaResponde(string $texto): void
    {
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]),
            'ollama.test:11434/api/chat' => Http::response(['message' => ['content' => $texto], 'prompt_eval_count' => 500, 'eval_count' => 200])]);
    }

    private function lw()
    {
        return Livewire::test(CalificarAbiertas::class, ['actividad' => $this->actividad]);
    }

    #[Test]
    public function la_ia_propone_borradores_solo_para_las_calificadas_sin_nombres_y_nada_llega_al_estudiante(): void
    {
        [$ana, $beto, $carla] = $this->r;
        $this->ollamaResponde(json_encode(['comentarios' => [
            ['id' => $ana->id, 'comentario' => 'Distingue bien los dos conceptos y el ejemplo de MySQL lo deja claro.'],
            ['id' => $beto->id, 'comentario' => 'Obtuvo 0.5 de 2 puntos: no son lo mismo.'],
            ['id' => 999999, 'comentario' => 'De otra respuesta: se ignora.'],
        ]]));

        $this->lw()->assertSee('Proponer comentarios con IA (2)')->call('proponerComentarios')->assertSee('La IA propuso 2 borrador(es)')
            ->assertSee('Borrador de la IA')->assertSee('Menciona cifras o puntajes');

        Http::assertSent(function (Request $req) {
            if (! str_contains($req->url(), '/api/chat')) {
                return false;
            }
            $u = $req['messages'][1]['content'];
            $s = $req['messages'][0]['content'];

            return str_contains($u, 'El requisito dice qué hace') && str_contains($u, 'Puntaje: 0.5 de 2') && str_contains($u, 'SIN mencionar cifras')
                && ! str_contains($u, 'ANA') && ! str_contains($u, 'APELLIDO') && ! str_contains($u, 'Requisito: registrar usuarios.')   // sin nombres ni la no calificada
                && str_contains($s, 'frases cortas, sin muletillas');                                                                  // perfil de escritura del docente
        });
        $this->assertTrue(GeneracionIa::query()->sole()->contiene_datos_estudiantes);

        // Borrador ≠ comentario: el estudiante aún no ve nada
        $this->assertNull($ana->fresh()->comentario);
        $this->assertSame('Distingue bien los dos conceptos y el ejemplo de MySQL lo deja claro.', $ana->fresh()->comentario_borrador);
        $this->assertNull($carla->fresh()->comentario_borrador);

        // Usarlo con un ajuste del docente; descartar el otro
        $this->lw()->call('usarBorrador', $ana->id, 'Distingue bien los dos conceptos; el ejemplo de MySQL lo deja claro.')
            ->call('descartarBorrador', $beto->id);
        $this->assertSame('Distingue bien los dos conceptos; el ejemplo de MySQL lo deja claro.', $ana->fresh()->comentario);
        $this->assertNull($ana->fresh()->comentario_borrador);
        $this->assertNull($beto->fresh()->comentario);
        $this->assertNull($beto->fresh()->comentario_borrador);
    }

    #[Test]
    public function con_motor_en_la_nube_no_envia_respuestas_de_estudiantes(): void
    {
        ConfigMotorIa::query()->update(['predeterminado' => false]);
        ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Claude', 'driver' => 'anthropic', 'modelo' => 'claude-opus-5-5',
            'es_local' => false, 'api_key' => 'sk-x', 'predeterminado' => true]);
        Http::fake();
        $this->lw()->call('proponerComentarios')->assertSee('Esta tarea procesa trabajo de estudiantes y el motor configurado es en la nube');
        Http::assertNothingSent();
        $this->assertNull($this->r[0]->fresh()->comentario_borrador);
    }

    #[Test]
    public function los_comentarios_frecuentes_se_insertan_con_un_clic_y_cuentan_su_uso(): void
    {
        $lw = $this->lw()->set('nuevoFrecuente', 'Falta un ejemplo propio.')->set('alcanceFrecuente', 'pregunta')->call('guardarFrecuente')
            ->set('nuevoFrecuente', 'Revise la ortografía.')->set('alcanceFrecuente', 'materia')->call('guardarFrecuente')
            ->assertSee('+ Falta un ejemplo propio.');
        $f = ComentarioFrecuente::query()->where('texto', 'Falta un ejemplo propio.')->sole();
        $this->assertSame($this->item->pregunta_id, $f->pregunta_id);
        $this->assertNull(ComentarioFrecuente::query()->where('texto', 'Revise la ortografía.')->sole()->pregunta_id);

        $lw->call('comentar', $this->r[1]->id, 'No son lo mismo.')->call('insertarFrecuente', $this->r[1]->id, $f->id);
        $this->assertSame('No son lo mismo. Falta un ejemplo propio.', $this->r[1]->fresh()->comentario);
        $this->assertSame(1, $f->fresh()->usos);

        // Otro docente no usa ni ve los frecuentes ajenos
        $ajeno = ComentarioFrecuente::query()->create(['user_id' => User::factory()->create()->id, 'materia_id' => $this->materia->id, 'texto' => 'Ajeno']);
        $this->lw()->assertDontSee('Ajeno');
        $this->lw()->call('insertarFrecuente', $this->r[1]->id, $ajeno->id)->assertStatus(404);
        $this->assertSame('No son lo mismo. Falta un ejemplo propio.', $this->r[1]->fresh()->comentario);
    }

    #[Test]
    public function detecta_comentarios_que_delatan_el_calculo(): void
    {
        foreach (['Obtuvo 3.5 de 5', 'Logró el 80 %', 'Le faltaron 2 puntos', 'Su puntaje refleja…', '1/2 de la respuesta'] as $t) {
            $this->assertTrue(ComentariosIA::delataCalculo($t), $t);
        }
        foreach (['Distingue bien los conceptos; le faltó un ejemplo propio.', 'Revise la segunda parte: confunde los términos.'] as $t) {
            $this->assertFalse(ComentariosIA::delataCalculo($t), $t);
        }
    }

    #[Test]
    public function solo_lista_las_respuestas_de_esta_actividad_aunque_el_examen_se_aplique_en_otra(): void
    {
        $otra = $this->crearActividad(null, ['artefacto_id' => $this->item->artefacto_id, 'nombre' => 'Otra aplicación']);
        $e = $this->crearEstudiante('999', 'OTRO', 'PERIODO');
        Respuesta::query()->create(['entrega_id' => $this->crearEntrega($otra, $e)->id, 'artefacto_pregunta_id' => $this->item->id,
            'respuesta' => ['valor' => 'Respuesta de la otra aplicación'], 'puntaje_max' => '2']);
        $this->lw()->assertSee('Son lo mismo.')->assertDontSee('Respuesta de la otra aplicación');
    }
}
