<?php

namespace Tests\Feature;

use App\Evaluacion\EditorBanco;
use App\Evaluacion\UsoPreguntas;
use App\Models\Artefacto;
use App\Models\Componente;
use App\Models\Dictado;
use App\Models\Division;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Respuesta;
use App\Models\Restauracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 5.4.13 y 5.4.14: aviso de las preguntas que ya respondieron, en otro periodo, estudiantes del periodo actual. */
class UsoPreguntasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Artefacto $examen;

    private Dictado $nuevo;

    private Grupo $grupoNuevo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 2);            // 2026A: documentos 1001 y 1002
        [$this->examen, $items] = $this->crearArtefacto([['vf', ['correcta' => true], '2'], ['vf', ['correcta' => false], '3']]);
        $this->examen->update(['configuracion' => ['valor' => '5'] + $this->examen->configuracion]);
        $actividad = $this->crearActividad($this->parcial, ['artefacto_id' => $this->examen->id]);
        // 1001 presentó el parcial y respondió solo la primera pregunta (la de su versión)
        $entrega = $this->crearEntrega($actividad, $this->grupo->estudiantes()->where('documento', '1001')->sole());
        Respuesta::query()->create(['entrega_id' => $entrega->id, 'artefacto_pregunta_id' => $items[0]->id, 'respuesta' => ['valor' => true], 'puntaje_max' => '2']);

        // 2026B: 1001 repite la materia; 2002 es nuevo
        $this->nuevo = Dictado::query()->create(['materia_id' => $this->materia->id, 'periodo' => '2026B']);
        $this->grupoNuevo = Grupo::query()->create(['dictado_id' => $this->nuevo->id, 'nombre' => 'Nocturno']);
        foreach (['1001' => 'APELLIDO1', '2002' => 'NUEVO'] as $doc => $ap) {
            $e = Estudiante::query()->create(['dictado_id' => $this->nuevo->id, 'documento' => $doc, 'apellidos' => $ap, 'nombres' => 'X']);
            $this->grupoNuevo->estudiantes()->attach($e->id);
        }
        $this->actingAs($this->docente);
    }

    #[Test]
    public function cuenta_solo_lo_que_respondieron_quienes_estan_en_el_periodo(): void
    {
        $vistas = app(UsoPreguntas::class)->vistas($this->nuevo);

        $this->assertCount(1, $vistas, 'solo la pregunta que respondió; la otra versión no cuenta');
        $this->assertSame(['estudiantes' => 1, 'periodos' => ['2026A']], array_values($vistas)[0]);
        $this->assertSame([], app(UsoPreguntas::class)->vistas($this->dictado), 'en su propio periodo no es «ya vista»');
    }

    #[Test]
    public function un_periodo_restaurado_no_cuenta_como_repetir_la_materia(): void
    {
        Restauracion::query()->create(['dictado_id' => $this->nuevo->id, 'user_id' => $this->docente->id, 'sha256' => str_repeat('a', 64),
            'version' => 1, 'periodo_original' => '2026A', 'resumen' => [], 'bitacora_original' => []]);

        $this->assertSame([], app(UsoPreguntas::class)->vistas($this->nuevo));
    }

    #[Test]
    public function una_copia_de_la_pregunta_tambien_cuenta_como_vista(): void
    {
        $copia = app(EditorBanco::class)->duplicar($this->examen, 'ED-COPIA', 'Copia');

        $vista = $this->get(route('bancos.edit', $copia))->assertOk()->viewData('vistasItems');
        $this->assertSame([['estudiantes' => 1, 'periodos' => ['2026A']]], array_values((array) $vista), 'la copia de la pregunta vista llega marcada');
        $this->assertSame(1, substr_count(json_encode(app(UsoPreguntas::class)->vistas($this->nuevo)), 'estudiantes'));

        // Al traerla de otro examen también llega marcada
        $this->get(route('bancos.create', $this->materia))->assertOk()
            ->assertSee('Ocultar las que ya respondieron estudiantes de 2026B en otro periodo');
    }

    #[Test]
    public function al_aplicar_el_examen_avisa_si_algun_estudiante_ya_lo_presento(): void
    {
        $componente = Componente::query()->create(['division_id' => Division::query()->create(
            ['dictado_id' => $this->nuevo->id, 'nombre' => 'Corte 1', 'peso' => '1', 'orden' => 0])->id, 'nombre' => 'Parcial', 'peso' => '1', 'orden' => 0]);

        $this->post(route('artefactos.aplicar', $this->examen), ['componente_id' => $componente->id, 'grupos' => [$this->grupoNuevo->id]])
            ->assertSessionHas('aviso_vistas', 'Un estudiante de estos grupos ya respondió, en 2026A, una pregunta de este examen. Si no quiere repetírselas, cámbielas en el banco o use otra versión.');
    }
}
