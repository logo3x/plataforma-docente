<?php

namespace Tests\Feature;

use App\Evaluacion\CatalogoErrores;
use App\Evaluacion\ImportadorBanco;
use App\Models\Artefacto;
use App\Models\ErrorFrecuente;
use App\Models\Materia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Catálogo de errores frecuentes mantenido por el docente (4.4.4, anexo A). */
class CatalogoErroresTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
    }

    private function explicar(string $lenguaje, string $mensaje): ?string
    {
        $c = app(CatalogoErrores::class);

        return $c->explicar($c->vigentes($this->docente->fresh(), $this->materia, [$lenguaje]), [$lenguaje], $mensaje)['texto'] ?? null;
    }

    #[Test]
    public function la_primera_vez_se_llena_con_el_anexo_a_y_no_se_vuelve_a_llenar_solo(): void
    {
        $this->get(route('catalogo-errores.index'))->assertOk()->assertSee('{x} is not defined')->assertSee('Errores frecuentes');
        $this->assertSame(count(CatalogoErrores::BASE), ErrorFrecuente::query()->count());
        $this->get(route('catalogo-errores.index', ['lenguaje' => 'sql']))->assertOk()->assertSee('no such table: {x}');
        $this->assertSame(count(CatalogoErrores::BASE), ErrorFrecuente::query()->count(), 'Sin duplicados');

        ErrorFrecuente::query()->delete();
        $this->get(route('catalogo-errores.index'))->assertOk()->assertSee('No hay entradas');
        $this->assertSame(0, ErrorFrecuente::query()->count(), 'Vaciado a propósito, no se rellena');

        $this->post(route('catalogo-errores.restaurar'))->assertSessionHas('estado', 'Se agregaron '.count(CatalogoErrores::BASE).' entradas de la base.');
    }

    #[Test]
    public function explica_con_el_nombre_y_respeta_ambito_orden_y_entradas_desactivadas(): void
    {
        $this->assertSame('Se usa «total», un nombre que no existe todavía. Qué revisar: La ortografía del nombre y que esté declarado antes de usarse.',
            $this->explicar('javascript', 'Línea 3: ReferenceError: total is not defined'));
        $this->assertSame('La tabla «equipo» no existe con ese nombre. Qué revisar: El nombre de la tabla; las tablas de la actividad se ven con «Ver tablas».',
            $this->explicar('sql', 'Línea 2: no such table: equipo'));
        $this->assertStringStartsWith('Se usa la variable $productos,', $this->explicar('php', 'Undefined variable $productos'));
        $this->assertNull($this->explicar('sql', 'total is not defined'), 'Cada lenguaje con sus entradas');

        // Una entrada de la materia gana sobre la general; una desactivada no cuenta
        $this->docente->erroresFrecuentes()->create(['lenguaje' => 'javascript', 'patron' => '{x} is not defined', 'materia_id' => $this->materia->id,
            'explicacion' => 'En Estructura de Datos: «{x}» no está declarada.', 'revision' => null]);
        $this->assertSame('En Estructura de Datos: «total» no está declarada.', $this->explicar('javascript', 'total is not defined'));
        ErrorFrecuente::query()->where('materia_id', $this->materia->id)->update(['activo' => false]);
        $this->assertStringStartsWith('Se usa «total»', $this->explicar('javascript', 'total is not defined'));

        // Expresión regular: el primer grupo es {x}
        $this->post(route('catalogo-errores.store'), ['lenguaje' => 'javascript', 'patron' => 'Invalid array length', 'es_regex' => '1', 'explicacion' => 'x'])->assertSessionHasNoErrors();
        $this->post(route('catalogo-errores.store'), ['lenguaje' => 'javascript', 'patron' => 'max (\d+', 'es_regex' => '1', 'explicacion' => 'x'])->assertSessionHasErrors('patron');
        $this->post(route('catalogo-errores.store'), ['lenguaje' => 'javascript', 'patron' => 'Unexpected identifier \'(\w+)\'', 'es_regex' => '1',
            'explicacion' => '«{x}» aparece donde no se esperaba.', 'revision' => '¿Falta una coma o un operador antes de {x}?'])->assertSessionHasNoErrors();
        $this->assertSame('«edad» aparece donde no se esperaba. Qué revisar: ¿Falta una coma o un operador antes de edad?', $this->explicar('javascript', "SyntaxError: Unexpected identifier 'edad'"));
    }

    #[Test]
    public function el_examen_lleva_solo_el_catalogo_de_los_lenguajes_de_sus_retos(): void
    {
        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-CAT', 'titulo' => 'Con reto', 'valor' => '5',
            'items' => [['tipo' => 'reto', 'peso' => '5', 'enunciado' => 'Sume.', 'plantilla_codigo' => 'function suma(a, b) {}',
                'verificaciones' => [['id' => 'v1', 'nombre' => 'Suma', 'fraccion' => '1', 'prueba' => 'suma(1, 2) === 3']]]]]);
        $actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Quiz', 'valor_max' => '5']);
        $actividad->grupos()->attach($this->grupo->id);

        preg_match('#id="datos-examen">(.*?)</script>#s', $this->get(route('calificador.examen', $actividad))->assertOk()->getContent(), $m);
        $catalogo = json_decode($m[1], true)['catalogo'];
        $this->assertNotEmpty($catalogo);
        $this->assertSame(['javascript'], array_values(array_unique(array_column($catalogo, 'lenguaje'))));
        $this->assertSame(['id', 'lenguaje', 'patron', 'regex', 'explicacion', 'revision'], array_keys($catalogo[0]));
    }

    #[Test]
    public function muestra_lo_que_aparecio_en_el_aula_y_lo_que_falta_explicar(): void
    {
        $this->get(route('catalogo-errores.index'));
        $definida = ErrorFrecuente::query()->where('patron', '{x} is not defined')->sole();
        $actividad = $this->crearActividad();
        foreach (['101' => 3, '102' => 1] as $doc => $veces) {
            $this->crearEntrega($actividad, $this->crearEstudiante($doc, 'APELLIDO', 'N'))->update(['sesion' => [
                'catalogo' => [$definida->id => $veces],
                'sin_catalogo' => [['lenguaje' => 'javascript', 'mensaje' => "Unexpected identifier 'edad'", 'veces' => 2],
                    ['lenguaje' => 'javascript', 'mensaje' => 'total is not defined', 'veces' => 1]],
            ]]);
        }

        $html = $this->get(route('catalogo-errores.index'))->assertOk()->assertSee('Mensajes que aparecieron sin explicación')->getContent();
        $this->assertStringContainsString('<code>Unexpected identifier &#039;edad&#039;</code></td><td class="num">2</td><td class="num">4</td>', $html);
        $this->assertStringNotContainsString('<code>total is not defined</code>', $html, 'Ya tiene explicación: no está pendiente');
        $this->assertStringContainsString('<td class="num">4</td>', $html);
        $this->assertStringContainsString(e(route('catalogo-errores.index', ['lenguaje' => 'javascript', 'nuevo' => "Unexpected identifier '{x}'"])), $html);
        $this->get(route('catalogo-errores.index', ['nuevo' => "Unexpected identifier '{x}'"]))->assertSee('value="Unexpected identifier &#039;{x}&#039;"', false);

        // Probar un mensaje
        $this->get(route('catalogo-errores.index', ['lenguaje' => 'javascript', 'mensaje' => 'precio is not a function']))->assertSee('Se intenta llamar a «precio»');
    }

    #[Test]
    public function cada_docente_edita_solo_su_catalogo(): void
    {
        $this->get(route('catalogo-errores.index'));
        $mia = ErrorFrecuente::query()->first();
        $this->put(route('catalogo-errores.update', $mia), ['lenguaje' => $mia->lenguaje, 'patron' => $mia->patron, 'explicacion' => 'Nueva explicación', 'activo' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Nueva explicación', $mia->fresh()->explicacion);

        $ajena = Materia::query()->create(['user_id' => User::factory()->create()->id, 'nombre' => 'Ajena']);
        $this->post(route('catalogo-errores.store'), ['lenguaje' => 'sql', 'patron' => 'x', 'explicacion' => 'y', 'materia_id' => $ajena->id])->assertSessionHasErrors('materia_id');

        $this->actingAs(User::factory()->create());
        $this->put(route('catalogo-errores.update', $mia), ['lenguaje' => 'sql', 'patron' => 'x', 'explicacion' => 'y'])->assertForbidden();
        $this->delete(route('catalogo-errores.destroy', $mia))->assertForbidden();
        $this->assertSame('Nueva explicación', $mia->fresh()->explicacion);
    }
}
