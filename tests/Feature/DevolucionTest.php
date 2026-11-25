<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Devolucion;
use App\Models\Estudiante;
use App\Models\Respuesta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;
use ZipArchive;

/** Informe individual (6.5) y devolución al estudiante (6.9). */
class DevolucionTest extends TestCase
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
        $this->ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('102', 'BELTRAN', 'BETO');
        $this->carla = $this->crearEstudiante('103', 'CASTRO', 'CARLA');      // no presenta

        app(ImportadorBanco::class)->importar($this->materia, [
            'formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-DEV-Q1', 'titulo' => 'Quiz de pilas', 'valor' => '5',
            'items' => [
                ['tipo' => 'unica', 'peso' => '3', 'enunciado' => '¿Qué estructura es LIFO?', 'opciones' => ['a' => 'Cola', 'b' => 'Pila'], 'correcta' => 'b',
                    'justificacion' => 'En una pila sale primero el último que entró.'],
                ['tipo' => 'abierta', 'peso' => '2', 'enunciado' => 'Dé un ejemplo de pila.'],
            ],
        ]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Quiz de pilas', 'valor_max' => '5']);
        $this->actividad->grupos()->attach($this->grupo->id);

        $this->entregar($this->ana, 'b', 'Los platos de un restaurante', salidas: 4);
        $this->entregar($this->beto, 'a', 'La fila del banco');
        foreach (Respuesta::query()->whereNull('puntaje')->get() as $r) {
            $r->update(['puntaje' => $r->entrega->subida_por_id === $this->ana->id ? '2' : '0', 'comentario' => 'Comentario para '.$r->entrega->subidaPor->nombres]);
        }
        $this->post(route('calificador.registro', $this->actividad));
    }

    private function entregar(Estudiante $e, string $unica, string $abierta, int $salidas = 0, array $integrantes = []): void
    {
        $ids = $this->actividad->artefacto->items()->orderBy('orden')->pluck('id')->all();
        $carga = ['sigla' => 'ED-DEV-Q1', 'artefacto' => $this->actividad->artefacto->uuid, 'documento' => $e->documento, 'nombre' => $e->nombreCompleto(),
            'integrantes' => $integrantes, 'inicio' => now()->subMinutes(20)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual',
            'respuestas' => [$ids[0] => $unica, $ids[1] => $abierta],
            'sesion' => ['salidas' => array_fill(0, $salidas, ['inicio' => now()->toIso8601String(), 'segundos' => 30]), 'pegados' => 0]];
        $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'ED-DEV-Q1', 'artefacto' => $this->actividad->artefacto->uuid,
            'documento' => $e->documento, 'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))];
        $ruta = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($ruta, json_encode($sobre));
        $this->post(route('calificador.subir', $this->actividad), ['entregas' => [new UploadedFile($ruta, 'e.json', 'application/json', null, true)]]);
    }

    private function enlaceDe(Estudiante $e): string
    {
        $csv = $this->get(route('calificador.devolucion.enlaces', $this->actividad))->streamedContent();
        preg_match('/^'.$e->documento.';.*;(http\S+);/m', $csv, $m);

        return $m[1];
    }

    #[Test]
    public function el_informe_explica_la_nota_y_no_muestra_alertas_ni_a_otros(): void
    {
        $html = $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->ana]))->assertOk()->getContent();

        $this->assertStringContainsString('ARIAS, ANA', $html);
        $this->assertMatchesRegularExpression('/nota-grande">5\.0</', $html);           // 3 + 2
        $this->assertStringContainsString('Correcta: <strong>b) Pila</strong>', $html);
        $this->assertStringContainsString('En una pila sale primero el último que entró.', $html);
        $this->assertStringContainsString('Comentario para ANA', $html);
        foreach (['BELTRAN', 'BETO', 'Comentario para BETO', 'La fila del banco', 'Salidas', 'salidas', 'Pegados'] as $ajeno) {
            $this->assertStringNotContainsString($ajeno, $html, "El informe de Ana no debe contener «{$ajeno}».");
        }
        $this->assertStringContainsString('<style>', $html, 'Autocontenido: el estilo va dentro del archivo.');
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
    }

    #[Test]
    public function nada_sale_antes_de_liberar_y_avisa_quien_no_ha_presentado(): void
    {
        $this->get(route('calificador.devolucion', $this->actividad))->assertOk()
            ->assertSee('1 estudiante(s) aún no han presentado');
        $this->get(route('calificador.devolucion.zip', $this->actividad))->assertSessionHas('error_devolucion');
        $this->get(route('calificador.devolucion.enlaces', $this->actividad))->assertSessionHas('error_devolucion');
        $this->assertSame(0, Devolucion::query()->count());
    }

    #[Test]
    public function el_enlace_personal_muestra_solo_lo_suyo_y_registra_la_apertura(): void
    {
        $this->post(route('calificador.devolucion.liberar', $this->actividad), ['dias' => 15])->assertSessionHas('estado');
        $enlace = $this->enlaceDe($this->beto);

        auth()->logout();
        $html = $this->get($enlace)->assertOk()->getContent();
        $this->assertStringContainsString('BELTRAN, BETO', $html);
        $this->assertStringNotContainsString('ARIAS', $html);
        $this->assertMatchesRegularExpression('/nota-grande">0\.0</', $html);    // eligió «Cola» y la abierta quedó en 0
        $this->get($enlace)->assertOk();

        $d = Devolucion::query()->where('estudiante_id', $this->beto->id)->sole();
        $this->assertSame(2, $d->aperturas);
        $this->assertTrue($d->recibida());
        $this->assertEqualsWithDelta(now()->addDays(15)->timestamp, $d->enlace_vence_en->timestamp, 5);
    }

    #[Test]
    public function un_enlace_manipulado_vencido_o_revocado_no_funciona(): void
    {
        $this->post(route('calificador.devolucion.liberar', $this->actividad), ['dias' => 1]);
        $enlace = $this->enlaceDe($this->ana);
        $otro = Devolucion::query()->where('estudiante_id', $this->beto->id)->value('id');
        $manipulado = preg_replace('~/informe/\d+~', "/informe/{$otro}", $enlace);

        auth()->logout();
        $this->get($manipulado)->assertForbidden();
        $this->travel(2)->days();
        $this->get($enlace)->assertForbidden();         // la firma vence con el enlace
        $this->travelBack();

        $this->actingAs($this->docente)->post(route('calificador.devolucion.revocar', $this->actividad));
        auth()->logout();
        $this->get($enlace)->assertStatus(410);
    }

    #[Test]
    public function el_zip_trae_un_informe_por_estudiante_que_presento(): void
    {
        $this->post(route('calificador.devolucion.liberar', $this->actividad), ['dias' => 30]);
        $r = $this->get(route('calificador.devolucion.zip', $this->actividad))->assertOk()->assertDownload('informes-quiz-de-pilas.zip');

        $zip = new ZipArchive;
        $zip->open($r->getFile()->getPathname());
        $nombres = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        sort($nombres);
        $this->assertSame(['101-arias-ana.html', '102-beltran-beto.html'], $nombres, 'Carla no presentó: no hay informe que enviar.');
        $this->assertStringNotContainsString('BELTRAN', $zip->getFromName('101-arias-ana.html'));
    }

    #[Test]
    public function con_supletorio_muestra_la_nota_vigente_y_la_original_si_se_activa(): void
    {
        $this->post(route('segundas.store', [$this->grupo, $this->carla]), [
            'ambito' => "actividad:{$this->actividad->id}", 'tipo' => 'supletorio', 'valor' => '3.8', 'regla' => 'reemplaza', 'fecha' => now()->toDateString(),
        ]);
        $ver = fn () => $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->carla]))->getContent();

        $this->assertMatchesRegularExpression('/nota-grande">3\.8</', $ver());
        $this->assertStringContainsString('Incluye supletorio', $ver());
        $this->assertStringNotContainsString('Nota original', $ver());

        $this->post(route('calificador.devolucion.configurar', $this->actividad), ['informe_muestra_original' => '1']);
        $this->assertStringContainsString('Nota original: sin presentar', $ver());
    }

    #[Test]
    public function la_entrega_grupal_nombra_a_los_companeros(): void
    {
        $this->actividad->update(['es_grupal' => true]);
        $this->entregar($this->carla, 'b', 'Una pila de libros', integrantes: ['102']);

        $this->assertStringContainsString('Entrega grupal con BELTRAN, BETO', $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->carla]))->getContent());
        $this->assertStringNotContainsString('Entrega grupal', $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->ana]))->getContent(),
            'Ana entregó sola: su informe no nombra a nadie más.');
    }

    #[Test]
    public function el_docente_registra_la_entrega_por_otro_canal(): void
    {
        $this->post(route('calificador.devolucion.liberar', $this->actividad), ['dias' => 30]);
        $d = Devolucion::query()->where('estudiante_id', $this->ana->id)->sole();

        $this->post(route('calificador.devolucion.marcar', $d));
        $this->assertNotNull($d->fresh()->entregada_en);
        $this->get(route('calificador.devolucion', $this->actividad))->assertSee('1 de 3 recibieron su informe');
    }

    #[Test]
    public function solo_el_docente_dueno(): void
    {
        $this->post(route('calificador.devolucion.liberar', $this->actividad), ['dias' => 30]);
        $d = Devolucion::query()->first();
        $this->actingAs(User::factory()->create());

        $this->get(route('calificador.devolucion', $this->actividad))->assertForbidden();
        $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->ana]))->assertForbidden();
        $this->get(route('calificador.devolucion.zip', $this->actividad))->assertForbidden();
        $this->get(route('calificador.devolucion.enlaces', $this->actividad))->assertForbidden();
        $this->post(route('calificador.devolucion.marcar', $d))->assertForbidden();
        $this->get(route('informe.publico', ['devolucion' => $d->id]))->assertForbidden();   // sin firma
    }
}
