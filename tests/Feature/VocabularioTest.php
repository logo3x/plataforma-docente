<?php

namespace Tests\Feature;

use App\Material\ListaVocabulario;
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

/** Generador «Lista de vocabulario» (7.4) y su paso al vocabulario propio de la materia (capa 2). */
class VocabularioTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $bd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        $u = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'unidad', 'titulo' => 'Datos']);
        $this->bd = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'parent_id' => $u->id, 'tipo' => 'tema', 'titulo' => 'Bases de datos relacionales']);
    }

    private function termino(string $t, string $def, array $cambios = []): array
    {
        return array_replace(['termino' => $t, 'tema' => 'bases de datos relacionales', 'definicion' => $def, 'ejemplo' => 'En el inventario de repuestos…',
            'ingles' => '', 'relacionados' => []], $cambios);
    }

    private function lista(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-vocabulario', 'version' => 1, 'titulo' => 'Vocabulario de bases de datos', 'proposito' => 'Hablar con precisión.',
            'terminos' => [
                $this->termino('Clave primaria', 'Campo que identifica cada fila sin repetirse.', ['ingles' => 'primary key', 'relacionados' => ['Clave foránea']]),
                $this->termino('Clave foránea', 'Campo que apunta a la fila de otra tabla.', ['ingles' => 'foreign key']),
                $this->termino('Índice', 'Estructura que acelera las búsquedas sobre una columna.'),
            ]], $cambios);
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
        return array_replace(['dictado_id' => $this->dictado->id, 'temas' => [$this->bd->id], 'cantidad' => 12, 'nivel' => 'introductorio', 'ingles' => '1',
            'contexto' => 'una planta de energía', 'grupo' => 'Diurno', 'motor_id' => $this->motor->id], $cambios);
    }

    #[Test]
    public function valida_terminos_repetidos_y_advierte_definiciones_circulares(): void
    {
        $s = app(ListaVocabulario::class);
        $this->assertSame([], $s->errores($this->lista()));

        $mala = $this->lista(['terminos' => [$this->termino('Índice', ''), $this->termino('indice', 'Otra cosa.'), $this->termino('', 'Sin término.')]]);
        $e = implode(' | ', $s->errores($mala));
        $this->assertStringContainsString('falta la definición', $e);
        $this->assertStringContainsString('está repetido', $e);
        $this->assertStringContainsString('falta el término', $e);

        $a = implode(' | ', $s->advertencias($this->lista(['terminos' => [
            $this->termino('Normalización', 'Proceso de normalización de las tablas.'),
            $this->termino('Trigger', 'Algo.', ['tema' => 'Redes']),
            $this->termino('Vista', str_repeat('palabra ', 70)),
        ]]), $this->materia));
        $this->assertStringContainsString('usa el mismo término que define', $a);
        $this->assertStringContainsString('«Redes» no está en el temario', $a);
        $this->assertStringContainsString('la definición es larga', $a);
        $this->assertSame([], $s->advertencias($this->lista(), $this->materia));
    }

    #[Test]
    public function genera_con_el_glosario_actual_y_lo_aprueba(): void
    {
        $this->materia->update(['estilo' => ['glosario' => 'Tupla: una fila de la tabla']]);
        $this->ollamaResponde("```json\n".json_encode($this->lista())."\n```");

        $this->post(route('vocabularios.preparar', $this->materia), $this->formulario())->assertOk()
            ->assertSee('Bases de datos relacionales')->assertSee('12 términos')->assertSee('introductorio')
            ->assertSee('equivalente en inglés')->assertSee('Tupla: una fila de la tabla')->assertSee(route('vocabularios.generar', $this->materia), false);
        $this->post(route('vocabularios.preparar', $this->materia), $this->formulario(['ingles' => null]))->assertSee('Deje «ingles» vacío');

        $this->post(route('vocabularios.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('vocabularios.borrador', $g = GeneracionIa::query()->sole()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $this->assertSame('vocabulario', $g->tarea);
        $this->get(route('vocabularios.borrador', $g))->assertOk()->assertSee('El formato es válido');
        $this->post(route('vocabularios.aprobar', $g))->assertRedirect();
        $this->assertSame('vocabulario', Material::query()->sole()->tipo);
    }

    #[Test]
    public function se_entrega_como_lista_tarjetas_u_hoja_de_repaso(): void
    {
        $m = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'vocabulario', 'titulo' => 'BD', 'contenido' => app(ListaVocabulario::class)->normalizar($this->lista())]);

        $this->get(route('vocabularios.show', $m))->assertOk()->assertSee('Clave primaria')->assertSee('primary key')->assertSee('Agregar al vocabulario de la materia');
        $this->get(route('vocabularios.show', ['material' => $m, 'version' => 'tarjetas']))->assertOk()->assertSee('tarjeta-voc', false)->assertSee('Campo que identifica cada fila');

        // En la hoja de repaso el término no aparece junto a su definición; sí en el banco de palabras
        $html = $this->get(route('vocabularios.show', ['material' => $m, 'version' => 'repaso']))->assertOk()->assertSee('Banco de palabras')->getContent();
        $ol = substr($html, strpos($html, 'class="repaso-voc"'));
        $this->assertStringContainsString('Campo que identifica cada fila sin repetirse.', $ol);
        $this->assertStringNotContainsString('Clave primaria', $ol);
        $this->assertStringNotContainsString('Agregar al vocabulario', $html);
    }

    #[Test]
    public function los_terminos_pasan_al_vocabulario_de_la_materia_sin_duplicar_ni_pasar_el_tope(): void
    {
        $this->materia->update(['estilo' => ['tono' => 'cercano', 'glosario' => 'Clave primaria: la que ya estaba']]);
        $m = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'vocabulario', 'titulo' => 'BD', 'contenido' => app(ListaVocabulario::class)->normalizar($this->lista())]);

        $this->post(route('vocabularios.glosario', $m))->assertSessionHas('estado', fn ($s) => str_contains($s, '2 término(s) agregados') && str_contains($s, '1 ya estaban'));
        $estilo = $this->materia->fresh()->estilo;
        $this->assertSame('cercano', $estilo['tono'], 'El resto del estilo no se toca');
        $this->assertSame("Clave primaria: la que ya estaba\nClave foránea: Campo que apunta a la fila de otra tabla\nÍndice: Estructura que acelera las búsquedas sobre una columna", $estilo['glosario']);

        // Repetirlo no duplica
        $this->post(route('vocabularios.glosario', $m))->assertSessionHas('estado', fn ($s) => str_contains($s, 'No se agregó ningún término') && str_contains($s, '3 ya estaban'));

        // Sin espacio en el campo, se avisa
        $this->materia->update(['estilo' => ['glosario' => str_repeat('x', ListaVocabulario::MAX_GLOSARIO - 10)]]);
        $this->post(route('vocabularios.glosario', $m))->assertSessionHas('estado', fn ($s) => str_contains($s, '3 no cupieron'));
        $this->assertLessThanOrEqual(ListaVocabulario::MAX_GLOSARIO, mb_strlen($this->materia->fresh()->estilo['glosario']));
    }

    #[Test]
    public function a_mano_con_plantilla_y_otro_docente_no_ve_nada(): void
    {
        $this->get(route('vocabularios.create', ['materia' => $this->materia, 'temas' => [$this->bd->id]]))->assertOk()
            ->assertSee('Agregar término')->assertSee('\\u0022tema\\u0022:\\u0022Bases de datos relacionales\\u0022', false);
        $this->post(route('vocabularios.store', $this->materia), ['contenido' => json_encode($this->lista())])->assertRedirect();
        $m = Material::query()->sole();
        $this->put(route('vocabularios.update', $m), ['contenido' => json_encode($this->lista(['titulo' => 'Otro']))])->assertRedirect();
        $this->get(route('vocabularios.index', $this->materia))->assertSee('Otro');
        $this->get(route('debates.show', $m))->assertNotFound();
        $this->get(route('ia.plantillas'))->assertSee('Capa 3 — Tarea: lista de vocabulario');

        $this->actingAs(User::factory()->create());
        $this->get(route('vocabularios.show', $m))->assertForbidden();
        $this->post(route('vocabularios.glosario', $m))->assertForbidden();
    }
}
