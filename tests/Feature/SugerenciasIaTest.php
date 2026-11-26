<?php

namespace Tests\Feature;

use App\Evaluacion\Rubricas;
use App\Livewire\CalificarAbiertas;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\ConfigMotorIa;
use App\Models\Respuesta;
use App\Models\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Puntaje sugerido por IA para respuestas abiertas: la IA propone, el docente decide (decisión 9, principio 1.2). */
class SugerenciasIaTest extends TestCase
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
        [, $items] = $this->crearArtefacto([['abierta', ['referencia' => 'El requisito dice qué hace; la restricción limita cómo.'], '2']]);
        $this->item = $items[0];
        $this->item->pregunta->update(['enunciado' => ['texto' => 'Explique la diferencia entre requisito y restricción.']]);
        $this->actividad = $this->crearActividad(null, ['artefacto_id' => $this->item->artefacto_id]);
        foreach ([['ANA', 'El requisito es lo que hace el sistema y la restricción lo limita, como usar MySQL.', null],
            ['BETO', 'Son lo mismo.', null], ['CARLA', 'Requisito: registrar usuarios.', '1'], ['DORA', '', null]] as $i => [$n, $texto, $p]) {
            $e = $this->crearEstudiante((string) (100 + $i), "APELLIDO{$i}", $n);
            $this->r[] = Respuesta::query()->create(['entrega_id' => $this->crearEntrega($this->actividad, $e)->id, 'artefacto_pregunta_id' => $this->item->id,
                'respuesta' => ['valor' => $texto], 'puntaje' => $p, 'puntaje_max' => '2', 'calificado_por' => $p ? 'docente' : null]);
        }
        ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama', 'base_url' => 'http://ollama.test:11434',
            'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true]);
    }

    private function ollamaResponde(array $sugerencias): void
    {
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]),
            'ollama.test:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['sugerencias' => $sugerencias])], 'prompt_eval_count' => 500, 'eval_count' => 200])]);
    }

    private function lw()
    {
        return Livewire::test(CalificarAbiertas::class, ['actividad' => $this->actividad]);
    }

    #[Test]
    public function sugiere_sin_aplicar_nada_y_sin_nombres_y_el_docente_decide(): void
    {
        [$ana, $beto, $carla, $dora] = $this->r;
        $this->ollamaResponde([
            ['id' => $ana->id, 'puntaje' => 1.8, 'justificacion' => 'Distingue bien y da un ejemplo de restricción.'],
            ['id' => $beto->id, 'puntaje' => 0, 'justificacion' => 'No distingue los conceptos.'],
            ['id' => $carla->id, 'puntaje' => 2, 'justificacion' => 'No se envió: ya tenía puntaje.'],
            ['id' => 99999, 'puntaje' => 2, 'justificacion' => 'Inventada'],
        ]);

        $lw = $this->lw()->assertSee('Sugerir puntajes con IA (2)')->call('sugerirPuntajes')
            ->assertSee('La IA sugirió 2 puntaje(s)')->assertSee('Sugerencia de la IA:')->assertSee('Distingue bien y da un ejemplo de restricción.');

        Http::assertSent(function (Request $req) {
            if (! str_contains($req->url(), '/api/chat')) {
                return false;
            }
            $u = json_encode($req->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($u, 'como usar MySQL') && ! str_contains($u, 'ANA') && ! str_contains($u, 'APELLIDO') && ! str_contains($u, 'Requisito: registrar usuarios');
        });

        // Nada se aplicó: siguen sin calificar
        $this->assertNull($ana->fresh()->puntaje);
        $this->assertSame('1.8000', $ana->fresh()->sugerencia['puntaje']);
        $this->assertNull($carla->fresh()->sugerencia, 'Ya calificada: no se le sugiere');
        $this->assertNull($dora->fresh()->sugerencia, 'Vacía: no se envía');

        // Acepta la de Ana tal cual; a Beto le pone otro puntaje
        $lw->call('aceptarSugerencia', $ana->id)->call('puntuar', $beto->id, '0.5');
        $html = preg_replace(['/<!--.*?-->/s', '/\s+/'], ['', ' '], $lw->html());
        $this->assertStringContainsString('aceptó tal cual 1 · cambió 1 (diferencia media 0.5)', $html);
        $this->assertSame(['1.8000', 'docente', 'aceptada'], [$ana->fresh()->puntaje, $ana->fresh()->calificado_por, $ana->fresh()->sugerencia['decision']]);
        $this->assertSame(['cambiada', '0.5000'], [$beto->fresh()->sugerencia['decision'], $beto->fresh()->sugerencia['final']]);

        // El estudiante nunca ve la sugerencia ni la justificación
        $this->post(route('calificador.registro', $this->actividad));
        $this->assertStringNotContainsString('Distingue bien y da un ejemplo', $this->get(route('calificador.devolucion.ver', [$this->actividad, $ana->entrega->integrantes->first()]))->getContent());
    }

    #[Test]
    public function con_rubrica_elige_niveles_y_el_puntaje_lo_calcula_la_plataforma(): void
    {
        $niveles = [['nombre' => 'Excelente', 'descripcion' => 'Con ejemplo', 'porcentaje' => '100'], ['nombre' => 'Básico', 'descripcion' => 'Sin ejemplo', 'porcentaje' => '50'],
            ['nombre' => 'Insuficiente', 'descripcion' => 'Los confunde', 'porcentaje' => '0']];
        $rub = Rubrica::query()->create(['materia_id' => $this->materia->id] + app(Rubricas::class)->normalizar(['titulo' => 'Requisitos', 'proposito' => '', 'criterios' => [
            ['nombre' => 'Precisión', 'descripcion' => '', 'peso' => '60', 'niveles' => $niveles], ['nombre' => 'Ejemplo', 'descripcion' => '', 'peso' => '40', 'niveles' => $niveles]]]));
        $this->item->pregunta->update(['rubrica_id' => $rub->id]);
        [$ana, $beto] = $this->r;
        // La IA dice 2 de puntaje, pero sus niveles valen 1.4: cuenta la rúbrica. Beto trae niveles inválidos: se descarta.
        $this->ollamaResponde([['id' => $ana->id, 'niveles' => [0, 1], 'puntaje' => 2, 'justificacion' => 'Precisa; el ejemplo es débil.'],
            ['id' => $beto->id, 'niveles' => [7], 'justificacion' => 'x']]);

        $lw = $this->lw()->call('sugerirPuntajes')->assertSee('La IA sugirió 1 puntaje(s)')->assertSee('Precisión: Excelente · Ejemplo: Básico');
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/api/chat') && str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), '[1] Básico: Sin ejemplo'));
        $this->assertSame('1.6000', $ana->fresh()->sugerencia['puntaje']);
        $this->assertNull($beto->fresh()->sugerencia);

        $lw->call('aceptarSugerencia', $ana->id);
        $ana->refresh();
        $this->assertSame('1.6000', $ana->puntaje);
        $this->assertSame([0, 1], $ana->desglose['rubrica']['seleccion']);
        $this->assertSame('aceptada', $ana->sugerencia['decision']);
    }

    #[Test]
    public function descartar_y_motor_en_la_nube(): void
    {
        [$ana] = $this->r;
        $this->ollamaResponde([['id' => $ana->id, 'puntaje' => 2, 'justificacion' => 'Bien.']]);
        $this->lw()->call('sugerirPuntajes')->call('descartarSugerencia', $ana->id)->assertDontSee('Sugerencia de la IA:')->assertSee('descartó 1');
        $this->assertNull($ana->fresh()->puntaje);

        ConfigMotorIa::query()->update(['predeterminado' => false]);
        ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Claude', 'driver' => 'anthropic', 'modelo' => 'claude-opus-5-5',
            'es_local' => false, 'api_key' => 'sk-x', 'predeterminado' => true]);
        Http::fake();
        $this->lw()->call('sugerirPuntajes')->assertSee('Esta tarea procesa trabajo de estudiantes y el motor configurado es en la nube');
        Http::assertNothingSent();
    }
}
