<?php

namespace Tests\Feature;

use App\Livewire\EditorTemario;
use App\Livewire\ListaChequeo;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\TemarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Plan curricular, temario, lista de chequeo y alcance por grupo (7.1). */
class TemarioTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private const TEMARIO = ['unidades' => [
        ['titulo' => 'Unidad 1: Fundamentos de la Web', 'resultados' => ['Construye páginas HTML válidas'],
            'temas' => [['titulo' => 'HTML y estructura', 'descripcion' => 'Etiquetas básicas'], ['titulo' => 'Tablas']]],
        ['titulo' => 'Unidad 2: Estructuras lineales', 'temas' => [['titulo' => 'Pilas'], ['titulo' => 'Colas']]],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 1);
        $this->actingAs($this->docente);
        ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'Local', 'driver' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'modelo' => 'm', 'es_local' => true, 'predeterminado' => true]);
    }

    private function ollama(string ...$respuestas): void
    {
        $cola = Http::sequence();
        foreach ($respuestas as $r) {
            $cola->push(['message' => ['content' => $r]]);
        }
        Http::fake(['*/api/tags' => Http::response([]), '*/api/chat' => $cola]);
    }

    private function plan()
    {
        return $this->materia->contenidos()->create(['titulo' => 'Microcurrículo ED', 'tipo' => 'plan_curricular', 'ruta' => 'x',
            'mime' => 'application/pdf', 'sha256' => str_repeat('c', 64), 'texto_extraido' => 'Unidad 1 Fundamentos... Unidad 2 Pilas y colas...']);
    }

    /** Aplica el temario de prueba directamente y devuelve los temas por título. */
    private function conTemario(string ...$despues): array
    {
        $this->ollama(json_encode(self::TEMARIO), ...$despues);
        $this->post(route('temario.extraer', $this->materia), ['contenido_id' => $this->plan()->id]);
        $this->post(route('temario.aplicar', GeneracionIa::query()->sole()), ['modo' => 'agregar']);

        return TemarioItem::query()->where('tipo', 'tema')->pluck('id', 'titulo')->all();
    }

    #[Test]
    public function extrae_el_temario_del_plan_como_borrador_y_lo_aplica(): void
    {
        $this->ollama("Este es el temario:\n```json\n".json_encode(self::TEMARIO, JSON_UNESCAPED_UNICODE)."\n```");
        $plan = $this->plan();

        $this->post(route('temario.extraer', $this->materia), ['contenido_id' => $plan->id, 'motor_id' => ConfigMotorIa::first()->id])->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'] ?? '', 'Unidad 2 Pilas y colas') && $r['format'] === 'json');
        $g = GeneracionIa::query()->sole();
        $this->assertSame([0, 'temario', 'borrador'], [TemarioItem::query()->count(), $g->tarea, $g->estado], 'Nada se aplica sin aprobación.');

        $this->get(route('temario.borrador', $g))->assertOk()->assertSee('Unidad 2: Estructuras lineales')->assertSee('Construye páginas HTML válidas');
        $this->post(route('temario.aplicar', $g), ['modo' => 'agregar'])->assertRedirect(route('temario.show', $this->materia));

        $this->assertSame(['Unidad 1: Fundamentos de la Web', 'Unidad 2: Estructuras lineales'],
            TemarioItem::query()->whereNull('parent_id')->orderBy('orden')->pluck('titulo')->all());
        $this->assertSame(['HTML y estructura', 'Tablas', 'Pilas', 'Colas'], TemarioItem::query()->where('tipo', 'tema')->orderBy('parent_id')->orderBy('orden')->pluck('titulo')->all());
        $this->assertSame(1, TemarioItem::query()->where('tipo', 'resultado')->count());
        $this->assertSame(['ia'], TemarioItem::query()->distinct()->pluck('origen')->all());
        $this->assertSame('aprobado', $g->fresh()->estado);
    }

    #[Test]
    public function un_borrador_invalido_se_corrige_a_mano(): void
    {
        $this->ollama(json_encode(['unidades' => [['titulo' => '', 'temas' => ['Pilas']]]]));
        $this->post(route('temario.extraer', $this->materia), ['contenido_id' => $this->plan()->id]);
        $g = GeneracionIa::query()->sole();

        $this->get(route('temario.borrador', $g))->assertSee('La unidad 1 no tiene título.');
        $this->post(route('temario.aplicar', $g), ['modo' => 'agregar'])->assertSessionHas('error_ia');

        $this->put(route('temario.editar', $g), ['borrador' => json_encode(['unidades' => [['titulo' => 'Unidad A', 'temas' => ['Pilas']]]])]);
        $this->post(route('temario.aplicar', $g), ['modo' => 'agregar'])->assertRedirect();
        $this->assertSame('Pilas', TemarioItem::query()->where('tipo', 'tema')->value('titulo'));
    }

    #[Test]
    public function no_reemplaza_un_temario_con_avance_marcado(): void
    {
        $temas = $this->conTemario(json_encode(self::TEMARIO));
        Livewire::test(ListaChequeo::class, ['grupo' => $this->grupo])->call('alternar', $temas['Pilas']);

        $this->post(route('temario.extraer', $this->materia), ['contenido_id' => $this->plan()->id]);
        $nuevo = GeneracionIa::query()->where('estado', 'borrador')->sole();

        $this->post(route('temario.aplicar', $nuevo), ['modo' => 'reemplazar'])
            ->assertSessionHas('error_ia', fn ($m) => str_contains($m, 'Ya hay temas marcados como vistos'));
        $this->assertSame(1, DB::table('avances_temario')->count());
    }

    #[Test]
    public function el_temario_se_arma_y_edita_a_mano(): void
    {
        $c = Livewire::test(EditorTemario::class, ['materia' => $this->materia])
            ->set('nuevaUnidad', 'Unidad 1: Arreglos')->call('agregarUnidad');
        $u = TemarioItem::query()->sole();

        $c->set("nuevoTema.{$u->id}", 'Recorridos')->call('agregarHijo', $u->id, 'tema')
            ->set("nuevoTema.{$u->id}", 'Búsqueda')->call('agregarHijo', $u->id, 'tema')
            ->set("nuevoResultado.{$u->id}", 'Recorre arreglos')->call('agregarHijo', $u->id, 'resultado');
        $busqueda = TemarioItem::query()->where('titulo', 'Búsqueda')->sole();
        $c->call('mover', $busqueda->id, -1)->call('renombrar', $busqueda->id, 'Búsqueda lineal')
            ->assertSee('Recorre arreglos');

        $this->assertSame(['Búsqueda lineal', 'Recorridos'], TemarioItem::query()->where('tipo', 'tema')->orderBy('orden')->pluck('titulo')->all());

        $c->call('eliminar', $u->id);
        $this->assertSame(0, TemarioItem::query()->count(), 'Eliminar la unidad elimina sus temas.');
    }

    #[Test]
    public function la_lista_de_chequeo_es_propia_de_cada_grupo(): void
    {
        $temas = $this->conTemario();
        $nocturno = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);

        Livewire::test(ListaChequeo::class, ['grupo' => $this->grupo])
            ->call('alternar', $temas['HTML y estructura'])->call('alternar', $temas['Tablas'])
            ->assertSee('2 de 4');
        Livewire::test(ListaChequeo::class, ['grupo' => $nocturno])->assertSee('0 de 4')
            ->call('alternar', $temas['Pilas'])->assertSee('1 de 4');
        Livewire::test(ListaChequeo::class, ['grupo' => $this->grupo])->call('alternar', $temas['Tablas'])->assertSee('1 de 4');

        $this->assertSame(1, DB::table('avances_temario')->where('grupo_id', $this->grupo->id)->count());
        $this->assertSame(now()->toDateString(), DB::table('avances_temario')->where('grupo_id', $nocturno->id)->value('cubierto_en'));
    }

    #[Test]
    public function la_ia_recibe_lo_que_el_grupo_vio_y_lo_que_no_debe_evaluar(): void
    {
        $temas = $this->conTemario();
        Livewire::test(ListaChequeo::class, ['grupo' => $this->grupo])->call('alternar', $temas['HTML y estructura'])->call('alternar', $temas['Pilas']);

        $p = $this->post(route('generacion.preparar', $this->materia), [
            'dictado_id' => $this->dictado->id, 'tipo' => 'quiz', 'grupo' => 'Diurno', 'valor' => '5',
            'temas' => [$temas['Pilas']], 'tema' => 'con ejemplos de restaurante', 'cantidades' => ['unica' => 2],
        ])->assertOk()->viewData('prompt');

        $this->assertStringContainsString('Tema:               Pilas. con ejemplos de restaurante', $p['usuario']);
        $this->assertStringContainsString('Grupo Diurno. Ya vio: HTML y estructura, Pilas. Aún no ha visto (no evaluar): Tablas, Colas.', $p['sistema']);
    }

    #[Test]
    public function las_sugerencias_se_guardan_aparte_y_el_docente_decide(): void
    {
        $ajeno = Materia::query()->create(['user_id' => User::factory()->create()->id, 'nombre' => 'Ajena'])
            ->temario()->create(['tipo' => 'tema', 'titulo' => 'Ajeno']);
        // Los ids de los temas se conocen al aplicar; la respuesta usa el primero creado (unidad, 2 temas, resultado, unidad, «Pilas» = +5)
        $primero = (int) TemarioItem::query()->max('id') + 1;
        $temas = $this->conTemario(json_encode(['sugerencias' => [
            ['id' => $primero + 5, 'sugerencia' => 'Empiece con la pila de platos; taller de invertir un texto.'],
            ['id' => $ajeno->id, 'sugerencia' => 'No debe aplicarse'],
        ]]));
        $this->assertSame($primero + 5, $temas['Pilas']);

        $this->post(route('temario.sugerir', $this->materia))->assertSessionHas('estado', fn ($m) => str_contains($m, '1 sugerencia'));
        $this->assertNull($ajeno->fresh()->sugerencia);

        Livewire::test(EditorTemario::class, ['materia' => $this->materia])
            ->assertSee('Sugerencia de la IA')->call('aceptarSugerencia', $temas['Pilas']);
        $pilas = TemarioItem::query()->find($temas['Pilas']);
        $this->assertSame([null, 'Empiece con la pila de platos; taller de invertir un texto.'], [$pilas->sugerencia, $pilas->descripcion]);
    }

    #[Test]
    public function el_registro_muestra_la_lista_y_todo_es_del_docente_dueno(): void
    {
        $temas = $this->conTemario();
        $this->get(route('registro', $this->grupo))->assertSee('Temario de Diurno')->assertSee('0 de 4');

        $this->actingAs(User::factory()->create());
        $this->get(route('temario.show', $this->materia))->assertForbidden();
        Livewire::test(ListaChequeo::class, ['grupo' => $this->grupo])->assertForbidden();
        Livewire::test(EditorTemario::class, ['materia' => $this->materia])->assertForbidden();
    }
}
