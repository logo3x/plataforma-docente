<?php

namespace Tests\Feature;

use App\Calificacion\BitacoraAula;
use App\Evaluacion\ImportadorBanco;
use App\IA\ConstructorPrompt;
use App\Models\Alerta;
use App\Models\Artefacto;
use App\Models\Ensayo;
use App\Models\EntradaBitacora;
use App\Models\Materia;
use App\Models\Reclamo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Bitácora de retroalimentación de aula (anexo B). */
class BitacoraTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
    }

    #[Test]
    public function anota_desde_la_actividad_y_la_leccion_llega_a_la_ia_de_su_materia(): void
    {
        $actividad = $this->crearActividad(null, ['nombre' => 'Parcial 1']);
        $this->get(route('bitacora.index', ['actividad' => $actividad->id]))->assertOk()
            ->assertSee('Estructura de Datos · Parcial 1')->assertSee('La bitácora está vacía');

        $this->post(route('bitacora.store'), ['fecha' => now()->toDateString(), 'actividad_id' => $actividad->id,
            'observacion' => 'Los retos por consola se respondieron a ciegas.', 'destino' => 'leccion', 'para_ia' => '1'])->assertSessionHasErrors('leccion');
        $this->post(route('bitacora.store'), ['fecha' => now()->toDateString(), 'actividad_id' => $actividad->id,
            'observacion' => 'Los retos por consola se respondieron a ciegas.', 'destino' => 'leccion', 'referencia' => 'Sección 4',
            'leccion' => 'En los retos que imprimen por consola, declarar la salida esperada en cada caso visible.', 'para_ia' => '1'])->assertSessionHasNoErrors();

        $e = EntradaBitacora::query()->sole();
        $this->assertSame($this->materia->id, $e->materia_id, 'La materia sale de la actividad');
        $this->assertTrue($e->para_ia);

        $sistema = fn () => app(ConstructorPrompt::class)->construir($this->docente, $this->materia, $this->dictado->fresh(), 'banco', ['tipo' => 'quiz', 'tema' => 'Pilas', 'grupo' => 'Diurno', 'valor' => '5', 'cantidades' => '1', 'obligatorio' => '—', 'excluido' => '—', 'sigla' => 'X', 'no_repetir' => ''], collect())['sistema'];
        $this->assertStringContainsString("## Lecciones aprendidas en el aula con esta materia (téngalas en cuenta)\n- En los retos que imprimen por consola, declarar la salida esperada", $sistema());

        // Una lección sin marcar para la IA, o de otra materia, no entra
        $e->update(['para_ia' => false]);
        $this->assertStringNotContainsString('Lecciones aprendidas', $sistema());

        $this->get(route('bitacora.index'))->assertSee('Los retos por consola se respondieron a ciegas.')->assertSee('Lección aprendida')->assertSee('Sección 4');
    }

    #[Test]
    public function sugiere_lo_que_registro_la_plataforma_una_sola_vez(): void
    {
        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-P1', 'titulo' => 'Parcial', 'valor' => '5',
            'items' => [['tipo' => 'vf', 'peso' => '5', 'enunciado' => 'Una pila es FIFO.', 'correcta' => false]]]);
        $art = Artefacto::query()->sole();
        $actividad = $this->parcial->actividades()->create(['artefacto_id' => $art->id, 'nombre' => 'Parcial 1', 'valor_max' => '5']);
        $art->items()->first()->update(['anulada_en' => now(), 'regla_anulacion' => 'dar_por_buena', 'motivo_anulacion' => 'Enunciado ambiguo']);

        $est = collect(range(1, 4))->map(fn ($i) => $this->crearEstudiante("10{$i}", "APELLIDO{$i}", 'N'));
        $entregas = $est->map(fn ($e) => $this->crearEntrega($actividad, $e));
        foreach ($entregas->take(2) as $en) {
            Alerta::query()->create(['actividad_id' => $actividad->id, 'entrega_id' => $en->id, 'tipo' => 'duracion_anomala', 'datos' => []]);
        }
        Alerta::query()->create(['actividad_id' => $actividad->id, 'entrega_id' => $entregas[0]->id, 'tipo' => 'salidas', 'datos' => []]);   // 1 de 4: no basta
        Reclamo::query()->create(['actividad_id' => $actividad->id, 'estudiante_id' => $est[0]->id, 'descripcion' => 'La pregunta 1 admite dos lecturas',
            'estado' => 'aceptado', 'accion' => 'anulacion', 'resolucion' => 'Se anula para todos', 'atendido_en' => now()]);
        Ensayo::query()->create(['actividad_id' => $actividad->id, 'artefacto_id' => $art->id, 'user_id' => $this->docente->id, 'equipo' => 'Chrome 120 · Windows',
            'cifrada' => true, 'equipo_listo' => false, 'verificaciones' => [['nombre' => 'Autoguardado', 'ok' => false], ['nombre' => 'Cifrado', 'ok' => true]]]);

        $s = app(BitacoraAula::class)->sugerencias($this->docente);
        $this->assertEqualsCanonicalizing(['anulacion', 'reclamo', 'ensayo', 'alertas'], $s->pluck('origen')->all());
        $this->assertSame('En «Parcial 1», 2 de 4 entregas con alerta de duración anómala.', $s->firstWhere('origen', 'alertas')['observacion']);
        $this->assertStringContainsString('Ítem 1 anulado en ED-P1', $s->firstWhere('origen', 'anulacion')['observacion']);
        $this->assertStringContainsString('Enunciado ambiguo', $s->firstWhere('origen', 'anulacion')['observacion']);
        $this->assertStringContainsString('(Autoguardado)', $s->firstWhere('origen', 'ensayo')['observacion']);

        $this->get(route('bitacora.index'))->assertSee('Para anotar (4)');
        $this->post(route('bitacora.sugerencia'), ['origen' => 'reclamo', 'clave' => (string) Reclamo::query()->sole()->id])
            ->assertSessionHas('estado', 'Anotada como pendiente: decida su destino cuando quiera.');
        $this->post(route('bitacora.sugerencia'), ['origen' => 'ensayo', 'clave' => (string) Ensayo::query()->sole()->id, 'descartar' => '1']);
        $this->post(route('bitacora.sugerencia'), ['origen' => 'ensayo', 'clave' => (string) Ensayo::query()->sole()->id])->assertNotFound();

        $this->assertCount(2, app(BitacoraAula::class)->sugerencias($this->docente));
        $anotada = EntradaBitacora::query()->where('origen', 'reclamo')->sole();
        $this->assertSame([$actividad->id, $this->materia->id, 'pendiente'], [$anotada->actividad_id, $anotada->materia_id, $anotada->destino]);
        // Las descartadas no salen en la lista por defecto
        $this->get(route('bitacora.index'))->assertSee('Reclamo aceptado en «Parcial 1»')->assertDontSee('Ensayo de ED-P1 en Chrome');
        $this->get(route('bitacora.index', ['destino' => 'descartada']))->assertSee('Ensayo de ED-P1 en Chrome');
    }

    #[Test]
    public function exporta_la_tabla_del_anexo_b(): void
    {
        EntradaBitacora::query()->create(['user_id' => $this->docente->id, 'materia_id' => $this->materia->id, 'fecha' => '2026-09-15',
            'observacion' => 'Cada prueba de código | contó como salida', 'destino' => 'requerimiento', 'referencia' => 'Requerimiento 5.4.9']);
        EntradaBitacora::query()->create(['user_id' => $this->docente->id, 'fecha' => '2026-10-02', 'observacion' => 'Ruido', 'destino' => 'descartada']);

        $md = $this->get(route('bitacora.anexo'))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="bitacora-anexo-b.md"')->getContent();
        $this->assertSame("| Fecha | Materia y actividad | Observación | Destino |\n|---|---|---|---|\n"
            ."| 15 de septiembre de 2026 | Estructura de Datos | Cada prueba de código \\| contó como salida | Requerimiento 5.4.9 |\n", $md);
    }

    #[Test]
    public function cada_docente_ve_y_edita_solo_su_bitacora(): void
    {
        $mia = EntradaBitacora::query()->create(['user_id' => $this->docente->id, 'fecha' => now()->toDateString(), 'observacion' => 'Mía', 'destino' => 'pendiente']);
        $ajena = Materia::query()->create(['user_id' => User::factory()->create()->id, 'nombre' => 'Ajena']);
        $this->post(route('bitacora.store'), ['fecha' => now()->toDateString(), 'materia_id' => $ajena->id, 'observacion' => 'x', 'destino' => 'pendiente'])
            ->assertSessionHasErrors('materia_id');
        $this->post(route('bitacora.store'), ['fecha' => now()->addDay()->toDateString(), 'observacion' => 'x', 'destino' => 'pendiente'])->assertSessionHasErrors('fecha');

        $otro = User::factory()->create();
        $this->actingAs($otro);
        $this->get(route('bitacora.index'))->assertOk()->assertDontSee('Mía');
        $this->put(route('bitacora.update', $mia), ['fecha' => now()->toDateString(), 'observacion' => 'x', 'destino' => 'pendiente'])->assertForbidden();
        $this->delete(route('bitacora.destroy', $mia))->assertForbidden();
        $this->get(route('bitacora.index', ['actividad' => $this->crearActividad()->id]))->assertForbidden();
    }
}
