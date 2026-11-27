<?php

namespace Tests\Feature;

use App\Material\Presentacion;
use App\Material\Publicador;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Material;
use App\Models\TemarioItem;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;
use ZipArchive;

/** Presentaciones de clase (7.2): generadas dentro de la aplicación, con notas del orador y PowerPoint editable. */
class PresentacionesTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $pilas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true]);
        $u = $this->materia->temario()->create(['tipo' => 'unidad', 'titulo' => 'Estructuras lineales', 'orden' => 1]);
        $this->pilas = $this->materia->temario()->create(['tipo' => 'tema', 'titulo' => 'Pilas', 'parent_id' => $u->id, 'orden' => 1]);
    }

    private function presentacion(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-presentacion', 'version' => 1, 'titulo' => 'Pilas: el último en llegar', 'subtitulo' => 'Unidad 2',
            'proposito' => 'Que el grupo reconozca cuándo usar una pila.',
            'diapositivas' => [
                ['tipo' => 'seccion', 'titulo' => '¿Dónde hay pilas?', 'puntos' => ['Del día a día al código'], 'notas' => 'Abrir con la pila de platos.', 'tema' => 'Pilas'],
                ['tipo' => 'contenido', 'titulo' => 'Qué es una pila', 'puntos' => ['Último en entrar, primero en salir', 'Solo se opera por la cima'], 'notas' => 'NOTA-SOLO-DOCENTE: dibujarla en el tablero.', 'tema' => 'Pilas'],
                ['tipo' => 'codigo', 'titulo' => 'Una pila con un arreglo', 'codigo' => "const pila = [];\npila.push(1);\npila.pop();", 'puntos' => ['push y pop son O(1)'], 'notas' => 'Ejecutar en vivo.', 'tema' => 'Pilas'],
                ['tipo' => 'pregunta', 'titulo' => '¿Qué estructura usa «Deshacer»?', 'puntos' => ['Piense en el orden'], 'notas' => 'Dos minutos.', 'tema' => 'Pilas'],
                ['tipo' => 'cierre', 'titulo' => 'Para llevarse', 'puntos' => ['LIFO', 'Cima'], 'notas' => ''],
            ]], $cambios);
    }

    #[Test]
    public function valida_y_advierte_lo_que_no_se_lee_proyectado(): void
    {
        $p = app(Presentacion::class);
        $this->assertSame([], $p->errores($this->presentacion()));
        $this->assertSame([], $p->advertencias($this->presentacion(), $this->materia));

        $mala = $this->presentacion(['diapositivas' => [
            ['tipo' => 'contenido', 'titulo' => '', 'puntos' => [], 'notas' => 'x'],
            ['tipo' => 'codigo', 'titulo' => 'Código', 'codigo' => ''],
            ['tipo' => 'animacion', 'titulo' => 'X', 'puntos' => ['a']],
        ]]);
        $e = implode(' | ', $p->errores($mala));
        foreach (['Diapositiva 2: falta el título', 'necesita al menos una viñeta', 'necesita el código', 'el tipo debe ser'] as $f) {
            $this->assertStringContainsString($f, $e);
        }

        $a = implode(' | ', $p->advertencias($this->presentacion(['diapositivas' => [
            ['tipo' => 'contenido', 'titulo' => 'Demasiado', 'puntos' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'], 'notas' => '', 'tema' => 'Árboles'],
            ['tipo' => 'contenido', 'titulo' => 'Párrafo', 'puntos' => ['Esta viñeta es en realidad un párrafo entero que el docente tendría que leer en voz alta frente al grupo'], 'notas' => 'x'],
        ]]), $this->materia));
        foreach (['tiene 7 viñetas', 'parece un párrafo', 'no tiene notas del orador', '«Árboles», que no está en el temario'] as $f) {
            $this->assertStringContainsString($f, $a);
        }
    }

    #[Test]
    public function genera_con_ia_y_proyecta_con_notas_solo_para_el_docente(): void
    {
        $cola = Http::sequence()->push(['message' => ['content' => json_encode($this->presentacion())], 'prompt_eval_count' => 900, 'eval_count' => 700]);
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]), 'ollama.test:11434/api/chat' => $cola]);

        $this->get(route('presentaciones.index', $this->materia))->assertOk()->assertSee('Generar una presentación con IA');
        $this->post(route('presentaciones.preparar', $this->materia), ['dictado_id' => $this->dictado->id, 'temas' => [$this->pilas->id], 'cantidad' => 10,
            'minutos' => 45, 'enfoque' => 'preguntas', 'codigo' => '1', 'contexto' => 'plantas de energía', 'grupo' => 'Diurno', 'motor_id' => $this->motor->id])
            ->assertOk()->assertSee('unas 10, sin contar la portada')->assertSee('Con preguntas al grupo')->assertSee('incluya diapositivas de tipo «codigo»')
            ->assertSee('Máximo 6 viñetas por diapositiva');
        $this->post(route('presentaciones.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('presentaciones.borrador', $g = GeneracionIa::query()->sole()));
        $this->assertSame('presentacion', $g->tarea);
        $this->get(route('presentaciones.borrador', $g))->assertOk()->assertSee('El formato es válido');
        $this->post(route('presentaciones.aprobar', $g))->assertRedirect();
        $m = Material::query()->sole();

        $this->get(route('presentaciones.show', $m))->assertOk()->assertSee('6 diapositivas')->assertSee('NOTA-SOLO-DOCENTE')
            ->assertSee('Proyectar')->assertSee('Pregunta al grupo')->assertSee('const pila = [];');
        $this->get(route('presentaciones.show', ['material' => $m, 'version' => 'estudiante']))->assertOk()->assertDontSee('NOTA-SOLO-DOCENTE');
        $this->get(route('historial.index', $this->materia))->assertOk()->assertSee('Presentaciones');
    }

    #[Test]
    public function el_powerpoint_es_editable_y_lleva_las_notas_del_orador(): void
    {
        $m = $this->materia->materiales()->create(['tipo' => 'presentacion', 'titulo' => 'Pilas', 'contenido' => app(Presentacion::class)->normalizar($this->presentacion())]);
        $r = $this->get(route('presentaciones.pptx', $m))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation')
            ->assertHeader('Content-Disposition', 'attachment; filename="pilas.pptx"');
        $ruta = tempnam(sys_get_temp_dir(), 'p');
        file_put_contents($ruta, $r->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        for ($i = 0; $i < $zip->numFiles; $i++) {                       // todas las partes son XML bien formado
            $this->assertTrue((new DOMDocument)->loadXML($zip->getFromIndex($i)), $zip->getNameIndex($i));
        }
        $this->assertSame(6, substr_count($zip->getFromName('ppt/presentation.xml'), '<p:sldId '));
        $this->assertStringContainsString('<a:t>Pilas: el último en llegar</a:t>', $zip->getFromName('ppt/slides/slide1.xml'));
        $this->assertStringContainsString('<a:t>Qué es una pila</a:t>', $zip->getFromName('ppt/slides/slide3.xml'));
        $this->assertStringContainsString('<p:ph type="ctrTitle"/>', $zip->getFromName('ppt/slides/slide1.xml'));   // títulos reales: esquema y lectores de pantalla
        $this->assertStringContainsString('<p:ph type="title"/>', $zip->getFromName('ppt/slides/slide3.xml'));
        $this->assertStringContainsString('typeface="Consolas"', $zip->getFromName('ppt/slides/slide4.xml'));
        $this->assertStringContainsString('NOTA-SOLO-DOCENTE', $zip->getFromName('ppt/notesSlides/notesSlide3.xml'));
        $this->assertStringContainsString('<p:ph type="body" idx="1"/>', $zip->getFromName('ppt/notesSlides/notesSlide3.xml'));
        $zip->close();
        @unlink($ruta);
    }

    #[Test]
    public function se_publica_sin_notas_y_solo_la_ve_su_docente(): void
    {
        $m = $this->materia->materiales()->create(['tipo' => 'presentacion', 'titulo' => 'Pilas', 'contenido' => app(Presentacion::class)->normalizar($this->presentacion())]);
        $html = app(Publicador::class)->contenido($m);
        $this->assertStringContainsString('Qué es una pila', $html);
        $this->assertStringNotContainsString('NOTA-SOLO-DOCENTE', $html);
        $this->assertSame(1, substr_count($html, 'Qué es una pila'), 'La proyección no se duplica en lo publicado');

        $this->actingAs(User::factory()->create());
        $this->get(route('presentaciones.show', $m))->assertForbidden();
        $this->get(route('presentaciones.pptx', $m))->assertForbidden();
    }
}
