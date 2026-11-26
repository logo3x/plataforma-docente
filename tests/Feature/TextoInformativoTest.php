<?php

namespace Tests\Feature;

use App\Material\TextoInformativo;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\GeneracionIa;
use App\Models\Material;
use App\Models\TemarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Generador «Texto informativo / apuntes» (7.4) y su paso al repositorio de contenido (módulo 7). */
class TextoInformativoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    private TemarioItem $norm;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
        $u = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'unidad', 'titulo' => 'Bases de datos']);
        $this->norm = TemarioItem::query()->create(['materia_id' => $this->materia->id, 'parent_id' => $u->id, 'tipo' => 'tema', 'titulo' => 'Normalización']);
    }

    private function texto(array $cambios = []): array
    {
        return array_replace(['formato' => 'plataforma-apunte', 'version' => 1, 'titulo' => 'Normalizar sin miedo', 'proposito' => 'Entender por qué se divide una tabla.',
            'secciones' => [
                ['titulo' => 'El problema de repetir', 'texto' => "Una planilla de repuestos repite el proveedor en cada fila.\n\nSi cambia su teléfono, hay que cambiarlo en cien filas.", 'idea_clave' => 'Repetir datos invita a la inconsistencia.', 'codigo' => ''],
                ['titulo' => 'Primera forma normal', 'texto' => "Cada celda guarda un solo valor:\n- nada de listas en una celda\n- nada de columnas repetidas", 'idea_clave' => '', 'codigo' => "CREATE TABLE repuesto (\n  id INT PRIMARY KEY\n);"],
            ],
            'resumen' => ['La normalización evita datos repetidos.'], 'preguntas' => [['pregunta' => '¿Qué problema resuelve?', 'respuesta' => 'La inconsistencia por repetición.']],
            'fuentes' => ['Guía de bases de datos, capítulo 4']], $cambios);
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
        return array_replace(['dictado_id' => $this->dictado->id, 'temas' => [$this->norm->id], 'extension' => 'corta', 'nivel' => 'introductorio',
            'enfoque' => 'para quienes faltaron', 'grupo' => 'Diurno', 'motor_id' => $this->motor->id], $cambios);
    }

    #[Test]
    public function valida_secciones_resumen_y_preguntas_y_parte_el_texto_en_bloques(): void
    {
        $s = app(TextoInformativo::class);
        $this->assertSame([], $s->errores($this->texto()));
        $this->assertSame(1, $s->minutos($this->texto()));

        $malo = $this->texto(['resumen' => [], 'secciones' => [['titulo' => 'Sin texto', 'texto' => '']], 'preguntas' => [['pregunta' => '¿Y?', 'respuesta' => '']]]);
        $e = implode(' | ', $s->errores($malo));
        foreach (['falta el texto', 'Falta el resumen', 'necesita la pregunta y su respuesta'] as $f) {
            $this->assertStringContainsString($f, $e);
        }

        $a = implode(' | ', $s->advertencias($this->texto(['preguntas' => [], 'fuentes' => [],
            'secciones' => [['titulo' => 'Larga', 'texto' => str_repeat('palabra ', 5600)]]]), $this->materia));
        foreach (['tiene 5600 palabras', 'unos 32 minutos de lectura', 'No tiene preguntas de comprensión', 'No cita fuentes'] as $f) {
            $this->assertStringContainsString($f, $a);
        }

        $b = TextoInformativo::bloques("Párrafo uno\nsigue.\n\n- a\n- b\n\nIntro:\n- c\nFinal.");
        $this->assertSame(['parrafo', 'lista', 'parrafo', 'lista', 'parrafo'], array_column($b, 'tipo'));
        $this->assertSame(['a', 'b'], $b[1]['contenido']);
    }

    #[Test]
    public function genera_con_el_glosario_de_la_materia_y_lo_aprueba(): void
    {
        $this->materia->update(['estilo' => ['glosario' => 'Tupla: una fila de la tabla']]);
        $this->ollamaResponde("```json\n".json_encode($this->texto())."\n```");

        $this->post(route('apuntes.preparar', $this->materia), $this->formulario())->assertOk()
            ->assertSee('Normalización')->assertSee('unas 600 palabras')->assertSee('para quienes faltaron')
            ->assertSee('Tupla: una fila de la tabla')->assertSee('no invente referencias')->assertSee(route('apuntes.generar', $this->materia), false);
        $this->post(route('apuntes.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U del docente', 'motor_id' => $this->motor->id])
            ->assertRedirect(route('apuntes.borrador', $g = GeneracionIa::query()->sole()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/chat') && str_contains($r['messages'][1]['content'], 'U del docente'));
        $this->assertSame('apuntes', $g->tarea);
        $this->post(route('apuntes.aprobar', $g))->assertRedirect();
        $m = Material::query()->sole();

        $this->get(route('apuntes.show', $m))->assertOk()
            ->assertSee('Si cambia su teléfono')->assertSee('<li>nada de listas en una celda</li>', false)->assertSee('CREATE TABLE repuesto')
            ->assertSee('Repetir datos invita a la inconsistencia.')->assertSee('La inconsistencia por repetición.')->assertSee('Guardar en el repositorio');
        $this->get(route('apuntes.show', ['material' => $m, 'version' => 'estudiante']))->assertOk()
            ->assertSee('¿Qué problema resuelve?')->assertDontSee('La inconsistencia por repetición.')->assertDontSee('Guardar en el repositorio');
    }

    #[Test]
    public function el_apunte_pasa_al_repositorio_y_sirve_de_material_de_referencia(): void
    {
        $m = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'apuntes', 'titulo' => 'Normalizar sin miedo',
            'contenido' => app(TextoInformativo::class)->normalizar($this->texto())]);

        $this->post(route('apuntes.repositorio', $m))->assertSessionHas('estado', fn ($s) => str_contains($s, 'quedó en el repositorio'));
        $c = Contenido::query()->sole();
        $this->assertSame('lectura', $c->tipo);
        $this->assertStringContainsString('## Primera forma normal', $c->texto_extraido);
        $this->assertStringContainsString('Idea clave: Repetir datos', $c->texto_extraido);
        Storage::disk('local')->assertExists($c->ruta);

        // No se duplica
        $this->post(route('apuntes.repositorio', $m))->assertSessionHas('estado', 'Este texto ya estaba en el repositorio de contenido.');
        $this->assertSame(1, Contenido::query()->count());

        // Ya aparece como material de referencia para otro generador y entra al prompt
        $this->get(route('ejemplos.index', $this->materia))->assertSee('Normalizar sin miedo');
        $this->post(route('ejemplos.preparar', $this->materia), ['dictado_id' => $this->dictado->id, 'temas' => [$this->norm->id], 'cantidad' => 2, 'dificultad' => 'mixta',
            'contenidos' => [$c->id], 'motor_id' => $this->motor->id])->assertSee('Si cambia su teléfono');
    }

    #[Test]
    public function un_borrador_invalido_se_corrige_y_otro_docente_no_ve_nada(): void
    {
        $this->ollamaResponde(json_encode($this->texto(['resumen' => []])), json_encode($this->texto(['titulo' => 'Corregido'])));
        $this->post(route('apuntes.preparar', $this->materia), $this->formulario(['temas' => [], 'tema' => 'Índices']))->assertOk()->assertSee('Índices');
        $this->post(route('apuntes.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U', 'motor_id' => $this->motor->id]);
        $g = GeneracionIa::query()->sole();
        $this->get(route('apuntes.borrador', $g))->assertSee('Falta el resumen')->assertSee('Pedir a la IA que lo corrija');
        $this->post(route('apuntes.corregir', $g))->assertRedirect();
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->put(route('apuntes.borrador.editar', $nueva), ['contenido' => json_encode($this->texto(['titulo' => 'A mano'])), 'aprobar' => '1'])->assertRedirect();
        $m = Material::query()->sole();
        $this->assertSame('A mano', $m->titulo);

        $this->get(route('apuntes.create', ['materia' => $this->materia, 'temas' => [$this->norm->id]]))->assertOk()->assertSee('Agregar sección');
        $this->get(route('ia.plantillas'))->assertSee('Capa 3 — Tarea: texto informativo o apunte');
        $this->get(route('proyectos.show', $m))->assertNotFound();
        $this->actingAs(User::factory()->create());
        $this->get(route('apuntes.show', $m))->assertForbidden();
        $this->post(route('apuntes.repositorio', $m))->assertForbidden();
    }
}
