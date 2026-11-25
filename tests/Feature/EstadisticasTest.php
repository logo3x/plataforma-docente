<?php

namespace Tests\Feature;

use App\Calificacion\EstadisticasActividad;
use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Livewire\ListaChequeo;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\ArtefactoPregunta;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\NotaCambio;
use App\Models\TemarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/**
 * Estadísticas de grupo y análisis de ítems (6.10), anulación de ítem (6.5.5) y desempeño por tema.
 *
 * Doce estudiantes: seis «fuertes» y seis «débiles». Ítem 1 lo aciertan solo los fuertes (discrimina),
 * ítem 2 lo aciertan todos (fácil, no discrimina), ítem 3 nadie (todos eligen el mismo distractor),
 * ítem 4 (peso 2) solo los fuertes. Fuertes: 1+1+0+2 = 4.0 · Débiles: 0+1+0+0 = 1.0.
 */
class EstadisticasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private const BANCO = [
        'formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-EST-Q1', 'titulo' => 'Quiz de estadística', 'valor' => '5',
        'items' => [
            ['tipo' => 'unica', 'tema' => 'ARREGLOS ', 'peso' => '1', 'enunciado' => '¿Qué devuelve length?', 'opciones' => ['a' => 'índice', 'b' => 'cantidad'], 'correcta' => 'b'],
            ['tipo' => 'vf', 'tema' => 'Funciones', 'peso' => '1', 'enunciado' => 'Una función puede retornar un valor.', 'correcta' => true],
            ['tipo' => 'unica', 'tema' => 'arreglos', 'peso' => '1', 'enunciado' => 'Pregunta ambigua', 'opciones' => ['a' => 'x', 'b' => 'y', 'c' => 'z'], 'correcta' => 'a'],
            ['tipo' => 'vf', 'tema' => 'Funciones', 'peso' => '2', 'enunciado' => 'Sin return devuelve null.', 'correcta' => false],
        ],
    ];

    private Actividad $actividad;

    /** @var array<int, Estudiante> */
    private array $estudiantes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);

        $u = $this->materia->temario()->create(['tipo' => 'unidad', 'titulo' => 'Unidad 1']);
        foreach (['Arreglos', 'Funciones'] as $i => $t) {
            $this->materia->temario()->create(['tipo' => 'tema', 'titulo' => $t, 'parent_id' => $u->id, 'orden' => $i]);
        }

        app(ImportadorBanco::class)->importar($this->materia, self::BANCO);
        $this->actividad = $this->aplicar();
        for ($i = 0; $i < 12; $i++) {
            $this->estudiantes[] = $this->crearEstudiante((string) (20000 + $i), sprintf('EST%02d', $i), 'X');
        }
        $this->entregar($this->actividad, $this->estudiantes);
    }

    private function aplicar(): Actividad
    {
        $a = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Quiz', 'valor_max' => '5']);
        $a->grupos()->attach($this->grupo->id);

        return $a;
    }

    /** @param array<int, Estudiante> $estudiantes los primeros seis son débiles, los demás fuertes */
    private function entregar(Actividad $a, array $estudiantes): void
    {
        $ids = $a->artefacto->items()->orderBy('orden')->pluck('id')->all();
        $archivos = [];
        foreach ($estudiantes as $i => $e) {
            $fuerte = $i >= 6;
            $carga = ['sigla' => 'ED-EST-Q1', 'artefacto' => $a->artefacto->uuid, 'documento' => $e->documento, 'nombre' => $e->nombreCompleto(),
                'integrantes' => [], 'inicio' => now()->subMinutes(30)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual',
                'respuestas' => [$ids[0] => $fuerte ? 'b' : 'a', $ids[1] => true, $ids[2] => 'c', $ids[3] => $fuerte ? false : true],
                'sesion' => ['salidas' => [], 'pegados' => 0]];
            $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'ED-EST-Q1', 'artefacto' => $a->artefacto->uuid,
                'documento' => $e->documento, 'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))];
            $ruta = tempnam(sys_get_temp_dir(), 'e');
            file_put_contents($ruta, json_encode($sobre));
            $archivos[] = new UploadedFile($ruta, "{$e->documento}.json", 'application/json', null, true);
        }
        $this->post(route('calificador.subir', $a), ['entregas' => $archivos])->assertSessionHasNoErrors();
    }

    private function item(int $n): ArtefactoPregunta
    {
        return Artefacto::query()->sole()->items()->orderBy('orden')->get()[$n - 1];
    }

    #[Test]
    public function resume_el_grupo_en_la_escala_del_dictado(): void
    {
        $r = (new EstadisticasActividad($this->actividad))->resumen();

        $this->assertSame([12, '2.5', '4', '1', 6, 6], [$r['n'], $r['promedio'], $r['maxima'], $r['minima'], $r['aprobados'], $r['reprobados']]);
        $this->assertSame([0, 6, 0, 0, 6], array_column($r['rangos'], 'cantidad'));
        $this->assertSame(['0.0', '1.0'], [$r['rangos'][0]['desde'], $r['rangos'][0]['hasta']]);
    }

    #[Test]
    public function analiza_acierto_discriminacion_y_marca_candidatos_a_revision(): void
    {
        $items = (new EstadisticasActividad($this->actividad))->items();

        $this->assertSame([0.5, 1.0, 0.0, 0.5], array_column($items, 'acierto'));
        $this->assertSame([1.0, 0.0, 0.0, 1.0], array_column($items, 'discriminacion'));
        $this->assertSame([], $items[0]['marcas'], 'El ítem 1 funciona: separa a fuertes de débiles.');
        $this->assertStringContainsString('Casi todos aciertan', implode(' ', $items[1]['marcas']));
        $this->assertStringContainsString('Acierto muy bajo', implode(' ', $items[2]['marcas']));
        // El distractor que eligió todo el grupo salta a la vista
        $this->assertSame(['a' => 0, 'b' => 0, 'c' => 12], array_map(fn ($o) => $o['veces'], array_intersect_key($items[2]['opciones'], array_flip(['a', 'b', 'c']))));
        $this->assertTrue($items[2]['opciones']['a']['correcta']);
        $this->assertSame(12, $items[1]['opciones']['true']['veces']);
    }

    #[Test]
    public function con_pocas_entregas_no_calcula_discriminacion(): void
    {
        $sola = $this->aplicar();
        $this->entregar($sola, [$this->estudiantes[6]]);

        $items = (new EstadisticasActividad($sola))->items();
        $this->assertSame([null, null, null, null], array_column($items, 'discriminacion'));
        $this->assertSame([[], [], [], []], array_column($items, 'marcas'), 'Con una sola entrega, marcar ítems es ruido.');
    }

    #[Test]
    public function enlaza_los_temas_y_marca_el_bajo_desempeno_en_la_lista_de_chequeo(): void
    {
        $temas = TemarioItem::query()->where('tipo', 'tema')->pluck('id', 'titulo');
        $this->assertSame([$temas['Arreglos'], $temas['Funciones'], $temas['Arreglos'], $temas['Funciones']],
            Artefacto::query()->sole()->items()->orderBy('orden')->get()->map(fn ($i) => $i->pregunta->temario_item_id)->all(),
            'El «tema» del banco se enlaza con el temario sin importar mayúsculas ni espacios.');

        // Arreglos: 6 de 24 puntos posibles = 25 % · Funciones: (12 + 12) / (12 + 24) = 67 %
        $porTema = (new EstadisticasActividad($this->actividad, $this->grupo))->porTema();
        $this->assertSame([0.25, 0.667], [$porTema[$temas['Arreglos']]['acierto'], $porTema[$temas['Funciones']]['acierto']]);

        Livewire::test(ListaChequeo::class, ['grupo' => $this->grupo])
            ->assertSee('⚠ bajo desempeño (25 %): reforzar')->assertSee('acierto 67 %');
    }

    #[Test]
    public function anular_un_item_recalcula_y_actualiza_el_registro_con_traza(): void
    {
        $this->post(route('calificador.registro', $this->actividad));
        $this->assertSame('1.0000', Nota::query()->where('estudiante_id', $this->estudiantes[0]->id)->value('valor'));

        // El ítem 3 (ambiguo) se da por bueno a todos: +1
        $this->post(route('calificador.items.anular', $this->item(3)), [
            'actividad_id' => $this->actividad->id, 'regla' => 'dar_por_buena', 'motivo' => 'Enunciado ambiguo: admite dos respuestas',
        ])->assertSessionHas('estado', fn ($m) => str_contains($m, '12 nota(s) del registro se recalcularon'));

        $this->assertSame('2.0000', Nota::query()->where('estudiante_id', $this->estudiantes[0]->id)->value('valor'));
        $this->assertSame('5.0000', Nota::query()->where('estudiante_id', $this->estudiantes[6]->id)->value('valor'));
        $this->assertStringContainsString('Anulación del ítem 3: Enunciado ambiguo', NotaCambio::query()->latest('id')->value('motivo'));
        $this->get(route('calificador.detalle', $this->actividad->entregas()->first()))->assertSee('Ítem anulado para todo el grupo')->assertSee('se dio por bueno a todos')->assertSee('Enunciado ambiguo: admite dos respuestas');

        // El análisis ya no lo marca para revisión
        $this->assertSame([], (new EstadisticasActividad($this->actividad))->items()[2]['marcas']);

        // Retirar y reescalar: débiles (1 + 0 + 0) / 4 × 5 = 1.25
        $this->post(route('calificador.items.restaurar', $this->item(3)), ['actividad_id' => $this->actividad->id]);
        $this->assertSame('1.0000', Nota::query()->where('estudiante_id', $this->estudiantes[0]->id)->value('valor'));
        $this->post(route('calificador.items.anular', $this->item(3)), ['actividad_id' => $this->actividad->id, 'regla' => 'retirar_y_reescalar', 'motivo' => 'Se retira por ambiguo']);
        $this->assertSame('1.2500', Nota::query()->where('estudiante_id', $this->estudiantes[0]->id)->value('valor'));
    }

    #[Test]
    public function la_anulacion_reprocesa_todas_las_actividades_que_aplicaron_el_examen(): void
    {
        $nocturno = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);
        $otra = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Quiz noche', 'valor_max' => '5']);
        $otra->grupos()->attach($nocturno->id);
        $luis = $this->dictado->estudiantes()->create(['documento' => '30000', 'apellidos' => 'NOCHE', 'nombres' => 'LUIS']);
        $nocturno->estudiantes()->attach($luis->id);
        $this->entregar($otra, [$luis]);
        $this->post(route('calificador.registro', $this->actividad));
        $this->post(route('calificador.registro', $otra));

        $this->post(route('calificador.items.anular', $this->item(3)), ['actividad_id' => $this->actividad->id, 'regla' => 'dar_por_buena', 'motivo' => 'Ambiguo en ambas jornadas']);

        $this->assertSame('2.0000', Nota::query()->where('estudiante_id', $luis->id)->value('valor'));
    }

    #[Test]
    public function la_pagina_es_anonima_proyectable_y_exportable(): void
    {
        $this->get(route('calificador.estadisticas', $this->actividad))->assertOk()
            ->assertSee('Análisis por ítem')->assertSee('Pregunta ambigua')->assertSee('Anular ítem')->assertDontSee('EST00');
        $this->get(route('calificador.estadisticas', [$this->actividad, 'proyectar' => 1]))->assertOk()
            ->assertDontSee('Acciones del docente')->assertDontSee('Anular ítem');

        $csv = $this->get(route('calificador.estadisticas.csv', $this->actividad))->assertOk()->streamedContent();
        $this->assertStringContainsString('3;unica;Arreglos;"Pregunta ambigua";12;0;0;No;', $csv);
        $this->assertStringContainsString('Promedio;2.5', $csv);
    }

    #[Test]
    public function solo_el_docente_dueno(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('calificador.estadisticas', $this->actividad))->assertForbidden();
        $this->get(route('calificador.estadisticas.csv', $this->actividad))->assertForbidden();
        $this->post(route('calificador.items.anular', $this->item(3)), ['actividad_id' => $this->actividad->id, 'regla' => 'dar_por_buena', 'motivo' => 'intruso'])->assertForbidden();
        $this->post(route('calificador.items.tema', $this->item(1)), ['actividad_id' => $this->actividad->id, 'temario_item_id' => ''])->assertForbidden();
    }
}
