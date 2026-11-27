<?php

namespace Tests\Feature;

use App\Evaluacion\EditorBanco;
use App\Evaluacion\ImportadorBanco;
use App\Historial\Versiones;
use App\Material\PreguntasDebate;
use App\Models\Material;
use App\Models\Rubrica;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 7.6: lo generado se versiona; editar no pierde lo anterior y se puede volver a cualquier versión. */
class VersionesTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 1);
        $this->actingAs($this->docente);
    }

    private function debate(string $titulo, string $pregunta): array
    {
        return app(PreguntasDebate::class)->normalizar(['formato' => 'plataforma-debate', 'version' => 1, 'titulo' => $titulo, 'preguntas' => [[
            'pregunta' => $pregunta, 'enfoque' => 'dilema', 'dinamica' => 'grupos', 'minutos' => '15', 'cierre' => 'Depende.',
            'posturas' => [['postura' => 'Sí', 'argumentos' => ['A']], ['postura' => 'No', 'argumentos' => ['B']]]]]]);
    }

    #[Test]
    public function editar_el_material_guarda_la_version_anterior_y_se_puede_volver(): void
    {
        $m = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'debate', 'titulo' => 'Debate 1', 'contenido' => $this->debate('Debate 1', '¿Pila o cola?')]);
        $this->put(route('debates.update', $m), ['contenido' => json_encode($this->debate('Debate corregido', '¿Pila, cola o lista?'))])->assertRedirect();
        $this->assertSame('Debate corregido', $m->fresh()->titulo);

        $v1 = Version::query()->sole();
        $this->assertSame([1, 'Debate 1', '¿Pila o cola?'], [$v1->numero, $v1->titulo, $v1->datos['contenido']['preguntas'][0]['pregunta']]);
        $this->get(route('debates.edit', $m))->assertOk()->assertSee('Versiones anteriores')->assertSee('Volver a esta versión');

        $this->post(route('versiones.restaurar', $v1))->assertRedirect(route('debates.show', $m))->assertSessionHas('estado');
        $this->assertSame('Debate 1', $m->fresh()->titulo);
        $this->assertSame(2, Version::query()->count(), 'Lo que había antes de volver también queda');

        $this->actingAs(User::factory()->create());
        $this->post(route('versiones.restaurar', $v1))->assertForbidden();
    }

    #[Test]
    public function la_rubrica_y_el_banco_tambien_se_versionan(): void
    {
        $r = Rubrica::query()->create(['materia_id' => $this->materia->id, 'titulo' => 'Rúbrica 1', 'criterios' => [
            ['nombre' => 'Claridad', 'descripcion' => '', 'peso' => '100', 'niveles' => [['nombre' => 'Sí', 'descripcion' => '', 'porcentaje' => '100'], ['nombre' => 'No', 'descripcion' => '', 'porcentaje' => '0']]]]]);
        app(Versiones::class)->guardar($r);
        $r->update(['titulo' => 'Rúbrica cambiada']);
        app(Versiones::class)->restaurar(Version::query()->sole());
        $this->assertSame('Rúbrica 1', $r->fresh()->titulo);

        $a = app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-VER-1', 'titulo' => 'Quiz', 'valor' => '1', 'items' => [
            ['tipo' => 'vf', 'peso' => '1', 'enunciado' => 'Original.', 'correcta' => true]]]);
        $b = app(EditorBanco::class)->exportar($a, true);
        $b['items'][0]['enunciado'] = 'Cambiado.';
        $this->put(route('bancos.update', $a), ['banco' => json_encode($b)])->assertRedirect();
        $this->assertSame('Cambiado.', $a->items()->first()->pregunta->enunciado['texto']);
        $v = Version::query()->where('versionable_type', $a->getMorphClass())->sole();
        $this->post(route('versiones.restaurar', $v))->assertRedirect(route('bancos.edit', $a));
        $this->assertSame('Original.', $a->items()->first()->pregunta->enunciado['texto']);

        // Un cambio que no se guarda no deja versión
        $b['valor'] = '9';
        $this->put(route('bancos.update', $a), ['banco' => json_encode($b)]);
        $this->assertSame(2, Version::query()->where('versionable_type', $a->getMorphClass())->count());
    }
}
