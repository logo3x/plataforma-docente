<?php

namespace Tests\Feature;

use App\Models\CambioEstructura;
use App\Models\Componente;
use App\Models\Nota;
use App\Models\User;
use App\Notas\TableroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 2.7.1, 2.7.2, 3.1.2: la estructura del periodo se edita después del alta, aunque ya haya notas. */
class EstructuraPeriodoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 1);
        foreach ([1, 2] as $i) {
            Componente::query()->create(['division_id' => $this->cortes[$i]->id, 'nombre' => 'Actividades', 'peso' => '1', 'orden' => 0]);
        }
        $this->actingAs($this->docente);
    }

    /** El formulario tal como lo envía la pantalla, con los pesos en porcentaje. */
    private function formulario(): array
    {
        return $this->dictado->divisiones()->with('componentes')->get()->map(fn ($d) => [
            'id' => $d->id, 'nombre' => $d->nombre, 'peso' => $d->porcentaje(), 'fecha_inicio' => '', 'fecha_fin' => '',
            'componentes' => $d->componentes->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'peso' => $c->porcentaje()])->all(),
        ])->all();
    }

    #[Test]
    public function cambiar_los_pesos_con_notas_cargadas_recalcula_la_definitiva_sin_tocar_las_notas(): void
    {
        $e = $this->grupo->estudiantes()->first();
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial);
        Nota::query()->create(['actividad_id' => $quiz->id, 'estudiante_id' => $e->id, 'valor' => '5', 'origen' => 'digitada']);
        Nota::query()->create(['actividad_id' => $parcial->id, 'estudiante_id' => $e->id, 'valor' => '2', 'origen' => 'digitada']);
        $this->assertSame('3.5', (new TableroNotas($this->grupo))->resultado($this->cortes[0]->fresh(), $e)->definitiva);

        $f = $this->formulario();
        $f[0]['nombre'] = 'Primer corte';
        $f[0]['peso'] = '20';
        $f[1]['peso'] = '40';
        $f[0]['componentes'][0]['peso'] = '30';
        $f[0]['componentes'][1]['peso'] = '70';
        $f[0]['fecha_inicio'] = '2026-02-01';
        $f[0]['fecha_fin'] = '2026-03-15';

        $this->put(route('dictados.estructura.guardar', $this->dictado), ['divisiones' => $f])
            ->assertRedirect(route('dictados.estructura', $this->dictado))->assertSessionHas('estado');

        $corte = $this->cortes[0]->fresh();
        $this->assertSame('Primer corte', $corte->nombre);
        $this->assertSame('20', $corte->porcentaje());
        $this->assertSame('2026-03-15', $corte->fecha_fin->format('Y-m-d'));
        $this->assertSame(2, Nota::query()->count(), 'las notas no se tocan');
        // 5 × 0.3 + 2 × 0.7 = 2.9
        $this->assertSame('2.9', (new TableroNotas($this->grupo))->resultado($corte->load('componentes.actividades'), $e)->definitiva);

        $cambio = CambioEstructura::query()->sole();
        $this->assertSame(2, $cambio->notas_cargadas);
        $this->assertContains('«Primer corte»: peso de 30 % a 20 %', $cambio->cambios);
        $this->assertSame('Corte 1', $cambio->antes[0]['nombre']);

        $this->get(route('dictados.estructura', $this->dictado))->assertOk()
            ->assertSee('ya tiene 2 notas cargadas')->assertSee('Primer corte»: peso de 30 % a 20 %', false)->assertSee('Antes:');
    }

    #[Test]
    public function los_pesos_deben_sumar_cien(): void
    {
        $f = $this->formulario();
        $f[0]['peso'] = '50';

        $this->from(route('dictados.estructura', $this->dictado))->put(route('dictados.estructura.guardar', $this->dictado), ['divisiones' => $f])
            ->assertRedirect(route('dictados.estructura', $this->dictado))->assertSessionHasErrors('divisiones');
        $this->assertSame('30', $this->cortes[0]->fresh()->porcentaje());
        $this->assertSame(0, CambioEstructura::query()->count());
    }

    #[Test]
    public function se_agregan_divisiones_y_componentes_y_se_quitan_los_que_no_tienen_actividades(): void
    {
        $this->crearActividad($this->trabajos);
        $f = $this->formulario();
        $f[0]['componentes'] = [
            $f[0]['componentes'][0],
            ['nombre' => 'Exposición', 'peso' => '50'],
        ];                                                              // se quita «Parcial», que no tiene actividades
        $f[2]['peso'] = '20';
        $f[] = ['nombre' => 'Corte 4', 'peso' => '20', 'componentes' => [['nombre' => 'Proyecto', 'peso' => '100']]];

        $this->put(route('dictados.estructura.guardar', $this->dictado), ['divisiones' => $f])->assertSessionHasNoErrors();

        $this->assertNull(Componente::query()->find($this->parcial->id));
        $this->assertSame(['Trabajos y quices', 'Exposición'], $this->cortes[0]->componentes()->pluck('nombre')->all());
        $this->assertSame(['Corte 1', 'Corte 2', 'Corte 3', 'Corte 4'], $this->dictado->divisiones()->pluck('nombre')->all());
        $this->assertContains('Se quita el componente «Parcial»', CambioEstructura::query()->sole()->cambios);
    }

    #[Test]
    public function no_se_quita_un_componente_con_actividades(): void
    {
        $this->crearActividad($this->parcial);
        $f = $this->formulario();
        $f[0]['componentes'] = [['id' => $this->trabajos->id, 'nombre' => 'Trabajos y quices', 'peso' => '100']];

        $this->put(route('dictados.estructura.guardar', $this->dictado), ['divisiones' => $f])
            ->assertSessionHasErrors(['divisiones' => '«Parcial» tiene una actividad: muévalas a otro componente antes de quitarlo.']);
        $this->assertNotNull($this->parcial->fresh());
        $this->assertSame('50', $this->trabajos->fresh()->porcentaje(), 'nada se guarda a medias');
    }

    #[Test]
    public function no_se_editan_componentes_de_otro_docente(): void
    {
        $otro = User::factory()->create();
        $this->actingAs($otro)->get(route('dictados.estructura', $this->dictado))->assertForbidden();
        $this->actingAs($otro)->put(route('dictados.estructura.guardar', $this->dictado), ['divisiones' => $this->formulario()])->assertForbidden();
    }
}
