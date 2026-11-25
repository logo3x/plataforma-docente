<?php

namespace Tests\Feature;

use App\Models\Dictado;
use App\Models\Materia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Alta de materia en un paso (2.4) y dictado heredado (2.6.3). */
class AltaMateriaTest extends TestCase
{
    use RefreshDatabase;

    private function formulario(array $cambios = []): array
    {
        return array_replace_recursive([
            'nombre' => 'Estructura de Datos', 'codigo' => '910303', 'nivel' => 'universidad', 'periodo' => '2026B',
            'grupos' => 'Diurno, Nocturno', 'escala_min' => '0', 'escala_max' => '5', 'nota_aprobatoria' => '3', 'decimales' => '1',
            'divisiones' => [
                ['nombre' => 'Corte 1', 'peso' => '30', 'componentes' => [['nombre' => 'Trabajos', 'peso' => '50'], ['nombre' => 'Parcial', 'peso' => '50']]],
                ['nombre' => 'Corte 2', 'peso' => '30', 'componentes' => [['nombre' => 'Trabajos', 'peso' => '50'], ['nombre' => 'Parcial', 'peso' => '50']]],
                ['nombre' => 'Corte 3', 'peso' => '40', 'componentes' => [['nombre' => 'Proyecto', 'peso' => '100']]],
            ],
        ], $cambios);
    }

    #[Test]
    public function crea_materia_dictado_divisiones_y_grupos_en_un_paso(): void
    {
        $docente = User::factory()->create();

        $r = $this->actingAs($docente)->post('/materias', $this->formulario());

        $dictado = Dictado::query()->sole();
        $r->assertRedirect(route('dictados.show', $dictado));
        $this->assertSame(['Corte 1', 'Corte 2', 'Corte 3'], $dictado->divisiones->pluck('nombre')->all());
        $this->assertSame(['0.3000', '0.3000', '0.4000'], $dictado->divisiones->pluck('peso')->all());
        $this->assertSame(['Diurno', 'Nocturno'], $dictado->grupos->pluck('nombre')->all());
        $this->assertSame(1, $dictado->divisiones[2]->componentes->count());

        $this->get(route('dictados.show', $dictado))->assertOk()->assertSee('Cargar lista')->assertSee('40 %');
    }

    #[Test]
    public function rechaza_pesos_que_no_suman_100(): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/materias/crear')
            ->post('/materias', $this->formulario(['divisiones' => [2 => ['peso' => '30']]]))
            ->assertRedirect('/materias/crear')
            ->assertSessionHasErrors('divisiones');

        $this->assertSame(0, Materia::query()->count());
    }

    #[Test]
    public function el_formulario_carga_las_plantillas_sugeridas(): void
    {
        $this->actingAs(User::factory()->create())->get('/materias/crear')
            ->assertOk()->assertSee('Periodo 4', false)->assertSee('Corte 3', false);
    }

    #[Test]
    public function otro_docente_no_puede_ver_el_dictado(): void
    {
        $this->actingAs(User::factory()->create())->post('/materias', $this->formulario());
        $dictado = Dictado::query()->sole();

        $this->actingAs(User::factory()->create())->get(route('dictados.show', $dictado))->assertForbidden();
    }

    #[Test]
    public function el_dictado_siguiente_hereda_estructura_y_grupos_sin_estudiantes(): void
    {
        $docente = User::factory()->create();
        $this->actingAs($docente)->post('/materias', $this->formulario());
        $origen = Dictado::query()->sole();
        $origen->estudiantes()->create(['documento' => '1', 'apellidos' => 'A', 'nombres' => 'B']);

        $this->post(route('dictados.store', $origen), ['periodo' => '2027A'])->assertRedirect();

        $nuevo = Dictado::query()->where('periodo', '2027A')->sole();
        $this->assertSame($origen->id, $nuevo->origen_dictado_id);
        $this->assertSame(3, $nuevo->divisiones()->count());
        $this->assertSame(2, $nuevo->grupos()->count());
        $this->assertSame(0, $nuevo->estudiantes()->count());

        $this->post(route('dictados.store', $origen), ['periodo' => '2027A'])->assertSessionHasErrors('periodo');
    }
}
