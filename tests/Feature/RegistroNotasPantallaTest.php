<?php

namespace Tests\Feature;

use App\Livewire\RegistroNotas;
use App\Models\Actividad;
use App\Models\Nota;
use App\Models\User;
use App\Notas\RegistroNotas as Registro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Interfaz del registro de notas (3.1, 3.3). */
class RegistroNotasPantallaTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 2);
        $this->actingAs($this->docente);
    }

    #[Test]
    public function la_pagina_muestra_pestanas_por_corte_y_consolidado(): void
    {
        $this->get(route('registro', $this->grupo))
            ->assertOk()
            ->assertSee('Corte 1')->assertSee('Corte 3')->assertSee('Consolidado')
            ->assertSee('APELLIDO1, Nombre1')
            ->assertSee('aún no tiene actividades');
    }

    #[Test]
    public function una_actividad_cambia_de_componente_con_sus_notas(): void
    {
        $quiz = $this->crearActividad($this->trabajos, ['nombre' => 'Quiz mal ubicado']);
        $e = $this->grupo->estudiantes()->first();
        app(Registro::class)->asignar($quiz, $e, '4.0', 'digitada');

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->assertSee('Parcial')->call('cambiarComponente', $quiz->id, $this->parcial->id);
        $this->assertSame($this->parcial->id, $quiz->fresh()->componente_id);
        $this->assertSame('4.0000', Nota::query()->where('actividad_id', $quiz->id)->sole()->valor);

        // Solo dentro del mismo periodo
        $otro = $this->dictado->replicate()->fill(['periodo' => '2099-1']);
        $otro->save();
        $div = $this->cortes[0]->replicate()->fill(['dictado_id' => $otro->id]);
        $div->save();
        $ajeno = $this->trabajos->replicate()->fill(['division_id' => $div->id]);
        $ajeno->save();
        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])->call('cambiarComponente', $quiz->id, $ajeno->id)->assertForbidden();
        $this->assertSame($this->parcial->id, $quiz->fresh()->componente_id);
    }

    #[Test]
    public function crear_columna_y_digitar_notas_recalcula_la_definitiva(): void
    {
        $c = Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->set("nuevaActividad.{$this->trabajos->id}", 'Quiz 1')
            ->call('crearActividad', $this->trabajos->id)
            ->set("nuevaActividad.{$this->parcial->id}", 'Parcial 1')
            ->call('crearActividad', $this->parcial->id);

        $quiz = Actividad::query()->where('nombre', 'Quiz 1')->sole();
        $parcial = Actividad::query()->where('nombre', 'Parcial 1')->sole();
        $e = $this->grupo->estudiantes()->orderBy('apellidos')->first();

        $c->call('guardar', $quiz->id, $e->id, '4,0')        // coma decimal
            ->call('guardar', $parcial->id, $e->id, '3.0')
            ->assertSee('3.5')
            ->assertSee('✓ Aprobado');

        $this->assertSame('4.0000', Nota::query()->where('actividad_id', $quiz->id)->value('valor'));
        $this->assertTrue($quiz->grupos()->whereKey($this->grupo->id)->exists(), 'La columna queda asignada al grupo.');
    }

    #[Test]
    public function valores_invalidos_se_marcan_en_la_celda_sin_guardar(): void
    {
        $a = $this->crearActividad();
        $e = $this->grupo->estudiantes()->first();

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->call('guardar', $a->id, $e->id, '7')
            ->assertSet("errores.{$a->id}-{$e->id}", 'La nota 7 está fuera de la escala 0.0 a 5.0.')
            ->call('guardar', $a->id, $e->id, 'abc')
            ->assertSet("errores.{$a->id}-{$e->id}", 'No es un número.');

        $this->assertSame(0, Nota::query()->count());
    }

    #[Test]
    public function borrar_el_contenido_deja_la_celda_vacia_no_en_cero(): void
    {
        $a = $this->crearActividad();
        $e = $this->grupo->estudiantes()->first();

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->call('guardar', $a->id, $e->id, '3')
            ->call('guardar', $a->id, $e->id, '');

        $this->assertNull(Nota::query()->sole()->valor);
    }

    #[Test]
    public function deshacer_muestra_y_revierte_la_ultima_accion(): void
    {
        $a = $this->crearActividad(atributos: ['nombre' => 'Taller 1']);
        $e = $this->grupo->estudiantes()->first();

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->call('guardar', $a->id, $e->id, '3')
            ->call('guardar', $a->id, $e->id, '4.5')
            ->assertSee('Deshacer: Nota de')
            ->call('deshacer')
            ->assertSee('Deshecho: Nota de');

        $this->assertSame('3.0000', Nota::query()->sole()->valor);
    }

    #[Test]
    public function vacias_a_ceros_y_eliminar_columna_con_confirmacion(): void
    {
        $a = $this->crearActividad();
        [$e1, $e2] = $this->grupo->estudiantes()->get()->all();
        app(Registro::class)->asignar($a, $e1, '4', 'digitada');

        $c = Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])->call('vaciasACeros', $a->id);
        $this->assertSame('0.0000', Nota::query()->where('estudiante_id', $e2->id)->value('valor'));

        $c->call('eliminarActividad', $a->id, 1)->assertSee('tiene 2 notas');
        $this->assertTrue(Actividad::query()->whereKey($a->id)->exists());

        $c->call('eliminarActividad', $a->id, 2);
        $this->assertFalse(Actividad::query()->whereKey($a->id)->exists());
    }

    #[Test]
    public function el_historial_de_una_nota_muestra_cada_cambio(): void
    {
        $a = $this->crearActividad(atributos: ['nombre' => 'Quiz 2']);
        $e = $this->grupo->estudiantes()->first();

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->call('guardar', $a->id, $e->id, '2.5')
            ->call('guardar', $a->id, $e->id, '3.5')
            ->call('verHistorial', $a->id, $e->id)
            ->assertSee('Historial')
            ->assertSee('2.5')
            ->assertSee('digitada');
    }

    #[Test]
    public function el_consolidado_muestra_aportes_y_acumulado(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial);
        $e = $this->grupo->estudiantes()->orderBy('apellidos')->first();
        app(Registro::class)->asignar($quiz, $e, '4', 'digitada');
        app(Registro::class)->asignar($parcial, $e, '5', 'digitada');

        // Corte 1 = 4.5; aporte 30 % = 1.35
        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->set('pestana', 'consolidado')
            ->assertSee('Aporte (30 %)')
            ->assertSee('4.5')
            ->assertSee('1.4')          // 1.35 redondeado a un decimal
            ->assertSee('… En curso');
    }

    #[Test]
    public function no_se_pueden_tocar_actividades_ni_estudiantes_de_otro_dictado(): void
    {
        $otraMateria = $this->docente->materias()->create(['nombre' => 'Otra']);
        $otroDictado = $otraMateria->dictados()->create(['periodo' => '2026A']);
        $div = $otroDictado->divisiones()->create(['nombre' => 'X', 'peso' => '1']);
        $ajena = $div->componentes()->create(['nombre' => 'Y', 'peso' => '1'])->actividades()->create(['nombre' => 'Z', 'valor_max' => '5']);

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->call('guardar', $ajena->id, $this->grupo->estudiantes()->first()->id, '3')
            ->assertForbidden();
    }

    #[Test]
    public function otro_docente_no_puede_abrir_el_registro(): void
    {
        $this->actingAs(User::factory()->create())->get(route('registro', $this->grupo))->assertForbidden();
    }

    #[Test]
    public function marca_quien_se_ve_aprobado_solo_por_redondeo(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial);
        $e = $this->grupo->estudiantes()->orderBy('apellidos')->first();
        app(Registro::class)->asignar($quiz, $e, '3', 'digitada');
        app(Registro::class)->asignar($parcial, $e, '2.9', 'digitada');

        // Definitiva 2.95: se muestra 3.0 pero está reprobado
        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->assertSee('✗ Reprobado')
            ->assertSee('2.95 sin redondear');
    }

    #[Test]
    public function reescribir_el_mismo_valor_no_crea_un_deshacer_vacio(): void
    {
        $a = $this->crearActividad(atributos: ['nombre' => 'Quiz 3']);
        $e = $this->grupo->estudiantes()->first();

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])
            ->call('guardar', $a->id, $e->id, '3')
            ->call('guardar', $a->id, $e->id, '3.0')
            ->call('deshacer')
            ->assertOk()
            ->call('deshacer')
            ->assertOk();

        $this->assertNull(Nota::query()->sole()->valor);
    }
}
