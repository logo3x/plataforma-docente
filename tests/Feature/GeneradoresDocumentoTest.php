<?php

namespace Tests\Feature;

use App\Material\DocumentoGenerado;
use App\Material\Publicador;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Generador;
use App\Models\Materia;
use App\Models\Material;
use App\Models\PlantillaPrompt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 7.4.3 generadores configurables, 7.5 y 7.10 manuales, guías y boletines, 8.4.5 biblioteca de lo que funcionó. */
class GeneradoresDocumentoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
    }

    private function manual(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-documento', 'version' => 1, 'generador' => 'Manual interactivo', 'titulo' => 'Funciones paso a paso',
            'proposito' => 'Escribir y probar funciones.', 'secciones' => [[
                'titulo' => 'Una función que suma', 'texto' => 'Una función recibe datos y devuelve un resultado.', 'codigo' => 'console.log(2 + 3);', 'lenguaje' => 'javascript',
                'ejercicio' => ['enunciado' => 'Escriba sumar(a, b).', 'plantilla' => "function sumar(a, b) {\n}", 'solucion' => 'function sumar(a, b) { return a + b; }', 'prueba' => 'sumar(2, 3) === 5'],
            ]], 'fuentes' => ['Guía 1']], $cambios);
    }

    private function ollamaResponde(string $r): void
    {
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]),
            'ollama.test:11434/api/chat' => Http::response(['message' => ['content' => $r], 'prompt_eval_count' => 900, 'eval_count' => 700])]);
    }

    #[Test]
    public function un_generador_propio_se_crea_sin_codigo_y_arma_su_prompt(): void
    {
        $this->post(route('generadores.store'), ['nombre' => 'Taller de repaso', 'instrucciones' => 'Haga {{preguntas}} preguntas de repaso de {{tema}} con su respuesta, para {{publico}}.',
            'campos' => "preguntas | Cuántas preguntas | 10\npublico | Para quién", 'codigo' => 'no'])
            ->assertSessionHasNoErrors()->assertRedirect(route('generadores.index'));
        $g = Generador::query()->sole();
        $this->assertSame([['clave' => 'preguntas', 'etiqueta' => 'Cuántas preguntas', 'defecto' => '10'], ['clave' => 'publico', 'etiqueta' => 'Para quién', 'defecto' => '']], $g->campos);

        $this->get(route('documentos.index', $this->materia))->assertOk()->assertSee('Taller de repaso')->assertSee('Manual interactivo')->assertSee('Boletín');

        $form = ['dictado_id' => $this->dictado->id, 'tema' => 'Pilas', 'generador' => "g:{$g->id}", 'motor_id' => $this->motor->id];
        $this->post(route('documentos.preparar', $this->materia), $form + ['campos' => ['preguntas' => '', 'publico' => '']])
            ->assertSessionHasErrors(['tema' => 'Falta «Para quién».']);
        $this->post(route('documentos.preparar', $this->materia), $form + ['campos' => ['preguntas' => '', 'publico' => 'quienes perdieron el parcial']])->assertOk()
            ->assertSee('Tarea: Taller de repaso.')
            ->assertSee('Haga 10 preguntas de repaso de el tema indicado con su respuesta, para quienes perdieron el parcial.')
            ->assertSee('No incluya código');

        // Las instrucciones no pueden usar campos que no existen
        $this->post(route('generadores.store'), ['nombre' => 'Malo', 'instrucciones' => 'Use {{inexistente}}.', 'campos' => '', 'codigo' => 'no'])
            ->assertSessionHasErrors(['campos' => 'Las instrucciones usan {{inexistente}}, que no está entre los campos.']);
        $this->actingAs(User::factory()->create())->get(route('generadores.edit', $g))->assertForbidden();
    }

    #[Test]
    public function el_manual_interactivo_se_genera_y_su_solucion_no_sale_para_el_estudiante(): void
    {
        $this->post(route('documentos.preparar', $this->materia), ['dictado_id' => $this->dictado->id, 'tema' => 'Funciones', 'generador' => 'f:manual',
            'campos' => ['pasos' => '3', 'punto_de_partida' => 'variables'], 'motor_id' => $this->motor->id])->assertOk()
            ->assertSee('manual interactivo de 3 pasos')->assertSee('La plantilla NO debe pasar la prueba');

        $this->ollamaResponde(json_encode($this->manual()));
        $this->post(route('documentos.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->latest('id')->first();
        $this->assertSame('documento', $g->tarea);
        $this->post(route('documentos.aprobar', $g))->assertRedirect();
        $m = Material::query()->where('tipo', 'documento')->sole();
        $this->assertSame('Manual interactivo', $m->contenido['generador']);
        $this->assertSame(1, app(DocumentoGenerado::class)->interactivas($m->contenido));

        $this->get(route('documentos.show', $m))->assertOk()->assertSee('Comprobar los ejercicios')->assertSee('return a + b');
        $this->get(route('documentos.show', ['material' => $m, 'version' => 'estudiante']))->assertOk()
            ->assertSee('Probar mi solución')->assertDontSee('return a + b')->assertDontSee('Esto funcionó');

        // Publicado: sin scripts ni la solución, con el código para empezar a la vista
        $html = app(Publicador::class)->contenido($m);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('return a + b', $html);
        $this->assertStringContainsString('<pre class="codigo">function sumar(a, b) {', $html);

        // 8.4.5: «esto funcionó» lleva la plantilla con que se generó a la biblioteca
        $this->post(route('ia.generaciones.funciono', $g), ['nota_funciono' => 'Buenos ejercicios'])->assertSessionHas('estado');
        $p = PlantillaPrompt::query()->where('clave', 'documento')->sole();
        $this->assertTrue($p->funciono);
        $this->get(route('ia.biblioteca'))->assertOk()->assertSee('Buenos ejercicios')->assertSee('documento de un generador configurable');
    }

    #[Test]
    public function el_documento_valida_sus_secciones_y_ejercicios(): void
    {
        $d = app(DocumentoGenerado::class);
        $this->assertSame([], $d->errores($this->manual()));
        $malo = $this->manual(['secciones' => [['titulo' => 'X', 'texto' => 'Y', 'lenguaje' => 'javascript', 'ejercicio' => ['enunciado' => 'Haga algo', 'plantilla' => '', 'solucion' => '', 'prueba' => '']]]]);
        $this->assertContains('Sección 1 («X»): el ejercicio necesita su prueba (una expresión que dé true si el código lo resuelve).', $d->errores($malo));
        $this->assertContains('Sección 1 («X»): el ejercicio no tiene solución; sin ella no se puede comprobar que su prueba funciona.', $d->advertencias($malo, $this->materia));
    }

    #[Test]
    public function los_generadores_que_funcionaron_y_el_estilo_pasan_entre_materias(): void
    {
        $g = Generador::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Guía corta', 'instrucciones' => 'Guía breve.', 'campos' => [], 'codigo' => 'no']);
        $this->post(route('generadores.funciono', $g), ['funciono' => 1, 'nota_funciono' => 'A los nocturnos les sirvió'])->assertSessionHas('estado');
        $this->get(route('ia.biblioteca'))->assertSee('Guía corta')->assertSee('A los nocturnos les sirvió');

        $this->materia->update(['estilo' => ['tono' => 'cercano', 'glosario' => 'Tupla: fila']]);
        $otra = Materia::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Bases de datos II']);
        $this->get(route('materias.estilo', $otra))->assertSee('Partir del estilo que funcionó en otra materia');
        $this->post(route('materias.estilo.copiar', $otra), ['origen' => $this->materia->id])->assertSessionHas('estado');
        $this->assertSame('cercano', $otra->fresh()->estilo['tono']);
    }
}
