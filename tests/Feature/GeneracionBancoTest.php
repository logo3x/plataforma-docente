<?php

namespace Tests\Feature;

use App\Evaluacion\GeneradorBanco;
use App\Evaluacion\ImportadorBanco;
use App\Models\Artefacto;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\PlantillaPrompt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;
use ZipArchive;

/** Generación del banco con IA (7.4, 8): prompt de tres capas, borrador, corrección y aprobación. */
class GeneracionBancoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private ConfigMotorIa $motor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 1);
        $this->actingAs($this->docente);
        $this->motor = ConfigMotorIa::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'Ollama local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'qwen3:14b', 'es_local' => true, 'predeterminado' => true,
        ]);
    }

    private function bancoIA(array $cambios = []): array
    {
        return array_replace([
            'formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'LO-QUE-INVENTE-LA-IA', 'titulo' => 'Quiz de arreglos', 'tipo' => 'quiz', 'valor' => '5.0',
            'items' => [
                ['tipo' => 'unica', 'peso' => '2.5', 'enunciado' => '¿Qué devuelve [1,2,3].length?', 'opciones' => ['a' => '2', 'b' => '3'], 'correcta' => 'b', 'justificacion' => 'Cuenta elementos.'],
                ['tipo' => 'vf', 'peso' => '2.5', 'enunciado' => 'push agrega al final.', 'correcta' => true],
            ],
        ], $cambios);
    }

    /** Ollama simulado que responde con el texto dado (como hacen los modelos: a veces con ```json). */
    private function ollamaResponde(string ...$respuestas): void
    {
        $cola = Http::sequence();
        foreach ($respuestas as $r) {
            $cola->push(['message' => ['content' => $r], 'prompt_eval_count' => 900, 'eval_count' => 400]);
        }
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => []]), 'ollama.test:11434/api/chat' => $cola]);
    }

    private function formulario(array $cambios = []): array
    {
        return array_replace([
            'dictado_id' => $this->dictado->id, 'tipo' => 'quiz', 'tema' => 'Arreglos en JavaScript', 'grupo' => 'Diurno', 'valor' => '5.0',
            'cantidades' => ['unica' => 1, 'vf' => 1, 'reto' => 0], 'obligatorio' => 'situaciones problema', 'excluido' => 'CSS',
            'contenidos' => [], 'motor_id' => $this->motor->id,
        ], $cambios);
    }

    #[Test]
    public function el_prompt_lleva_las_tres_capas_el_material_y_lo_que_no_debe_repetir(): void
    {
        $this->docente->update(['perfil_ia' => ['ciudad' => 'Barrancabermeja']]);
        $this->materia->update(['estilo' => ['tono' => 'de taller, sin metáforas', 'alcance_grupo' => 'Diurno hasta tablas']]);
        $c = $this->materia->contenidos()->create(['titulo' => 'Guía de arreglos', 'tipo' => 'guia', 'ruta' => 'x', 'mime' => 'text/plain',
            'sha256' => str_repeat('a', 64), 'texto_extraido' => 'Un arreglo guarda una lista ordenada de valores.']);
        app(ImportadorBanco::class)->importar($this->materia, json_decode(file_get_contents(base_path('docs/ejemplos/banco-ejemplo.json')), true));

        $r = $this->post(route('generacion.preparar', $this->materia), $this->formulario(['contenidos' => [$c->id]]))->assertOk();
        $p = $r->viewData('prompt');

        $this->assertStringContainsString('Ciudad:             Barrancabermeja', $p['sistema']);                // capa 1 con perfil
        $this->assertStringContainsString('Corte 1 = 30 %, Corte 2 = 30 %, Corte 3 = 40 %', $p['sistema']);     // capa 1 automática
        $this->assertStringContainsString('Tono:                  de taller, sin metáforas', $p['sistema']);    // capa 2
        $this->assertStringContainsString('Tema:               Arreglos en JavaScript', $p['usuario']);        // capa 3
        $this->assertStringContainsString('1 de tipo «unica» (Selección única); 1 de tipo «vf»', $p['usuario']);
        $this->assertStringContainsString('Un arreglo guarda una lista ordenada', $p['usuario']);              // material
        $this->assertStringContainsString('No repita estas preguntas', $p['usuario']);                          // memoria (7.3)
        $this->assertStringContainsString('"sigla":"910303-2026A-Q1"', $p['usuario']);
        $this->assertSame([['id' => $c->id, 'titulo' => 'Guía de arreglos', 'sha256' => str_repeat('a', 64), 'caracteres' => 48]], $p['contenidos']);
        $this->assertFalse($p['recortado']);
    }

    #[Test]
    public function el_material_largo_se_recorta_y_se_avisa(): void
    {
        config(['ia.max_contexto_caracteres' => 1000]);
        $c = $this->materia->contenidos()->create(['titulo' => 'Largo', 'tipo' => 'lectura', 'ruta' => 'x', 'mime' => 'text/plain',
            'sha256' => str_repeat('b', 64), 'texto_extraido' => str_repeat('palabra ', 500)]);

        $this->post(route('generacion.preparar', $this->materia), $this->formulario(['contenidos' => [$c->id]]))
            ->assertOk()->assertSee('se recortó');
    }

    #[Test]
    public function genera_un_borrador_valido_lo_aprueba_y_deja_trazabilidad(): void
    {
        $this->ollamaResponde("Claro, aquí está:\n```json\n".json_encode($this->bancoIA(), JSON_UNESCAPED_UNICODE)."\n```");
        $this->post(route('generacion.preparar', $this->materia), $this->formulario());

        $this->post(route('generacion.generar', $this->materia), ['sistema' => 'S editado por el docente', 'usuario' => 'U', 'motor_id' => $this->motor->id])
            ->assertRedirect();
        $g = GeneracionIa::query()->sole();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat') && $r['format'] === 'json' && $r['messages'][0]['content'] === 'S editado por el docente');
        $this->assertSame('borrador', $g->estado);
        $this->assertSame('910303-2026A-Q1', json_decode($g->borrador, true)['sigla'], 'La sigla la pone la plataforma, no la IA.');
        $this->get(route('generacion.show', $g))->assertOk()->assertSee('El borrador es válido')->assertSee('¿Qué devuelve [1,2,3].length?')->assertSee('b) 3 ✓ correcta');

        $this->post(route('generacion.aprobar', $g))->assertRedirect(route('artefactos.index', $this->materia));

        $a = Artefacto::query()->sole();
        $g->refresh();
        $this->assertSame(['aprobado', $a->id, 'banco', $this->materia->id], [$g->estado, $g->resultado_id, $g->tarea, $g->materia_id]);
        $this->assertSame(2, $a->items()->count());
        $this->post(route('generacion.aprobar', $g))->assertStatus(409);
    }

    #[Test]
    public function un_borrador_invalido_se_ajusta_a_mano_o_con_ayuda(): void
    {
        $malo = $this->bancoIA(['items' => [
            ['tipo' => 'unica', 'peso' => '2', 'enunciado' => 'A', 'opciones' => ['a' => '1', 'b' => '2'], 'correcta' => 'b'],
            ['tipo' => 'vf', 'peso' => '1', 'enunciado' => 'B', 'correcta' => true],
            ['tipo' => 'vf', 'peso' => '1', 'enunciado' => 'C', 'correcta' => false],
        ]]);
        $this->ollamaResponde(json_encode($malo));
        $this->post(route('generacion.preparar', $this->materia), $this->formulario());
        $this->post(route('generacion.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U']);
        $g = GeneracionIa::query()->sole();

        $this->get(route('generacion.show', $g))->assertSee('Los pesos suman 4 y el valor de la actividad es 5.0');

        // Ayuda determinista: 2/1/1 → 2.5/1.25/1.25
        $this->post(route('generacion.reescalar', $g));
        $this->assertSame(['2.5', '1.25', '1.25'], array_column(json_decode($g->fresh()->borrador, true)['items'], 'peso'));
        $this->assertSame([], app(GeneradorBanco::class)->errores($g->fresh()->borrador));

        // Edición manual: la vía manual nunca se cierra
        $b = json_decode($g->fresh()->borrador, true);
        $b['items'][1]['enunciado'] = 'Pregunta corregida por el docente';
        $this->put(route('generacion.editar', $g), ['borrador' => json_encode($b)]);
        $this->assertStringContainsString('Pregunta corregida por el docente', $g->fresh()->borrador);
    }

    #[Test]
    public function la_ia_puede_corregir_su_borrador_con_los_errores(): void
    {
        $malo = $this->bancoIA();
        $malo['items'][0]['correcta'] = 'z';
        $this->ollamaResponde(json_encode($malo), json_encode($this->bancoIA()));
        $this->post(route('generacion.preparar', $this->materia), $this->formulario());
        $this->post(route('generacion.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U']);
        $original = GeneracionIa::query()->sole();

        $this->post(route('generacion.corregir', $original))->assertRedirect();

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'la respuesta correcta no está entre las opciones'));
        $nueva = GeneracionIa::query()->where('estado', 'borrador')->sole();
        $this->assertSame('descartado', $original->fresh()->estado);
        $this->assertSame([], app(GeneradorBanco::class)->errores($nueva->borrador));
        $this->assertSame('910303-2026A-Q1', json_decode($nueva->borrador, true)['sigla']);
    }

    #[Test]
    public function si_el_motor_no_responde_avisa_y_no_usa_otro(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);
        $this->post(route('generacion.preparar', $this->materia), $this->formulario());

        $this->post(route('generacion.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U'])
            ->assertRedirect(route('generacion.create', $this->materia))
            ->assertSessionHas('error_ia', fn ($m) => str_contains($m, 'No se usó ningún otro motor'));
        $this->assertSame(0, GeneracionIa::query()->count());
    }

    #[Test]
    public function la_respuesta_sin_json_queda_como_borrador_editable(): void
    {
        $this->ollamaResponde('Lo siento, no puedo generar eso ahora.');
        $this->post(route('generacion.preparar', $this->materia), $this->formulario());
        $this->post(route('generacion.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U']);

        $this->get(route('generacion.show', GeneracionIa::query()->sole()))
            ->assertOk()->assertSee('no es JSON válido')->assertSee('Lo siento, no puedo generar eso ahora.');
    }

    #[Test]
    public function el_repositorio_extrae_texto_de_txt_y_word(): void
    {
        $docx = tempnam(sys_get_temp_dir(), 'd').'.docx';
        $zip = new ZipArchive;
        $zip->open($docx, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Pilas y colas</w:t></w:r></w:p><w:p><w:r><w:t>LIFO &amp; FIFO</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $txt = tempnam(sys_get_temp_dir(), 't');
        file_put_contents($txt, mb_convert_encoding("Árboles binarios\n\n\n\nRecorridos", 'Windows-1252', 'UTF-8'));

        $this->post(route('contenido.store', $this->materia), ['tipo' => 'guia', 'archivos' => [
            new UploadedFile($docx, 'Unidad 3.docx', null, null, true),
            new UploadedFile($txt, 'notas.txt', 'text/plain', null, true),
        ]])->assertSessionHas('estado', '2 archivo(s) cargado(s).');

        $textos = Contenido::query()->orderBy('id')->pluck('texto_extraido', 'titulo');
        $this->assertSame("Pilas y colas\nLIFO & FIFO", $textos['Unidad 3']);
        $this->assertSame("Árboles binarios\n\nRecorridos", $textos['notas']);

        // 7.11: el original se descarga tal cual; el mismo archivo cargado dos veces comparte ruta y borrar uno no borra el otro
        $notas = Contenido::query()->where('titulo', 'notas')->sole();
        $original = file_get_contents($txt);
        $this->assertSame($original, $this->get(route('contenido.descargar', $notas))->assertOk()->assertDownload('notas.txt')->streamedContent());
        $this->post(route('contenido.store', $this->materia), ['tipo' => 'guia', 'archivos' => [new UploadedFile($txt, 'notas.txt', 'text/plain', null, true)]])
            ->assertSessionHas('avisos', fn ($a) => in_array('notas.txt: ya estaba cargado sin cambios; no se duplicó.', $a, true));
        $this->post(route('contenido.store', $this->materia), ['tipo' => 'guia', 'archivos' => [new UploadedFile($txt, 'notas de otro curso.txt', 'text/plain', null, true)]]);
        $this->delete(route('contenido.destroy', $notas))->assertRedirect();
        $copia = Contenido::query()->where('titulo', 'notas de otro curso')->sole();
        $this->assertSame($original, $this->get(route('contenido.descargar', $copia))->assertOk()->streamedContent());
        $this->actingAs(User::factory()->create());
        $this->get(route('contenido.descargar', $copia))->assertForbidden();
        $this->actingAs($this->docente);

        // 9.1: el mismo material, cambiado, entra como versión nueva
        file_put_contents($txt, 'Recorridos en profundidad y en amplitud, con ejemplos.');
        $this->post(route('contenido.store', $this->materia), ['tipo' => 'guia', 'archivos' => [new UploadedFile($txt, 'notas de otro curso.txt', 'text/plain', null, true)]])
            ->assertSessionHas('estado', '1 archivo(s) cargado(s).');
        $this->assertSame([1, 2], Contenido::query()->where('titulo', 'notas de otro curso')->orderBy('id')->pluck('version')->all());
        $this->get(route('contenido.index', $this->materia))->assertSee('versión 2');
    }

    #[Test]
    public function el_material_se_asigna_a_un_tema_y_se_ofrece_por_tema_al_generar(): void
    {
        // 7.2
        $u = $this->materia->temario()->create(['tipo' => 'unidad', 'titulo' => 'Estructuras lineales', 'orden' => 1]);
        $pilas = $this->materia->temario()->create(['tipo' => 'tema', 'titulo' => 'Pilas', 'parent_id' => $u->id, 'orden' => 1]);
        $guia = $this->materia->contenidos()->create(['titulo' => 'Guía de pilas', 'tipo' => 'guia', 'ruta' => 'x', 'mime' => 'text/plain', 'sha256' => str_repeat('b', 64), 'texto_extraido' => 'LIFO']);

        $this->get(route('contenido.index', $this->materia))->assertOk()->assertSee('Estructuras lineales (toda la unidad)');
        $this->put(route('contenido.tema', $guia), ['temario_item_id' => $pilas->id])->assertSessionHas('estado', '«Guía de pilas» quedó en «Pilas».');
        $this->assertSame($pilas->id, $guia->fresh()->temario_item_id);

        // El generador marca el material del tema elegido (el atributo lo usa el script común) y dice de qué tema es
        $this->get(route('generacion.create', $this->materia))->assertOk()
            ->assertSee('data-tema="'.$pilas->id.'"', false)->assertSee('data-unidad="'.$u->id.'" value=', false)->assertSee('· Pilas');

        // Solo temas de la misma materia
        $otra = Materia::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Otra', 'codigo' => 'OT1']);
        $ajeno = $otra->temario()->create(['tipo' => 'tema', 'titulo' => 'Ajeno', 'orden' => 1]);
        $this->put(route('contenido.tema', $guia), ['temario_item_id' => $ajeno->id])->assertStatus(422);
        $this->put(route('contenido.tema', $guia), ['temario_item_id' => ''])->assertSessionHas('estado');
        $this->assertNull($guia->fresh()->temario_item_id);
    }

    #[Test]
    public function plantillas_versionadas_que_cambian_el_prompt(): void
    {
        $this->post(route('ia.plantillas.guardar'), ['clave' => 'materia', 'cuerpo' => 'MATERIA PROPIA: {{materia}}']);
        $this->post(route('ia.plantillas.guardar'), ['clave' => 'materia', 'cuerpo' => 'MATERIA V2: {{materia}}']);

        $p = $this->post(route('generacion.preparar', $this->materia), $this->formulario())->viewData('prompt');
        $this->assertStringContainsString('MATERIA V2: Estructura de Datos', $p['sistema']);
        $this->assertSame(2, $p['plantillas']['materia']);

        // 8.4.1: un campo que no existe se rechaza al guardar, no al generar
        $this->post(route('ia.plantillas.guardar'), ['clave' => 'materia', 'cuerpo' => 'ROTA: {{no_existe}}'])
            ->assertSessionHasErrors(['cuerpo' => 'La plantilla usa campos que no existen: {{no_existe}}. Los disponibles son {{materia}}, {{codigo_materia}}, {{unidades}}, {{alcance_grupo}}, {{herramientas}}, {{modalidad}}, {{tono}}, {{tipo_verificacion}}, {{glosario}}.']);
        $this->assertSame(2, $this->post(route('generacion.preparar', $this->materia), $this->formulario())->viewData('prompt')['plantillas']['materia']);

        // 8.4.4: volver a la versión 1 la deja como versión 3, sin borrar la historia
        $v1 = PlantillaPrompt::query()->where('clave', 'materia')->where('version', 1)->sole();
        $this->get(route('ia.plantillas'))->assertSee('Versiones anteriores (1)')->assertSee('Volver a esta versión');
        $this->post(route('ia.plantillas.volver'), ['version_id' => $v1->id])->assertSessionHas('estado', 'Plantilla «materia»: se volvió a la versión 1 (queda como versión 3).');
        $this->assertStringContainsString('MATERIA PROPIA: Estructura de Datos', $this->post(route('generacion.preparar', $this->materia), $this->formulario())->viewData('prompt')['sistema']);
        $this->actingAs(User::factory()->create());
        $this->post(route('ia.plantillas.volver'), ['version_id' => $v1->id])->assertNotFound();
    }

    #[Test]
    public function los_motores_en_la_nube_nunca_quedan_como_locales(): void
    {
        $this->post(route('ia.motores.store'), ['nombre' => 'Claude', 'driver' => 'anthropic', 'modelo' => 'claude-opus-5-5', 'api_key' => 'sk-x', 'base_url' => 'http://falso.local']);
        $m = ConfigMotorIa::query()->where('driver', 'anthropic')->sole();

        $this->assertFalse($m->es_local);
        $this->assertNotSame('sk-x', DB::table('motores_ia')->where('id', $m->id)->value('api_key'));
    }

    #[Test]
    public function otro_docente_no_ve_ni_usa_nada_de_esto(): void
    {
        $this->ollamaResponde(json_encode($this->bancoIA()));
        $this->post(route('generacion.preparar', $this->materia), $this->formulario());
        $this->post(route('generacion.generar', $this->materia), ['sistema' => 'S', 'usuario' => 'U']);
        $g = GeneracionIa::query()->sole();

        $this->actingAs(User::factory()->create());
        $this->get(route('generacion.create', $this->materia))->assertForbidden();
        $this->get(route('generacion.show', $g))->assertForbidden();
        $this->post(route('generacion.aprobar', $g))->assertForbidden();
        $this->get(route('contenido.index', $this->materia))->assertForbidden();
        $this->post(route('ia.motores.probar', $this->motor))->assertForbidden();
    }
}
