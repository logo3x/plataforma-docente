<?php

namespace Tests\Feature;

use App\Calificacion\EnvioInformes;
use App\Evaluacion\ImportadorBanco;
use App\Jobs\EnviarEnlaceInforme;
use App\Mail\EnlaceInforme;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Devolucion;
use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Envío del enlace al informe por correo institucional (6.9.3) y su registro (6.9.5). */
class EnvioInformesTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private Estudiante $ana;

    private Estudiante $beto;

    private Estudiante $carla;

    private Estudiante $dario;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['plataforma.correo_dominio' => 'ejemplo.edu.co', 'plataforma.correo_en_cola' => false, 'mail.default' => 'smtp']);
        $this->crearEscenario(estudiantes: 0);
        $this->docente->update(['email' => 'profe@ejemplo.edu.co', 'name' => 'Luis Oviedo']);
        $this->actingAs($this->docente);

        $this->ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('102', 'BELTRAN', 'BETO');
        $this->carla = $this->crearEstudiante('103', 'CASTRO', 'CARLA');
        $this->dario = $this->crearEstudiante('104', 'DIAZ', 'DARIO');
        $this->ana->update(['email' => 'ana@ejemplo.edu.co']);
        $this->beto->update(['email' => 'beto@gmail.com']);                 // no institucional
        $this->dario->update(['email' => 'dario@ejemplo.edu.co']);           // no presenta

        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-COR-Q1', 'titulo' => 'Quiz de pilas', 'valor' => '5',
            'items' => [['tipo' => 'vf', 'peso' => '5', 'enunciado' => 'Una pila es LIFO.', 'correcta' => true]]]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Quiz de pilas', 'valor_max' => '5']);
        $this->actividad->grupos()->attach($this->grupo->id);
        foreach ([$this->ana, $this->beto, $this->carla] as $e) {
            $this->crearEntrega($this->actividad, $e);
        }
    }

    private function liberar(): void
    {
        $this->post(route('calificador.devolucion.liberar', $this->actividad), ['dias' => 15])->assertRedirect();
    }

    private function devolucion(Estudiante $e): Devolucion
    {
        return Devolucion::query()->where('estudiante_id', $e->id)->sole();
    }

    #[Test]
    public function solo_se_envia_a_correos_institucionales_de_quienes_presentaron_y_sin_la_nota(): void
    {
        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'pendientes'])->assertSessionHas('error_devolucion', 'Primero libere la devolución.');
        $this->liberar();

        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'pendientes', 'mensaje' => 'Revisen la pregunta 1.'])
            ->assertSessionHas('estado', fn ($s) => str_contains($s, '1 correo(s) enviados') && str_contains($s, '3 sin enviar'));

        Mail::assertSent(EnlaceInforme::class, 1);
        Mail::assertSent(EnlaceInforme::class, function (EnlaceInforme $m) {
            $html = $m->render();
            $enlace = $m->enlace;

            return $m->hasTo('ana@ejemplo.edu.co') && $m->hasReplyTo('profe@ejemplo.edu.co')
                && $m->hasSubject('Su informe de «Quiz de pilas» — Estructura de Datos')
                && str_contains($html, 'Hola, ANA') && str_contains($html, 'Revisen la pregunta 1.') && str_contains($html, e($enlace))
                && ! str_contains($html, 'Nota') && str_contains($enlace, 'signature=');
        });

        $d = $this->devolucion($this->ana);
        $this->assertSame('ana@ejemplo.edu.co', $d->correo_destino);
        $this->assertNotNull($d->correo_enviado_en);
        $this->assertSame(1, $d->correos);

        // El enlace del correo abre el informe y queda como recibido
        Mail::assertSent(EnlaceInforme::class, function (EnlaceInforme $m) {
            $this->get($m->enlace)->assertOk()->assertSee('Quiz de pilas');

            return true;
        });
        $this->assertTrue($this->devolucion($this->ana)->fresh()->recibida());

        $this->get(route('calificador.devolucion', $this->actividad))->assertOk()
            ->assertSee('Enviado')->assertSee('a ana@ejemplo.edu.co')
            ->assertSee('«beto@gmail.com» no es del dominio institucional (ejemplo.edu.co).')
            ->assertSee('No tiene correo en la lista.')
            ->assertSee('1 estudiante(s) con correo válido');
    }

    #[Test]
    public function no_reenvia_a_quien_ya_lo_tiene_salvo_que_se_pida(): void
    {
        $this->carla->update(['email' => 'carla@ejemplo.edu.co']);
        $this->liberar();
        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'pendientes']);
        Mail::assertSent(EnlaceInforme::class, 2);

        // Otra vez «pendientes»: nadie nuevo
        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'pendientes'])->assertSessionHas('estado', fn ($s) => str_contains($s, '2 omitidos'));
        Mail::assertSent(EnlaceInforme::class, 2);

        // «A todos de nuevo», pasado el tiempo de espera
        $this->travel(EnvioInformes::ESPERA_MINUTOS + 1)->minutes();
        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'todos'])->assertSessionHas('estado', fn ($s) => str_contains($s, '2 correo(s) enviados'));
        Mail::assertSent(EnlaceInforme::class, 4);
        $this->assertSame(2, $this->devolucion($this->ana)->correos);

        // Reenvío individual: no si se acaba de enviar
        $this->post(route('calificador.devolucion.correo.uno', $this->devolucion($this->ana)))->assertSessionHas('error_devolucion', fn ($s) => str_contains($s, 'acaba de enviar'));
        $this->post(route('calificador.devolucion.correo.uno', $this->devolucion($this->beto)))->assertSessionHas('error_devolucion', fn ($s) => str_contains($s, 'no es del dominio institucional'));
    }

    #[Test]
    public function un_fallo_queda_registrado_y_no_detiene_a_los_demas(): void
    {
        $this->carla->update(['email' => 'carla@ejemplo.edu.co']);
        $this->liberar();
        Mail::shouldReceive('to')->andReturnUsing(function ($correo) {
            $m = \Mockery::mock();
            $m->shouldReceive('send')->andReturnUsing(function () use ($correo) {
                if ($correo === 'ana@ejemplo.edu.co') {
                    throw new RuntimeException('Connection could not be established with host smtp.office365.com');
                }

                return null;
            });

            return $m;
        });

        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'pendientes'])
            ->assertSessionHas('error_devolucion', fn ($s) => str_contains($s, '1 correo(s) enviados') && str_contains($s, '1 fallaron'));
        $this->assertStringContainsString('Connection could not be established', $this->devolucion($this->ana)->correo_error);
        $this->assertNull($this->devolucion($this->ana)->correo_enviado_en);
        $this->assertNotNull($this->devolucion($this->carla)->correo_enviado_en);
        $this->get(route('calificador.devolucion', $this->actividad))->assertSee('Falló')->assertSee('Reenviar');
    }

    #[Test]
    public function en_cola_se_despacha_y_no_envia_si_la_devolucion_se_retira(): void
    {
        config(['plataforma.correo_en_cola' => true]);
        Queue::fake();
        $this->liberar();
        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'pendientes'])->assertSessionHas('estado', fn ($s) => str_contains($s, '1 en cola'));
        Queue::assertPushed(EnviarEnlaceInforme::class, 1);
        $this->assertNotNull($this->devolucion($this->ana)->correo_en_cola_en);
        $this->get(route('calificador.devolucion', $this->actividad))->assertSee('En cola');

        // El trabajo envía; si antes se retiró la devolución, no
        $job = new EnviarEnlaceInforme($this->devolucion($this->ana)->id, $this->docente->id, null);
        $job->handle(app(EnvioInformes::class));
        Mail::assertSent(EnlaceInforme::class, 1);
        $this->post(route('calificador.devolucion.revocar', $this->actividad));
        $job->handle(app(EnvioInformes::class));
        Mail::assertSent(EnlaceInforme::class, 1);
    }

    #[Test]
    public function avisa_si_el_correo_no_esta_configurado_y_otro_docente_no_envia(): void
    {
        config(['mail.default' => 'log']);
        $this->liberar();
        $this->get(route('calificador.devolucion', $this->actividad))->assertSee('El correo de la plataforma no está configurado');

        $d = $this->devolucion($this->ana);
        $this->actingAs(User::factory()->create());
        $this->post(route('calificador.devolucion.correo', $this->actividad), ['alcance' => 'todos'])->assertForbidden();
        $this->post(route('calificador.devolucion.correo.uno', $d))->assertForbidden();
        Mail::assertNothingSent();
    }
}
