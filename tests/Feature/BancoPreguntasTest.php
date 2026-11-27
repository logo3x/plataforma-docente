<?php

namespace Tests\Feature;

use App\Evaluacion\EditorBanco;
use App\Evaluacion\ImportadorBanco;
use App\Models\Artefacto;
use App\Models\Pregunta;
use App\Models\Rubrica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Banco de preguntas administrable desde la interfaz (13.5). */
class BancoPreguntasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Artefacto $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 1);
        $this->actingAs($this->docente);
        $u = $this->materia->temario()->create(['tipo' => 'unidad', 'titulo' => 'Lineales', 'orden' => 1]);
        $this->materia->temario()->create(['tipo' => 'tema', 'titulo' => 'Pilas', 'parent_id' => $u->id, 'orden' => 1]);
        $this->a = app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-BAN-P1', 'titulo' => 'Parcial 1',
            'tipo' => 'parcial', 'valor' => '5', 'configuracion' => ['reloj_minutos' => 50], 'items' => [
                ['tipo' => 'unica', 'peso' => '2', 'tema' => 'Pilas', 'enunciado' => '¿Qué plato se lava primero?', 'opciones' => ['a' => 'El primero', 'b' => 'El último'], 'correcta' => 'b'],
                ['tipo' => 'emparejar', 'peso' => '1', 'enunciado' => 'Empareje.', 'izquierda' => ['1' => 'Pila', '2' => 'Cola'], 'derecha' => ['a' => 'FIFO', 'b' => 'LIFO', 'c' => 'Árbol'], 'pares' => ['1' => 'b', '2' => 'a']],
                ['tipo' => 'abierta', 'peso' => '2', 'enunciado' => 'Explique una pila.', 'referencia' => 'Estructura LIFO.'],
            ]]);
    }

    private function banco(array $cambios = []): array
    {
        return array_replace(app(EditorBanco::class)->exportar($this->a, true), $cambios);
    }

    private function guardar(array $banco)
    {
        return $this->from(route('bancos.edit', $this->a))->put(route('bancos.update', $this->a), ['banco' => json_encode($banco)]);
    }

    #[Test]
    public function el_editor_muestra_el_banco_con_sus_respuestas_solo_al_docente(): void
    {
        $this->get(route('artefactos.index', $this->materia))->assertOk()->assertSee('Banco de preguntas')->assertSee('Crear un examen desde cero');
        $this->get(route('bancos.edit', $this->a))->assertOk()->assertSee('Banco de preguntas — Parcial 1')
            ->assertSee('¿Qué plato se lava primero?')->assertSee('Validar y guardar el banco')->assertDontSee('Solo lectura');

        $json = $this->get(route('bancos.descargar', $this->a))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="banco-ED-BAN-P1.json"')->json();
        $this->assertArrayNotHasKey(EditorBanco::ID, $json['items'][0]);
        $this->assertSame([], app(ImportadorBanco::class)->validar(['sigla' => 'OTRA'] + $json), 'Lo descargado se vuelve a importar tal cual');
        $this->assertSame(50, $json['configuracion']['reloj_minutos']);

        $this->actingAs(User::factory()->create());
        $this->get(route('bancos.edit', $this->a))->assertForbidden();
        $this->get(route('bancos.descargar', $this->a))->assertForbidden();
        $this->put(route('bancos.update', $this->a), ['banco' => '{}'])->assertForbidden();
    }

    #[Test]
    public function edita_en_su_lugar_y_cada_pregunta_conserva_su_identidad(): void
    {
        $abierta = $this->a->items()->with('pregunta')->get()[2];
        $rubrica = Rubrica::query()->create(['materia_id' => $this->materia->id, 'titulo' => 'Explicar', 'criterios' => []]);
        $abierta->pregunta->update(['rubrica_id' => $rubrica->id]);

        $b = $this->banco();
        $b['titulo'] = 'Parcial 1 corregido';
        $b['items'][0]['enunciado'] = 'En el casino, ¿qué plato se lava primero?';
        $b['items'][0]['opciones'] = ['a' => 'El de abajo', 'b' => 'El de arriba', 'c' => 'Cualquiera'];
        $b['items'][0]['correcta'] = 'b';
        unset($b['items'][1]);                                                       // quita el emparejar…
        $b['items'][] = ['tipo' => 'vf', 'peso' => '1', 'enunciado' => 'Una cola es FIFO.', 'correcta' => true, 'tema' => 'pilas'];   // …y agrega otra
        $b['items'] = array_values($b['items']);
        $this->guardar($b)->assertRedirect(route('bancos.edit', $this->a))->assertSessionHas('estado', 'Banco guardado: 3 ítems (1 nuevos, 1 quitados).');

        $this->a->refresh();
        $items = $this->a->items()->with('pregunta')->get();
        $this->assertSame('Parcial 1 corregido', $this->a->titulo);
        $this->assertSame(['unica', 'abierta', 'vf'], $items->pluck('pregunta.tipo')->all());
        $this->assertSame('b', $items[0]->pregunta->clave['correcta']);
        $this->assertSame('El de arriba', $items[0]->pregunta->enunciado['opciones']['b']);
        $this->assertSame($abierta->id, $items[1]->id, 'La abierta es la misma fila');
        $this->assertSame($rubrica->id, $items[1]->pregunta->rubrica_id, 'y conserva su rúbrica');
        $this->assertNotNull($items[2]->pregunta->temario_item_id, 'El tema se enlaza con el temario');
        $this->assertSame(50, $this->a->configuracion['reloj_minutos'], 'La configuración no se pierde');
        $this->assertSame(3, Pregunta::query()->count(), 'La pregunta quitada se borra si nadie más la usa');
    }

    #[Test]
    public function valida_igual_que_una_importacion_y_no_pierde_lo_escrito(): void
    {
        $b = $this->banco();
        $b['items'][0]['peso'] = '3';                                                // ya no suma 5
        $b['items'][1]['pares'] = ['1' => 'b', '2' => 'z'];
        $this->guardar($b)->assertRedirect()->assertSessionHas('banco_previo')->assertSessionHas('errores_banco');
        $this->followRedirects($this->guardar($b))->assertSee('No se guardó')->assertSee('Los pesos suman 6')->assertSee('cada elemento de «izquierda»');
        $this->assertSame('2.0000', (string) $this->a->items()->first()->peso, 'Nada cambió');

        Artefacto::query()->create(['materia_id' => $this->materia->id, 'sigla' => 'OCUPADA', 'tipo' => 'quiz', 'titulo' => 'Otra', 'configuracion' => []]);
        $this->followRedirects($this->guardar($this->banco(['sigla' => 'OCUPADA'])))->assertSee('Ya existe otra actividad con la sigla OCUPADA');
    }

    #[Test]
    public function con_entregas_queda_de_solo_lectura_y_se_edita_una_copia(): void
    {
        $actividad = $this->crearActividad($this->parcial, ['artefacto_id' => $this->a->id]);
        $this->crearEntrega($actividad, $this->grupo->estudiantes()->first());

        $this->get(route('bancos.edit', $this->a))->assertOk()->assertSee('Solo lectura')->assertSee('Hacer una copia para editar')->assertDontSee('Validar y guardar el banco');
        $this->guardar($this->banco(['titulo' => 'Cambiado']))->assertSessionHas('errores_banco');
        $this->assertSame('Parcial 1', $this->a->fresh()->titulo);

        $rubrica = Rubrica::query()->create(['materia_id' => $this->materia->id, 'titulo' => 'Explicar', 'criterios' => []]);
        $this->a->items()->with('pregunta')->get()[2]->pregunta->update(['rubrica_id' => $rubrica->id]);
        $this->post(route('bancos.duplicar', $this->a), ['sigla' => 'ED-BAN-P1-R', 'titulo' => 'Parcial 1 (repetición)'])->assertRedirect();
        $copia = Artefacto::query()->where('sigla', 'ED-BAN-P1-R')->sole();
        $this->assertSame(3, $copia->items()->count());
        $this->assertSame($this->a->id, $copia->origen_artefacto_id);           // 2.8.3
        $this->assertSame(50, $copia->configuracion['reloj_minutos']);
        $this->assertSame($rubrica->id, $copia->items()->with('pregunta')->get()[2]->pregunta->rubrica_id);
        $this->get(route('bancos.edit', $copia))->assertOk()->assertDontSee('Solo lectura');

        $this->post(route('bancos.duplicar', $this->a), ['sigla' => 'ED-BAN-P1-R', 'titulo' => 'x'])->assertSessionHasErrors('sigla');
    }

    #[Test]
    public function crea_un_examen_desde_cero_y_trae_preguntas_de_otros(): void
    {
        $this->get(route('bancos.create', $this->materia))->assertOk()->assertSee('Nuevo examen desde cero')
            ->assertSee('Traer preguntas de otros exámenes de la materia')->assertSee('¿Qué plato se lava primero?');
        $this->assertCount(3, app(EditorBanco::class)->deLaMateria($this->materia));
        $this->assertCount(0, app(EditorBanco::class)->deLaMateria($this->materia, $this->a));

        $nuevo = ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-Q1', 'titulo' => 'Quiz 1', 'tipo' => 'quiz', 'valor' => '2', 'configuracion' => [], 'items' => [
            ['tipo' => 'ordenar', 'version' => 'A', 'peso' => '1', 'enunciado' => 'Ordene.', 'pasos' => ['p1' => 'push', 'p2' => 'pop'], 'orden' => ['p1', 'p2']],
            ['tipo' => 'completar', 'version' => 'A', 'peso' => '1', 'enunciado' => 'Complete.', 'plantilla' => 'pila.___()', 'huecos' => [['pop', 'desapilar']]],
            ['tipo' => 'ordenar', 'version' => 'B', 'peso' => '1', 'enunciado' => 'Ordene B.', 'pasos' => ['p1' => 'enqueue', 'p2' => 'dequeue'], 'orden' => ['p1', 'p2']],
            ['tipo' => 'completar', 'version' => 'B', 'peso' => '1', 'enunciado' => 'Complete B.', 'plantilla' => 'cola.___()', 'huecos' => ['shift']],
        ]];
        $this->post(route('bancos.store', $this->materia), ['banco' => json_encode($nuevo)])->assertRedirect();
        $q = Artefacto::query()->where('sigla', 'ED-Q1')->sole();
        $this->assertSame(['A', 'B'], $q->versiones());
        $this->assertSame('2', $q->configuracion['valor']);

        $nuevo['sigla'] = 'ED-Q2';
        $nuevo['items'][3]['peso'] = '2';
        $this->followRedirects($this->from(route('bancos.create', $this->materia))->post(route('bancos.store', $this->materia), ['banco' => json_encode($nuevo)]))
            ->assertSee('No se guardó')->assertSee('mismos tipos de ítem con los mismos pesos')->assertSee('ED-Q2');
    }
}
