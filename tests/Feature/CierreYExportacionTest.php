<?php

namespace Tests\Feature;

use App\Archivos\LectorTabla;
use App\Dictados\RespaldoDictado;
use App\Models\Estudiante;
use App\Models\User;
use App\Notas\CierrePeriodo;
use App\Notas\ClasificacionCierre;
use App\Notas\ExportadorNotas;
use App\Notas\RegistroNotas;
use App\Notas\TableroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Exportación (3.1.11, 3.1.14), cierre (3.7), edición de estudiantes y respaldo (3.1.10). */
class CierreYExportacionTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    /** @var array<int, Estudiante> */
    private array $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->e = [
            $this->crearEstudiante('1', 'ALTO', 'ANA'),
            $this->crearEstudiante('2', 'BAJO', 'BETO'),
            $this->crearEstudiante('3', 'MEDIO', 'CARLA'),
        ];
        $this->actingAs($this->docente);
    }

    /** Corte 1 completo y Corte 2 con una sola actividad; Corte 3 sin actividades. */
    private function cargarNotas(): void
    {
        $r = app(RegistroNotas::class);
        $quiz = $this->crearActividad($this->trabajos, ['nombre' => 'Quiz 1']);
        $parcial = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1']);
        foreach ([['5', '4'], ['1', '2'], ['3', null]] as $i => [$q, $p]) {
            $r->asignar($quiz, $this->e[$i], $q, 'digitada');
            if ($p !== null) {
                $r->asignar($parcial, $this->e[$i], $p, 'digitada');
            }
        }
    }

    #[Test]
    public function la_planilla_no_exporta_como_final_un_periodo_incompleto(): void
    {
        $this->cargarNotas();
        $planilla = app(ExportadorNotas::class)->planilla(new TableroNotas($this->grupo));

        $this->assertSame(['Documento', 'Apellidos', 'Nombres', 'Corte 1 (30 %)', 'Estado Corte 1'], array_slice($planilla[0], 0, 5));
        $ana = $planilla[1];
        $this->assertSame(4.5, $ana[3]);
        $this->assertSame('Aprobado', $ana[4]);
        $this->assertNull($ana[9], 'Sin nota final mientras faltan cortes.');
        $this->assertStringStartsWith('En curso', $ana[10]);
        $this->assertSame('Incompleto', $planilla[3][4], 'Carla no tiene parcial: corte incompleto.');
    }

    #[Test]
    public function descarga_xlsx_con_una_hoja_por_corte_y_csv_para_excel(): void
    {
        $this->cargarNotas();

        $this->get(route('exportar.xlsx', $this->grupo))->assertOk()->assertDownload();
        $ruta = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        app(ExportadorNotas::class)->xlsx($this->grupo, $ruta);
        $hoja1 = (new LectorTabla)->leer($ruta, 'xlsx');
        $this->assertContains('Trabajos y quices: Quiz 1', $hoja1['encabezados']);

        $r = $this->get(route('exportar.csv', $this->grupo));
        $r->assertOk()->assertDownload();
        $contenido = file_get_contents($r->getFile()->getPathname());
        $this->assertStringStartsWith("\u{FEFF}", $contenido, 'BOM para que Excel reconozca UTF-8.');
        $this->assertStringContainsString('1;ALTO;ANA;4,5;Aprobado', $contenido);
    }

    #[Test]
    public function el_simulador_calcula_lo_que_necesita_cada_uno(): void
    {
        $this->cargarNotas();
        // Ana: corte 1 = 4.5 → aporte 1.35; pendiente 70 % → necesita (3 − 1.35)/0.7 = 2.357…
        $sim = collect((new CierrePeriodo($this->grupo))->simulacion())->keyBy(fn ($f) => $f['estudiante']->documento);

        $this->assertSame('1.35', $sim['1']['acumulado']);
        $this->assertSame('0.7', $sim['1']['pendiente']);
        $this->assertSame(ClasificacionCierre::DependeDeLoPendiente, $sim['1']['simulacion']->clasificacion);
        $this->assertStringStartsWith('2.357', $sim['1']['simulacion']->notaNecesaria);
        // Beto: corte 1 = 1.5 → necesita (3 − 0.45)/0.7 = 3.64
        $this->assertStringStartsWith('3.642', $sim['2']['simulacion']->notaNecesaria);
        // Carla: corte 1 incompleto → todo pendiente
        $this->assertSame('1', $sim['3']['pendiente']);
        $this->assertSame(['Corte 1 (incompleto)', 'Corte 2', 'Corte 3'], $sim['3']['pendientes']);
    }

    #[Test]
    public function detecta_quien_aprueba_solo_porque_una_vacia_no_promedia(): void
    {
        $r = app(RegistroNotas::class);
        $q1 = $this->crearActividad($this->trabajos);
        $q2 = $this->crearActividad($this->trabajos);
        $p = $this->crearActividad($this->parcial);
        // Ana no presentó el quiz 2 (que otros sí presentaron): 3.0 con vacía, 2.25 si contara como cero
        $r->asignar($q1, $this->e[0], '3', 'digitada');
        $r->asignar($p, $this->e[0], '3', 'digitada');
        $r->asignar($q2, $this->e[1], '4', 'digitada');

        $sim = collect((new CierrePeriodo($this->grupo))->simulacion())->keyBy(fn ($f) => $f['estudiante']->documento);
        $this->assertTrue($sim['1']['beneficio_vacias']);

        $ceros = (new CierrePeriodo($this->grupo))->ceros();
        $vacia = collect($ceros)->first(fn ($c) => $c['estudiante']->documento === '1' && $c['actividad_id'] === $q2->id);
        $this->assertSame('vacia', $vacia['tipo']);
    }

    #[Test]
    public function la_revision_de_ceros_ordena_por_cercania_al_limite(): void
    {
        $r = app(RegistroNotas::class);
        $q = $this->crearActividad($this->trabajos);
        $p = $this->crearActividad($this->parcial);
        $r->asignar($q, $this->e[0], '0', 'digitada');   // Ana: quiz 0, parcial 5 → 2.5 → a 0.5 del límite
        $r->asignar($p, $this->e[0], '5', 'digitada');
        $r->asignar($q, $this->e[1], '0', 'digitada');   // Beto: quiz 0, parcial 0.4 → 0.2 → a 2.8 del límite
        $r->asignar($p, $this->e[1], '0.4', 'digitada');

        $ceros = (new CierrePeriodo($this->grupo))->ceros();
        $this->assertSame(['1', '2'], array_slice(array_map(fn ($c) => $c['estudiante']->documento, $ceros), 0, 2));

        $this->get(route('cierre', $this->grupo))->assertOk()
            ->assertSee('solo calcula')->assertSee('Ceros y vacías para revisar')->assertSee('0 Cero');
    }

    #[Test]
    public function marca_el_cero_de_quien_entrego_en_pareja_con_alguien_que_si_tiene_nota(): void
    {
        // 3.7.9: Ana y Beto entregaron juntos el taller 1; en el taller 2 Beto tiene nota y Ana cero
        $r = app(RegistroNotas::class);
        $t1 = $this->crearActividad($this->trabajos, ['es_grupal' => true]);
        $t2 = $this->crearActividad($this->trabajos, ['es_grupal' => true]);
        $this->crearEntrega($t1, $this->e[0], $this->e[1]);
        $r->asignar($t2, $this->e[0], '0', 'digitada');
        $r->asignar($t2, $this->e[1], '4', 'digitada');
        $r->asignar($t2, $this->e[2], '0', 'digitada');

        $ceros = collect((new CierrePeriodo($this->grupo))->ceros())->keyBy(fn ($c) => $c['estudiante']->documento);
        $this->assertSame([$this->e[1]->id], $ceros['1']['companeros_con_nota']->pluck('id')->all());
        $this->assertTrue($ceros['3']['companeros_con_nota']->isEmpty());
        $this->get(route('cierre', $this->grupo))->assertOk()->assertSee('con quien ha entregado en grupo, sí tiene nota');
    }

    #[Test]
    public function editar_estudiante_corrige_el_nombre_y_retirar_conserva_notas(): void
    {
        $sinComa = $this->crearEstudiante('9', 'PEREZ LUIS', '');
        $sinComa->update(['revisar_nombre' => true]);
        $a = $this->crearActividad();
        app(RegistroNotas::class)->asignar($a, $sinComa, '4', 'digitada');

        $this->get(route('estudiantes.edit', [$this->grupo, $sinComa]))->assertOk()->assertSee('Sepárelo');

        $this->put(route('estudiantes.update', [$this->grupo, $sinComa]), [
            'apellidos' => 'PEREZ', 'nombres' => 'LUIS', 'activo' => '0',
        ])->assertRedirect(route('registro', $this->grupo));

        $sinComa->refresh();
        $this->assertSame(['PEREZ', 'LUIS', false], [$sinComa->apellidos, $sinComa->nombres, $sinComa->revisar_nombre]);
        $this->assertSame('4.0000', $sinComa->notas()->value('valor'));
        $this->assertFalse((new TableroNotas($this->grupo))->estudiantes()->contains('id', $sinComa->id));
        $this->get(route('registro', $this->grupo))->assertSee('Retirados del grupo (1)');
    }

    #[Test]
    public function el_respaldo_incluye_estructura_estudiantes_notas_y_bitacora(): void
    {
        $this->cargarNotas();

        $r = $this->get(route('respaldo', $this->dictado));
        $r->assertOk()->assertDownload();
        $json = json_decode($r->streamedContent(), true);

        $this->assertSame(RespaldoDictado::FORMATO, $json['formato']);
        $this->assertCount(3, $json['estudiantes']);
        $this->assertSame('Quiz 1', $json['divisiones'][0]['componentes'][0]['actividades'][0]['nombre']);
        $this->assertSame('5.0000', collect($json['divisiones'][0]['componentes'][0]['actividades'][0]['notas'])->firstWhere('documento', '1')['valor']);
        $this->assertCount(5, $json['bitacora']);
    }

    #[Test]
    public function todo_esto_es_solo_para_el_docente_dueno(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('cierre', $this->grupo))->assertForbidden();
        $this->get(route('exportar.xlsx', $this->grupo))->assertForbidden();
        $this->get(route('exportar.csv', $this->grupo))->assertForbidden();
        $this->get(route('respaldo', $this->dictado))->assertForbidden();
        $this->get(route('estudiantes.edit', [$this->grupo, $this->e[0]]))->assertForbidden();
    }
}
