<?php

namespace Tests\Feature;

use App\Estudiantes\EnDosGrupos;
use App\Models\Grupo;
use App\Models\Nota;
use App\Models\NotaCambio;
use App\Models\User;
use App\Notas\RegistroNotas;
use App\Notas\TableroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Estudiantes que aparecen en dos grupos del periodo (3.1.12, decisión pendiente 5). */
class EnDosGruposTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Grupo $nocturno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->nocturno = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);
    }

    private function nota($actividad, $estudiante, string $v): void
    {
        $r = app(RegistroNotas::class);
        $r->operacion(fn () => $r->asignar($actividad, $estudiante, $v, 'digitada'));
    }

    #[Test]
    public function traslada_las_notas_a_la_actividad_equivalente_y_retira_del_otro_grupo(): void
    {
        $ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');               // en Diurno
        $this->nocturno->estudiantes()->attach($ana->id);                    // y también en Nocturno
        $soloNocturno = $this->crearEstudiante('102', 'BELTRAN', 'BETO');
        $this->grupo->estudiantes()->detach($soloNocturno->id);
        $this->nocturno->estudiantes()->attach($soloNocturno->id);

        // El mismo parcial en las dos jornadas, cada uno asignado a su grupo
        $parcialD = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1 (Diurno)']);
        $parcialD->grupos()->attach($this->grupo->id);
        $parcialN = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1 (Nocturno)']);
        $parcialN->grupos()->attach($this->nocturno->id);
        $tallerN = $this->crearActividad(null, ['nombre' => 'Taller de la noche']);
        $tallerN->grupos()->attach($this->nocturno->id);
        $quizN = $this->crearActividad(null, ['nombre' => 'Quiz 2']);
        $quizN->grupos()->attach($this->nocturno->id);
        $quizD = $this->crearActividad(null, ['nombre' => 'Quiz 2']);
        $quizD->grupos()->attach($this->grupo->id);
        $this->nota($parcialN, $ana, '4.2');     // presentó el parcial en la noche
        $this->nota($tallerN, $ana, '3');        // sin equivalente en Diurno
        $this->nota($quizN, $ana, '2');
        $this->nota($quizD, $ana, '4');          // conflicto: ya tiene otra nota en el Quiz 2 del Diurno

        $servicio = app(EnDosGrupos::class);
        $this->assertSame(1, $servicio->cuantos($this->grupo));
        $this->get(route('registro', $this->grupo))->assertSee('Un estudiante de este grupo también está en otro grupo del periodo.');
        $this->get(route('dictados.show', $this->dictado))->assertSee('Un estudiante aparece en dos grupos de este periodo.');

        $this->get(route('dos-grupos.index', $this->dictado))->assertOk()
            ->assertSee('ARIAS, ANA')->assertDontSee('BELTRAN, BETO')
            ->assertSee('sugerido: tiene más registros')
            ->assertSee('«Parcial 1 (Nocturno)» (Nocturno) → «Parcial 1 (Diurno)»: 4.2', false)
            ->assertSee('no tiene equivalente en Diurno')
            ->assertSee('«Quiz 2» ya tiene 4.0: no se toca', false);

        $this->post(route('dos-grupos.resolver', [$this->dictado, $ana]), ['decision' => $this->grupo->id])
            ->assertSessionHas('estado', 'ARIAS, ANA queda en Diurno; retirado de Nocturno (sin borrar nada). 1 nota(s) trasladada(s), con constancia en la bitácora. '
                .'1 con nota distinta en el destino: no se tocaron, revíselas en el registro. 1 sin actividad equivalente en Diurno: regístrelas a mano.');

        $this->assertSame('4.2000', Nota::query()->where('actividad_id', $parcialD->id)->where('estudiante_id', $ana->id)->value('valor'));
        $this->assertSame('traslado', Nota::query()->where('actividad_id', $parcialD->id)->where('estudiante_id', $ana->id)->value('origen'));
        $this->assertSame('4.0000', Nota::query()->where('actividad_id', $quizD->id)->where('estudiante_id', $ana->id)->value('valor'), 'El conflicto no se sobrescribe');
        $this->assertSame('4.2000', Nota::query()->where('actividad_id', $parcialN->id)->where('estudiante_id', $ana->id)->value('valor'), 'La nota original se conserva');
        $this->assertStringContainsString('Viene de «Parcial 1 (Nocturno)» (Nocturno)', NotaCambio::query()->where('origen', 'traslado')->sole()->motivo);

        // Queda solo en el registro del Diurno; en el Nocturno pasa a retirado
        $this->assertTrue((new TableroNotas($this->grupo))->estudiantes()->contains('id', $ana->id));
        $this->assertFalse((new TableroNotas($this->nocturno))->estudiantes()->contains('id', $ana->id));
        $this->assertSame(0, $servicio->cuantos($this->grupo));
        $this->get(route('dos-grupos.index', $this->dictado))->assertSee('Nadie aparece en dos grupos.');
        $this->get(route('estudiantes.historial', [$this->grupo, $ana]))->assertSee('trasladada de otro grupo');
    }

    #[Test]
    public function mantener_en_ambos_deja_de_avisar_y_solo_el_docente_decide(): void
    {
        $ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $this->nocturno->estudiantes()->attach($ana->id);

        $this->actingAs(User::factory()->create());
        $this->get(route('dos-grupos.index', $this->dictado))->assertForbidden();
        $this->post(route('dos-grupos.resolver', [$this->dictado, $ana]), ['decision' => 'ambos'])->assertForbidden();

        $this->actingAs($this->docente);
        $otro = $this->dictado->materia->dictados()->create(['periodo' => '2027A']);
        $this->post(route('dos-grupos.resolver', [$otro, $ana]), ['decision' => 'ambos'])->assertNotFound();
        $this->post(route('dos-grupos.resolver', [$this->dictado, $ana]), ['decision' => 'ambos'])->assertSessionHas('estado');
        $this->assertSame(0, app(EnDosGrupos::class)->cuantos($this->grupo));
        $this->assertTrue((new TableroNotas($this->nocturno))->estudiantes()->contains('id', $ana->id), 'Sigue en los dos');

        // Si después lo pone en un tercer grupo, vuelve a avisar
        $tarde = $this->dictado->grupos()->create(['nombre' => 'Tarde']);
        $tarde->estudiantes()->attach($ana->id);
        $this->assertSame(1, app(EnDosGrupos::class)->cuantos($tarde));
    }
}
