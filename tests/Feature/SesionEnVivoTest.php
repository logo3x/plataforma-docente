<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\SesionEnVivo;
use App\Livewire\PanelEnVivo;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Artefacto;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\ParticipanteVivo;
use App\Models\SesionVivo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Sesión en vivo con internet: entrega automática, panel del grupo y finalización masiva (sección 13). */
class SesionEnVivoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private Estudiante $ana;

    private Estudiante $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('1098000001', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('1098000002', 'BELTRAN', 'BETO');
        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-VIVO', 'titulo' => 'Quiz en vivo', 'valor' => '5',
            'items' => [['tipo' => 'vf', 'peso' => '5', 'enunciado' => 'Una pila es LIFO.', 'correcta' => true]]]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Quiz en vivo', 'valor_max' => '5']);
        $this->actividad->grupos()->attach($this->grupo->id);
    }

    private function abrir(): SesionVivo
    {
        $this->post(route('calificador.vivo.abrir', $this->actividad))->assertRedirect();

        return SesionVivo::query()->latest('id')->first();
    }

    private function sobre(Estudiante $e, bool $respuesta, string $cierre = 'manual'): string
    {
        $item = Artefacto::query()->sole()->items()->first();
        $carga = ['sigla' => 'ED-VIVO', 'artefacto' => $this->actividad->artefacto->uuid, 'documento' => $e->documento, 'nombre' => $e->nombreCompleto(),
            'integrantes' => [], 'inicio' => now()->subMinutes(10)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => $cierre,
            'respuestas' => [$item->id => $respuesta], 'sesion' => ['salidas' => [], 'pegados' => 0], 'version' => 'A'];

        return json_encode(['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'ED-VIVO', 'artefacto' => $this->actividad->artefacto->uuid,
            'documento' => $e->documento, 'generado' => now()->toIso8601String(), 'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))]);
    }

    private function unirse(SesionVivo $s, Estudiante $e): string
    {
        return $this->postJson(route('vivo.unirse', $s->codigo), ['documento' => $e->documento, 'nombre' => $e->nombreCompleto(), 'version' => 'A', 'total' => 1])
            ->assertOk()->json('clave');
    }

    #[Test]
    public function el_estudiante_abre_el_examen_con_el_codigo_y_entrega_sin_descargar_nada(): void
    {
        $s = $this->abrir();
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $s->codigo);
        $this->get(route('calificador.show', $this->actividad))->assertSee('Sesión en vivo abierta')->assertSee($s->codigo);

        auth()->logout();
        $html = $this->get(route('vivo.examen', $s->codigo))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString("connect-src 'self' https:", html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString('"modo_desconectado":false', $html);
        $this->assertStringContainsString('"unirse":"'.route('vivo.unirse', $s->codigo).'"', $html);
        $this->get(route('vivo.entrar', ['codigo' => strtolower($s->codigo)]))->assertRedirect(route('vivo.examen', $s->codigo));
        $this->get(route('vivo.entrar', ['codigo' => 'NOEXISTE']))->assertNotFound()->assertSee('no corresponde a una sesión abierta');

        // Sin la credencial no hay latido ni entrega; el CSRF no aplica a estas rutas
        $this->postJson(route('vivo.latido', $s->codigo), ['clave' => 'falsa'])->assertForbidden();
        $clave = $this->unirse($s, $this->ana);
        $this->postJson(route('vivo.latido', $s->codigo), ['clave' => $clave, 'respondidas' => 1, 'total' => 1, 'salidas' => 2, 'segundos_fuera' => 35])
            ->assertOk()->assertJson(['cerrar' => false]);
        $p = ParticipanteVivo::query()->sole();
        $this->assertSame([1, 2, 35, $this->ana->id], [$p->respondidas, $p->salidas, $p->segundos_fuera, $p->estudiante_id]);

        // Una entrega con otro documento no se acepta
        $this->postJson(route('vivo.entrega', $s->codigo), ['clave' => $clave, 'sobre' => $this->sobre($this->beto, true)])->assertStatus(422);
        $this->postJson(route('vivo.entrega', $s->codigo), ['clave' => $clave, 'sobre' => $this->sobre($this->ana, true)])->assertOk()->assertJson(['estado' => 'recibida']);

        $e = Entrega::query()->sole();
        $this->assertSame([$this->ana->id], $e->integrantes->pluck('id')->all());
        $this->assertSame('5.0000', $e->respuestas()->first()->puntaje, 'Calificada por el servidor al instante (13.4)');
        $this->assertSame(['estudiante', $e->id], [$p->fresh()->finalizado_como, $p->fresh()->entrega_id]);

        // Ya entregó: no vuelve a entrar ni entrega otra vez
        $this->postJson(route('vivo.unirse', $s->codigo), ['documento' => $this->ana->documento, 'nombre' => 'ARIAS ANA'])->assertStatus(422)->assertJson(['message' => 'Este documento ya entregó el examen en esta sesión.']);
    }

    #[Test]
    public function la_finalizacion_masiva_cierra_a_todos_y_deja_constancia(): void
    {
        $s = $this->abrir();
        $claveAna = $this->unirse($s, $this->ana);
        $claveBeto = $this->unirse($s, $this->beto);
        // Beto envió una instantánea y luego su equipo dejó de responder
        $this->postJson(route('vivo.latido', $s->codigo), ['clave' => $claveBeto, 'respondidas' => 1, 'total' => 1, 'instantanea' => $this->sobre($this->beto, false, 'docente')])->assertOk();

        Livewire::test(PanelEnVivo::class, ['sesion' => $s])->assertSee('ARIAS, ANA')->assertSee('2</strong> entraron', false)
            ->call('finalizarTodos')->assertSee('Se pidió finalizar los 2 examen(es) abiertos');
        $this->assertNotNull($s->fresh()->cerrada_en);

        // Ana responde a la finalización: su examen se cierra y entrega solo
        $this->postJson(route('vivo.latido', $s->codigo), ['clave' => $claveAna])->assertJson(['cerrar' => true]);
        $this->postJson(route('vivo.entrega', $s->codigo), ['clave' => $claveAna, 'sobre' => $this->sobre($this->ana, true, 'docente')])->assertOk();

        // Beto no responde: pasada la espera, se registra con su instantánea
        $this->travel(SesionEnVivo::ESPERA_CIERRE + 1)->seconds();
        Livewire::test(PanelEnVivo::class, ['sesion' => $s])->assertSee('Cerrado por el docente, sin respuesta del equipo')
            ->assertSeeInOrder(['pidió finalizar 2 examen(es):', '1 respondieron y entregaron,', '1 se registraron con lo último que enviaron']);

        $this->assertSame(2, Entrega::query()->where('cierre', 'docente')->count());
        $alertas = Alerta::query()->where('tipo', 'cierre_docente')->get()->keyBy('estudiante_id');
        $this->assertFalse($alertas[$this->ana->id]->datos['sin_respuesta']);
        $this->assertTrue($alertas[$this->beto->id]->datos['sin_respuesta']);
        $this->assertSame($this->docente->name, $alertas[$this->beto->id]->datos['por']);
        $this->assertSame('0.0000', Entrega::query()->where('subida_por_id', $this->beto->id)->sole()->respuestas()->first()->puntaje);

        // Cerrada: nadie nuevo entra y el examen ya no se sirve
        $nuevo = $this->crearEstudiante('1098000003', 'CASTRO', 'CARLA');
        $this->postJson(route('vivo.unirse', $s->codigo), ['documento' => $nuevo->documento, 'nombre' => 'CASTRO CARLA'])->assertStatus(422);
        $this->get(route('vivo.examen', $s->codigo))->assertStatus(410);
        $this->get(route('calificador.detalle', Entrega::query()->where('subida_por_id', $this->ana->id)->sole()))->assertOk();
    }

    #[Test]
    public function reabrir_el_examen_invalida_la_ventana_anterior_y_se_ve_en_el_panel(): void
    {
        $s = $this->abrir();
        $primera = $this->unirse($s, $this->ana);
        $segunda = $this->unirse($s, $this->ana);
        $this->postJson(route('vivo.latido', $s->codigo), ['clave' => $primera])->assertForbidden();
        $this->postJson(route('vivo.latido', $s->codigo), ['clave' => $segunda])->assertOk();

        $this->travel(SesionEnVivo::SIN_SENAL + 5)->seconds();
        Livewire::test(PanelEnVivo::class, ['sesion' => $s])->assertSee('2 ingresos')->assertSee('Sin señal')->assertSee('BELTRAN, BETO');
    }

    #[Test]
    public function solo_el_docente_de_la_materia_abre_y_ve_la_sesion(): void
    {
        $s = $this->abrir();
        $this->actingAs(User::factory()->create());
        $this->post(route('calificador.vivo.abrir', $this->actividad))->assertForbidden();
        $this->get(route('calificador.vivo.panel', $s))->assertForbidden();
        $this->get(route('calificador.vivo.proyectar', $s))->assertForbidden();

        $this->actingAs($this->docente);
        $this->get(route('calificador.vivo.proyectar', $s))->assertOk()->assertSee($s->codigo)->assertSee(route('vivo.entrar'));
        // Abrir otra cierra la anterior
        $otra = $this->abrir();
        $this->assertNotNull($s->fresh()->cerrada_en);
        $this->assertNull($otra->cerrada_en);
    }
}
