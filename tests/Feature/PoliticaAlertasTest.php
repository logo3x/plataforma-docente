<?php

namespace Tests\Feature;

use App\Calificacion\PoliticaAlertas;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Política frente a las alertas: revisión del docente y lo que sabe el estudiante (decisión pendiente 6). */
class PoliticaAlertasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private Estudiante $ana;

    private Estudiante $beto;

    private Entrega $entrega;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('102', 'BELTRAN', 'BETO');
        [$art] = $this->crearArtefacto([['vf', ['correcta' => true], '5']]);
        $this->actividad = $this->crearActividad($this->parcial, ['artefacto_id' => $art->id, 'nombre' => 'Parcial 1']);
        $this->entrega = $this->crearEntrega($this->actividad, $this->ana, $this->beto);   // entrega de pareja
        foreach ([['salidas', $this->ana, ['cantidad' => 4, 'segundos' => 95]], ['duracion_anomala', $this->ana, ['segundos' => 240, 'minimo' => 900]],
            ['sin_cifrar', $this->ana, []], ['estudiante_en_dos_entregas', $this->beto, []]] as [$tipo, $e, $datos]) {
            Alerta::query()->create(['actividad_id' => $this->actividad->id, 'entrega_id' => $this->entrega->id, 'estudiante_id' => $e->id, 'tipo' => $tipo, 'datos' => $datos]);
        }
    }

    private function informe(Estudiante $e): string
    {
        return $this->get(route('calificador.devolucion.ver', [$this->actividad, $e]))->assertOk()->getContent();
    }

    private function politica(array $cambios): void
    {
        $p = app(PoliticaAlertas::class)->de($this->docente);
        foreach ($cambios as $tipo => $v) {
            $p[$tipo] = $v + $p[$tipo];
        }
        $this->put(route('alertas.politica.guardar'), ['politica' => array_map(fn ($x) => ['revisar' => $x['revisar'] ? '1' : '0', 'informar' => $x['informar']], $p)])
            ->assertSessionHas('estado');
    }

    #[Test]
    public function por_defecto_el_estudiante_no_sabe_nada_aunque_el_docente_revise(): void
    {
        $this->get(route('calificador.show', $this->actividad))->assertSee('Revisar las alertas (3 pendientes)');
        $this->get(route('alertas.actividad', $this->actividad))->assertOk()
            ->assertSee('Por revisar (3)')->assertSee('Durante el examen se registraron 4 salidas de la ventana, 95 segundos en total.')
            ->assertSee('Informativas, sin revisión (1)')->assertDontSee('Contárselo en su informe');

        $salidas = Alerta::query()->where('tipo', 'salidas')->sole();
        $this->post(route('alertas.revisar', $salidas), ['decision' => 'conversada', 'nota' => 'Consultaba el enunciado impreso.', 'informar' => '1'])->assertSessionHas('estado');
        $salidas->refresh();
        $this->assertSame(['conversada', false, $this->docente->id], [$salidas->decision, $salidas->informar, $salidas->revisada_por]);
        $this->assertStringNotContainsString('salidas de la ventana', $this->informe($this->ana));
        $this->get(route('calificador.show', $this->actividad))->assertSee('Revisar las alertas (2 pendientes)');
    }

    #[Test]
    public function tras_revision_el_informe_cuenta_solo_lo_que_el_docente_decidio(): void
    {
        $this->politica(['salidas' => ['informar' => 'tras_revision'], 'duracion_anomala' => ['informar' => 'tras_revision'], 'estudiante_en_dos_entregas' => ['informar' => 'tras_revision']]);
        $this->get(route('alertas.actividad', $this->actividad))->assertSee('Contárselo en su informe');

        // Antes de revisar: nada
        $this->assertStringNotContainsString('Observaciones de la aplicación', $this->informe($this->ana));

        $this->post(route('alertas.revisar', Alerta::query()->where('tipo', 'salidas')->sole()), ['decision' => 'conversada', 'nota' => 'Lo hablamos en clase.', 'informar' => '1']);
        $this->post(route('alertas.revisar', Alerta::query()->where('tipo', 'duracion_anomala')->sole()), ['decision' => 'sin_consecuencia', 'informar' => '0']);
        $this->post(route('alertas.revisar', Alerta::query()->where('tipo', 'estudiante_en_dos_entregas')->sole()), ['decision' => 'sin_consecuencia', 'informar' => '1']);

        $ana = $this->informe($this->ana);
        $this->assertStringContainsString('Observaciones de la aplicación, revisadas por el docente', $ana);
        $this->assertStringContainsString('Durante el examen se registraron 4 salidas de la ventana', $ana);
        $this->assertStringContainsString('Lo hablamos en clase.', $ana);
        $this->assertStringNotContainsString('minutos, menos que el tiempo mínimo', $ana, 'Revisada pero no informada');
        $this->assertStringNotContainsString('dos entregas', $ana, 'La alerta personal de Beto no le llega a Ana');

        // Su compañera de entrega comparte la sesión del examen: también ve las salidas; la suya personal solo él
        $beto = $this->informe($this->beto);
        $this->assertStringContainsString('salidas de la ventana', $beto);
        $this->assertStringContainsString('Usted aparece como integrante de dos entregas', $beto);

        // Si el docente vuelve a «nunca», deja de mostrarse
        $this->politica(['salidas' => ['informar' => 'nunca']]);
        $this->assertStringNotContainsString('salidas de la ventana', $this->informe($this->ana));
    }

    #[Test]
    public function la_politica_cambia_que_pide_revision_y_solo_el_docente_revisa(): void
    {
        $this->politica(['sin_cifrar' => ['revisar' => true], 'salidas' => ['revisar' => false]]);
        $this->get(route('alertas.actividad', $this->actividad))->assertSee('Por revisar (3)')->assertSee('El navegador del equipo no permitió cifrar la entrega.')
            ->assertSee('Informativas, sin revisión (1)');
        $this->get(route('alertas.politica'))->assertOk()->assertSee('Política frente a las alertas');

        $this->actingAs(User::factory()->create());
        $this->get(route('alertas.actividad', $this->actividad))->assertForbidden();
        $this->post(route('alertas.revisar', Alerta::query()->first()), ['decision' => 'conversada'])->assertForbidden();
    }
}
