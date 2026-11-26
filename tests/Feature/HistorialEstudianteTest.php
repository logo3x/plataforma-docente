<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Reclamo;
use App\Models\User;
use App\Notas\RegistroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Historial del estudiante a lo largo del semestre, enlazado con el registro (13.6). */
class HistorialEstudianteTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
    }

    #[Test]
    public function reune_notas_entregas_alertas_reclamos_y_cambios_en_una_pagina(): void
    {
        $ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $beto = $this->crearEstudiante('102', 'BELTRAN', 'BETO');
        $quiz = $this->crearActividad(null, ['nombre' => 'Quiz de pilas']);
        $parcial = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1']);
        $registro = app(RegistroNotas::class);
        $registro->operacion(fn () => $registro->asignar($quiz, $ana, '3', 'digitada'));
        $registro->operacion(fn () => $registro->asignar($quiz, $ana, '4', 'digitada', 'Revisé la pregunta 3'));
        $registro->operacion(fn () => $registro->asignar($quiz, $beto, '2', 'digitada'));
        $entrega = $this->crearEntrega($parcial, $ana, $beto);
        $entrega->update(['duracion_seg' => 1500, 'version' => 'B', 'sesion' => ['salidas' => [['segundos' => 20], ['segundos' => 5]]]]);
        Alerta::query()->create(['actividad_id' => $parcial->id, 'entrega_id' => $entrega->id, 'estudiante_id' => $ana->id, 'tipo' => 'salidas', 'datos' => ['cantidad' => 2, 'segundos' => 25]]);
        Reclamo::query()->create(['actividad_id' => $parcial->id, 'estudiante_id' => $ana->id, 'descripcion' => 'La pregunta 2 tenía dos respuestas',
            'estado' => 'rechazado', 'resolucion' => 'La opción b no cumple la condición', 'atendido_en' => now()]);

        $html = $this->get(route('estudiantes.historial', [$this->grupo, $ana]))->assertOk()
            ->assertSee('ARIAS, ANA')->assertSee('Documento 101')
            ->assertSee('Situación en el periodo')
            ->assertSee('Quiz de pilas')->assertSee('Parcial 1')
            ->assertSee('25 min')->assertSee('v. B')->assertSee('en grupo de 2')
            ->assertSee('Salidas')->assertSee('Rechazado')
            ->assertSee('2</strong> salidas de ventana', false)
            ->assertSee('Quiz de pilas: vacía → 3.0')
            ->assertSee('Quiz de pilas: 3.0 → 4.0')->assertSee('Revisé la pregunta 3')
            ->assertSee('Entrega de Parcial 1')->assertSee('con BELTRAN, BETO')
            ->assertSee('Reclamo rechazado en Parcial 1')->assertSee('La opción b no cumple la condición')
            ->getContent();

        // Solo lo suyo: la nota de Beto no aparece
        $this->assertStringNotContainsString('vacía → 2.0', $html);
        // Desde el registro, el nombre lleva al historial
        $this->get(route('registro', $this->grupo))->assertSee(route('estudiantes.historial', [$this->grupo, $ana]), false);
        $this->get(route('calificador.detalle', $entrega))->assertSee(route('estudiantes.historial', [$this->grupo, $beto]), false);
    }

    #[Test]
    public function muestra_la_segunda_oportunidad_una_vez_y_lo_que_necesita_para_aprobar(): void
    {
        $ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $quiz = $this->crearActividad(null, ['nombre' => 'Quiz de colas']);
        $registro = app(RegistroNotas::class);
        $registro->operacion(fn () => $registro->asignar($quiz, $ana, '1', 'digitada'));
        $this->post(route('segundas.store', [$this->grupo, $ana]), ['ambito' => "actividad:{$quiz->id}", 'tipo' => 'recuperacion', 'valor' => '3.5',
            'regla' => 'reemplaza', 'fecha' => now()->toDateString()])->assertSessionHasNoErrors();

        $html = $this->get(route('estudiantes.historial', [$this->grupo, $ana]))->assertOk()
            ->assertSee('Recuperación de Quiz de colas: 3.5')->assertSee('Necesita')->getContent();
        $this->assertSame(1, substr_count($html, 'Recuperación de Quiz de colas'), 'No se repite como cambio de nota');
        $this->assertStringContainsString('>3.5</strong>', $html);
    }

    #[Test]
    public function solo_el_docente_del_grupo_y_estudiantes_del_grupo(): void
    {
        $ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $otroGrupo = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);
        $this->get(route('estudiantes.historial', [$otroGrupo, $ana]))->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get(route('estudiantes.historial', [$this->grupo, $ana]))->assertForbidden();
    }
}
