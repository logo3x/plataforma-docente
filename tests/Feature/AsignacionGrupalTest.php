<?php

namespace Tests\Feature;

use App\Calificacion\AsignacionGrupal;
use App\Models\Alerta;
use App\Models\Nota;
use App\Notas\RegistroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Criterios de aceptación de la entrega grupal (6.8). */
class AsignacionGrupalTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 6);
    }

    #[Test]
    public function una_entrega_de_cuatro_se_califica_una_vez_y_los_cuatro_quedan_con_la_nota(): void
    {
        $actividad = $this->crearActividad(atributos: ['es_grupal' => true]);
        $integrantes = $this->dictado->estudiantes()->take(4)->get();
        $entrega = $this->crearEntrega($actividad, ...$integrantes->all());

        app(AsignacionGrupal::class)->asignar($entrega, '4.2');

        $notas = Nota::query()->where('actividad_id', $actividad->id)->get();
        $this->assertCount(4, $notas);
        $this->assertTrue($notas->every(fn ($n) => $n->valor === '4.2000' && $n->origen === 'grupal' && $n->entrega_id === $entrega->id));
    }

    #[Test]
    public function el_ajuste_a_un_integrante_no_cambia_a_los_demas_y_queda_con_motivo(): void
    {
        $actividad = $this->crearActividad(atributos: ['es_grupal' => true]);
        [$a, $b] = $this->dictado->estudiantes()->take(2)->get()->all();
        app(AsignacionGrupal::class)->asignar($this->crearEntrega($actividad, $a, $b), '4.0');

        app(RegistroNotas::class)->asignar($actividad, $b, '3.0', 'ajuste_grupal', 'No participó en la sustentación', ajusteIndividual: true);

        $this->assertSame('4.0000', Nota::query()->where('estudiante_id', $a->id)->value('valor'));
        $nb = Nota::query()->where('estudiante_id', $b->id)->sole();
        $this->assertSame('3.0000', $nb->valor);
        $this->assertTrue($nb->ajuste_individual);
        $this->assertSame('No participó en la sustentación', $nb->cambios->last()->motivo);
    }

    #[Test]
    public function un_trio_en_trabajo_de_parejas_queda_con_tope(): void
    {
        $actividad = $this->crearActividad(atributos: ['es_grupal' => true, 'tamano_grupo' => 2, 'tope_exceso' => '4.0']);
        $trio = $this->dictado->estudiantes()->take(3)->get()->all();
        $pareja = $this->dictado->estudiantes()->skip(3)->take(2)->get()->all();

        app(AsignacionGrupal::class)->asignar($this->crearEntrega($actividad, ...$trio), '4.8');
        app(AsignacionGrupal::class)->asignar($this->crearEntrega($actividad, ...$pareja), '4.8');

        $this->assertSame('4.0000', Nota::query()->where('estudiante_id', $trio[0]->id)->value('valor'));
        $this->assertStringContainsString('Tope de 4.0: grupo de 3 integrantes', Nota::query()->where('estudiante_id', $trio[0]->id)->sole()->cambios->last()->motivo);
        $this->assertSame('4.8000', Nota::query()->where('estudiante_id', $pareja[0]->id)->value('valor'));
    }

    #[Test]
    public function un_estudiante_en_dos_entregas_dispara_alerta_en_lugar_de_doble_nota(): void
    {
        $actividad = $this->crearActividad(atributos: ['es_grupal' => true]);
        [$a, $b, $c] = $this->dictado->estudiantes()->take(3)->get()->all();

        app(AsignacionGrupal::class)->asignar($this->crearEntrega($actividad, $a, $b), '4.0');
        app(AsignacionGrupal::class)->asignar($this->crearEntrega($actividad, $b, $c), '2.0');

        $this->assertSame('4.0000', Nota::query()->where('estudiante_id', $b->id)->value('valor'));
        $this->assertSame('2.0000', Nota::query()->where('estudiante_id', $c->id)->value('valor'));
        $this->assertTrue(Alerta::query()->where('estudiante_id', $b->id)->where('tipo', 'estudiante_en_dos_entregas')->exists());
    }
}
