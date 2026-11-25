<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Livewire\RegistroNotas;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\ArtefactoPregunta;
use App\Models\ConfigMotorIa;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\NotaCambio;
use App\Models\Reclamo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/**
 * Reclamos y su resolución (6.5.4, 6.5.6).
 * Ítem 1 (única, 2 pts, correcta «b»), ítem 2 (completar, 2 pts, «return»), ítem 3 (V/F, 1 pt, verdadero).
 * Ana: b, «devolver», V → 2 + 0 + 1 = 3 · Beto: a, «devolver», V → 0 + 0 + 1 = 1 · Carla: b, «return», F → 2 + 2 + 0 = 4.
 */
class ReclamosTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private Estudiante $ana;

    private Estudiante $beto;

    private Estudiante $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('201', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('202', 'BELTRAN', 'BETO');
        $this->carla = $this->crearEstudiante('203', 'CASTRO', 'CARLA');

        app(ImportadorBanco::class)->importar($this->materia, [
            'formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-REC-P1', 'titulo' => 'Parcial de funciones', 'valor' => '5',
            'items' => [
                ['tipo' => 'unica', 'peso' => '2', 'enunciado' => '¿Cuál declara una función?', 'opciones' => ['a' => 'function f()', 'b' => 'const f = () =>'], 'correcta' => 'b'],
                ['tipo' => 'completar', 'peso' => '2', 'enunciado' => 'Complete.', 'plantilla' => 'function f() { ___ 1; }', 'huecos' => ['return']],
                ['tipo' => 'vf', 'peso' => '1', 'enunciado' => 'Una función puede no retornar.', 'correcta' => true],
            ],
        ]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Parcial 1', 'valor_max' => '5']);
        $this->actividad->grupos()->attach($this->grupo->id);

        foreach ([[$this->ana, 'b', 'devolver', true], [$this->beto, 'a', 'devolver', true], [$this->carla, 'b', 'return', false]] as [$e, $u, $h, $vf]) {
            $this->entregar($e, $u, $h, $vf);
        }
        $this->post(route('calificador.registro', $this->actividad));
    }

    private function entregar(Estudiante $e, string $unica, string $hueco, bool $vf): void
    {
        $ids = $this->actividad->artefacto->items()->orderBy('orden')->pluck('id')->all();
        $carga = ['sigla' => 'ED-REC-P1', 'artefacto' => $this->actividad->artefacto->uuid, 'documento' => $e->documento, 'nombre' => $e->nombreCompleto(),
            'integrantes' => [], 'inicio' => now()->subMinutes(20)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual',
            'respuestas' => [$ids[0] => $unica, $ids[1] => [$hueco], $ids[2] => $vf], 'sesion' => []];
        $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'ED-REC-P1', 'artefacto' => $this->actividad->artefacto->uuid,
            'documento' => $e->documento, 'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))];
        $ruta = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($ruta, json_encode($sobre));
        $this->post(route('calificador.subir', $this->actividad), ['entregas' => [new UploadedFile($ruta, 'e.json', 'application/json', null, true)]]);
    }

    private function item(int $n): ArtefactoPregunta
    {
        return Artefacto::query()->sole()->items()->orderBy('orden')->get()[$n - 1];
    }

    private function nota(Estudiante $e): string
    {
        return Nota::query()->where(['actividad_id' => $this->actividad->id, 'estudiante_id' => $e->id])->value('valor');
    }

    private function reclamo(Estudiante $e, ?int $item, string $texto = 'Mi respuesta también es válida'): Reclamo
    {
        $this->post(route('calificador.reclamos.store', $this->actividad), [
            'estudiante_id' => $e->id, 'artefacto_pregunta_id' => $item ? $this->item($item)->id : null, 'descripcion' => $texto,
        ])->assertSessionHas('estado');

        return Reclamo::query()->latest('id')->first();
    }

    #[Test]
    public function deshacer_el_paso_al_registro_revierte_todas_las_notas_del_calificador(): void
    {
        // Regresión: la operación de «Pasar notas al registro» quedaba vacía y deshacerla no revertía nada
        $pila = session("deshacer.grupo.{$this->grupo->id}");
        $this->assertStringContainsString('Notas de «Parcial 1» desde el calificador', end($pila)['descripcion']);
        $this->assertSame(3, NotaCambio::query()->where('operacion_id', end($pila)['op'])->count());

        Livewire::test(RegistroNotas::class, ['grupo' => $this->grupo])->call('deshacer');

        $this->assertSame([null, null, null], Nota::query()->where('actividad_id', $this->actividad->id)->orderBy('estudiante_id')->pluck('valor')->all());
    }

    #[Test]
    public function corregir_la_clave_recalifica_a_todo_el_grupo_no_solo_al_reclamante(): void
    {
        $this->assertSame(['3.0000', '1.0000', '4.0000'], [$this->nota($this->ana), $this->nota($this->beto), $this->nota($this->carla)]);
        $r = $this->reclamo($this->ana, 2, '«devolver» es la palabra que usamos en clase');

        $this->post(route('calificador.reclamos.resolver', $r), [
            'decision' => 'aceptar', 'accion' => 'correccion_clave', 'huecos' => ['return | devolver'],
            'resolucion' => 'Se acepta «devolver», como se usó en clase.',
        ])->assertSessionHas('estado', fn ($m) => str_contains($m, '2 nota(s) del registro cambiaron'));

        // Ana y Beto escribieron «devolver»: ambos suben 2 puntos
        $this->assertSame(['5.0000', '3.0000', '4.0000'], [$this->nota($this->ana), $this->nota($this->beto), $this->nota($this->carla)]);
        $r->refresh();
        $this->assertSame(['aceptado', 'correccion_clave', ['return'], [['return', 'devolver']], 3],
            [$r->estado, $r->accion, $r->detalle['clave_anterior']['huecos'], $r->detalle['clave_nueva']['huecos'], $r->detalle['respuestas_recalificadas']]);
        $this->assertStringContainsString('Corrección de la clave del ítem 2', NotaCambio::query()->where('estudiante_id', $this->beto->id)->latest('id')->value('motivo'));
    }

    #[Test]
    public function cambiar_la_respuesta_correcta_tambien_quita_puntos_a_quien_la_tenia(): void
    {
        $r = $this->reclamo($this->beto, 1, 'La a también declara una función, y es la forma que vimos');
        $this->post(route('calificador.reclamos.resolver', $r), [
            'decision' => 'aceptar', 'accion' => 'correccion_clave', 'correcta' => 'a', 'resolucion' => 'La clave estaba invertida.',
        ]);

        $this->assertSame(['1.0000', '3.0000', '2.0000'], [$this->nota($this->ana), $this->nota($this->beto), $this->nota($this->carla)]);
    }

    #[Test]
    public function aceptar_anulando_el_item(): void
    {
        $r = $this->reclamo($this->carla, 3, 'El enunciado es ambiguo');
        $this->post(route('calificador.reclamos.resolver', $r), [
            'decision' => 'aceptar', 'accion' => 'anulacion', 'regla' => 'dar_por_buena', 'resolucion' => 'Admitía dos lecturas.',
        ]);

        $this->assertNotNull($this->item(3)->anulada_en);
        $this->assertSame('5.0000', $this->nota($this->carla));
        $this->assertSame(1, $r->fresh()->detalle['notas_recalculadas'], 'Solo cambia Carla: Ana y Beto ya tenían el ítem bien.');
    }

    #[Test]
    public function el_ajuste_individual_solo_cambia_al_reclamante(): void
    {
        $r = $this->reclamo($this->beto, null, 'Entregué la corrección a tiempo y no se tuvo en cuenta');
        $this->post(route('calificador.reclamos.resolver', $r), [
            'decision' => 'aceptar', 'accion' => 'ajuste_nota', 'valor' => '2.5', 'resolucion' => 'Se verificó la entrega por correo.',
        ]);

        $this->assertSame(['3.0000', '2.5000', '4.0000'], [$this->nota($this->ana), $this->nota($this->beto), $this->nota($this->carla)]);
        $n = Nota::query()->where(['actividad_id' => $this->actividad->id, 'estudiante_id' => $this->beto->id])->sole();
        $this->assertTrue($n->ajuste_individual);
        $this->assertStringContainsString("Reclamo #{$r->id} aceptado", $n->cambios->last()->motivo);
        $this->assertSame(['1.0000', '2.5'], [$r->fresh()->detalle['nota_anterior'], $r->fresh()->detalle['nota_nueva']]);
    }

    #[Test]
    public function rechazar_deja_la_respuesta_y_un_reclamo_atendido_no_cambia(): void
    {
        $r = $this->reclamo($this->ana, 1);
        $this->post(route('calificador.reclamos.resolver', $r), ['decision' => 'rechazar', 'resolucion' => 'La opción b es la única correcta.']);

        $this->assertSame(['rechazado', '3.0000'], [$r->fresh()->estado, $this->nota($this->ana)]);
        $this->post(route('calificador.reclamos.resolver', $r), ['decision' => 'aceptar', 'accion' => 'ajuste_nota', 'valor' => '5', 'resolucion' => 'Cambio de opinión'])
            ->assertSessionHas('error_reclamo', 'Este reclamo ya fue atendido.');
        $this->assertSame('3.0000', $this->nota($this->ana));

        $this->expectException(LogicException::class);
        $r->fresh()->delete();
    }

    #[Test]
    public function el_estudiante_ve_en_su_informe_solo_sus_reclamos_atendidos(): void
    {
        $atendido = $this->reclamo($this->ana, 2);
        $this->post(route('calificador.reclamos.resolver', $atendido), ['decision' => 'rechazar', 'resolucion' => 'Se pedía la palabra reservada.']);
        $this->reclamo($this->ana, 1, 'Reclamo aún abierto');
        $deBeto = $this->reclamo($this->beto, 1, 'Reclamo de Beto');
        $this->post(route('calificador.reclamos.resolver', $deBeto), ['decision' => 'rechazar', 'resolucion' => 'Respuesta para Beto']);

        $html = $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->ana]))->getContent();
        $this->assertStringContainsString('fue rechazado.</strong> Se pedía la palabra reservada.', $html);
        $this->assertStringNotContainsString('Respuesta para Beto', $html);
        $this->assertStringNotContainsString('Reclamo aún abierto', $html);
    }

    #[Test]
    public function queda_en_el_registro_de_la_materia_en_las_estadisticas_y_en_la_memoria_de_la_ia(): void
    {
        $r = $this->reclamo($this->carla, 3, 'Ambiguo');
        $this->post(route('calificador.reclamos.resolver', $r), ['decision' => 'aceptar', 'accion' => 'anulacion', 'regla' => 'dar_por_buena', 'resolucion' => 'El enunciado no decía si debía retornar']);

        $this->get(route('reclamos.materia', $this->materia))->assertOk()->assertSee('Se anuló el ítem para todo el grupo')->assertSee('Parcial 1');
        $this->get(route('calificador.estadisticas', $this->actividad))->assertSee('⚑ 1 reclamo(s), 1 aceptado(s)');

        ConfigMotorIa::query()->create(['user_id' => $this->docente->id, 'nombre' => 'L', 'driver' => 'ollama', 'base_url' => 'http://x.test', 'modelo' => 'm', 'es_local' => true, 'predeterminado' => true]);
        $p = $this->post(route('generacion.preparar', $this->materia), [
            'dictado_id' => $this->dictado->id, 'tipo' => 'quiz', 'tema' => 'Funciones', 'valor' => '5', 'cantidades' => ['vf' => 2],
        ])->viewData('prompt');
        $this->assertStringContainsString('reclamos que prosperaron; no repita el error', $p['usuario']);
        $this->assertStringContainsString('«Una función puede no retornar.»: El enunciado no decía si debía retornar', $p['usuario']);
    }

    #[Test]
    public function una_clave_corregida_invalida_no_se_aplica(): void
    {
        $r = $this->reclamo($this->ana, 1);
        $this->post(route('calificador.reclamos.resolver', $r), ['decision' => 'aceptar', 'accion' => 'correccion_clave', 'correcta' => 'z', 'resolucion' => 'Prueba de clave mala'])
            ->assertSessionHas('error_reclamo');

        $this->assertSame(['abierto', 'b'], [$r->fresh()->estado, $this->item(1)->pregunta->clave['correcta']]);
    }

    #[Test]
    public function solo_el_docente_dueno(): void
    {
        $r = $this->reclamo($this->ana, 1);
        $this->actingAs(User::factory()->create());

        $this->get(route('calificador.reclamos', $this->actividad))->assertForbidden();
        $this->post(route('calificador.reclamos.resolver', $r), ['decision' => 'rechazar', 'resolucion' => 'intruso'])->assertForbidden();
        $this->get(route('reclamos.materia', $this->materia))->assertForbidden();
    }
}
