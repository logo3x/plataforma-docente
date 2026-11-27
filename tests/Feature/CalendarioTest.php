<?php

namespace Tests\Feature;

use App\Dictados\AltaMateria;
use App\Dictados\CalendarioClases;
use App\Dictados\FestivosColombia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 2.6.2: se registra una semana y las clases salen solas, sin festivos nacionales ni días sin clase. */
class CalendarioTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->cortes[0]->update(['fecha_inicio' => '2026-03-16', 'fecha_fin' => '2026-04-05']);
        $this->cortes[1]->update(['fecha_inicio' => '2026-04-06', 'fecha_fin' => '2026-04-19']);
        $this->actingAs($this->docente);
    }

    #[Test]
    public function los_festivos_de_2026_son_los_oficiales(): void
    {
        $this->assertSame(['2026-01-01', '2026-01-12', '2026-03-23', '2026-04-02', '2026-04-03', '2026-05-01', '2026-05-18', '2026-06-08', '2026-06-15',
            '2026-06-29', '2026-07-20', '2026-08-07', '2026-08-17', '2026-10-12', '2026-11-02', '2026-11-16', '2026-12-08', '2026-12-25'], array_keys(FestivosColombia::del(2026)));
        $this->assertSame('2025-04-20', FestivosColombia::pascua(2025)->format('Y-m-d'));
        $this->assertSame('2027-03-28', FestivosColombia::pascua(2027)->format('Y-m-d'));
    }

    #[Test]
    public function las_clases_salen_del_horario_sin_festivos_ni_dias_sin_clase(): void
    {
        $this->put(route('calendario.horario', $this->grupo), ['dias' => [
            1 => ['activo' => '1', 'hora_inicio' => '18:00', 'hora_fin' => '20:00', 'aula' => 'Sala 3'],
            4 => ['activo' => '1', 'hora_inicio' => '18:00', 'hora_fin' => '20:00'],
            5 => ['hora_inicio' => '07:00', 'hora_fin' => '09:00'],          // sin marcar: no cuenta
        ]])->assertSessionHasNoErrors()->assertSessionHas('estado', 'Horario guardado: 8 clases en el periodo, sin festivos.');
        // Del 16 de marzo al 19 de abril: 5 lunes y 5 jueves; 23 de marzo (San José) y 2 de abril (Jueves Santo) son festivos

        $this->post(route('calendario.sin-clase', $this->grupo), ['desde' => '2026-04-13', 'hasta' => '2026-04-17', 'motivo' => 'Semana institucional'])->assertSessionHasNoErrors();
        $c = app(CalendarioClases::class)->de($this->grupo->fresh());

        $this->assertSame(['2026-03-16', '2026-03-19', '2026-03-26', '2026-03-30', '2026-04-06', '2026-04-09'], $c['clases']->map(fn ($x) => $x['fecha']->format('Y-m-d'))->all());
        $this->assertSame(['Corte 1', 'Corte 1', 'Corte 1', 'Corte 1', 'Corte 2', 'Corte 2'], $c['clases']->map(fn ($x) => $x['corte']->nombre)->all());
        $this->assertSame(['Festivo: San José', 'Festivo: Jueves Santo', 'Semana institucional', 'Semana institucional'], $c['omitidas']->pluck('motivo')->all());

        $this->get(route('calendario.grupo', $this->grupo))->assertOk()->assertSee('6 clases')->assertSee('Festivo: San José');
        $ics = $this->get(route('calendario.ics', $this->grupo))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->getContent();
        $this->assertSame(6, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString("DTSTART;TZID=America/Bogota:20260316T180000\r\n", $ics);
        $this->assertStringContainsString('LOCATION:Sala 3', $ics);
    }

    #[Test]
    public function el_horario_valida_las_horas_y_pasa_al_periodo_siguiente(): void
    {
        $this->put(route('calendario.horario', $this->grupo), ['dias' => [2 => ['activo' => '1', 'hora_inicio' => '10:00', 'hora_fin' => '08:00']]])
            ->assertSessionHasErrors(['dias' => 'El martes la clase termina antes de empezar.']);

        $this->put(route('calendario.horario', $this->grupo), ['dias' => [2 => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '10:00']]]);
        $nuevo = app(AltaMateria::class)->nuevoDictado($this->dictado->fresh(), '2026B');
        $this->assertSame([2], $nuevo->grupos()->sole()->horarios()->pluck('dia')->all());

        $this->actingAs(User::factory()->create())->get(route('calendario.grupo', $this->grupo))->assertForbidden();
    }
}
