<?php

namespace Tests\Feature;

use App\Models\Dictado;
use App\Models\Grupo;
use App\Models\Materia;
use App\Models\NotaCambio;
use App\Models\Restauracion;
use App\Models\User;
use App\Notas\RegistroNotas;
use App\Notas\SegundasOportunidades;
use App\Notas\TableroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Restaurar un respaldo de periodo (3.1.10) como periodo nuevo, sin sobrescribir nada. */
class RestauracionTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 4);
        $this->actingAs($this->docente);

        $reg = app(RegistroNotas::class);
        $quiz = $this->crearActividad($this->trabajos, ['nombre' => 'Quiz 1']);
        $taller = $this->crearActividad($this->trabajos, ['nombre' => 'Taller', 'es_grupal' => true]);
        $parcial = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1']);
        $estudiantes = $this->dictado->estudiantes()->orderBy('documento')->get();
        foreach ($estudiantes as $i => $e) {
            $reg->asignar($quiz, $e, ['4.5', '3.0', '2.0', '5.0'][$i], 'digitada');
            $reg->asignar($taller, $e, ['4.0', '4.0', '3.5', null][$i], 'archivo', comentario: $i === 0 ? 'Muy buen informe' : null);
            $reg->asignar($parcial, $e, ['3.8', '2.5', '1.0', '4.2'][$i], 'calificador');
        }
        $reg->asignar($quiz, $estudiantes[1], '3.4', 'digitada', 'Reclamo: sumé mal');    // un cambio con motivo
        app(SegundasOportunidades::class)->registrar($estudiantes[2], ['ambito' => "actividad:{$parcial->id}", 'tipo' => 'supletorio', 'valor' => '3.6', 'regla' => 'reemplaza', 'motivo' => 'Incapacidad médica']);
        $this->grupo->dictado->update(['estado' => 'abierto']);
    }

    private function respaldo(): string
    {
        return $this->get(route('respaldo', $this->dictado))->streamedContent();
    }

    private function subir(string $json): TestResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'r');
        file_put_contents($ruta, $json);

        return $this->post(route('restauracion.revisar'), ['respaldo' => new UploadedFile($ruta, 'respaldo.json', 'application/json', null, true)]);
    }

    /** @return array<string, string> documento => nota final del periodo */
    private function finales(Grupo $g): array
    {
        return collect((new TableroNotas($g))->filasConsolidado())->mapWithKeys(fn ($f) => [$f['estudiante']->documento => (string) $f['acumulado']])->all();
    }

    #[Test]
    public function restaura_el_periodo_con_otro_nombre_y_las_notas_finales_salen_identicas(): void
    {
        $json = $this->respaldo();
        $this->assertSame(2, json_decode($json, true)['version']);
        $antes = $this->finales($this->grupo);

        $this->subir($json)->assertOk()
            ->assertSee('Estructura de Datos · 2026A')->assertSee('La materia ya tiene el periodo 2026A')->assertSee('value="2026A-R"', false)
            ->assertSee('Restaurar como periodo nuevo');

        // Con el nombre ocupado no se crea nada
        $sha = hash('sha256', $json);
        $this->post(route('restauracion.restaurar'), ['periodo' => '2026A', 'sha' => $sha])->assertSessionHasErrors('respaldo');
        $this->assertSame(1, Dictado::query()->count());

        $this->subir($json);
        $this->post(route('restauracion.restaurar'), ['periodo' => '2026A-R', 'sha' => $sha])->assertRedirect();
        $nuevo = Dictado::query()->where('periodo', '2026A-R')->sole();
        $this->assertSame($this->materia->id, $nuevo->materia_id, 'Se restaura en la misma materia');

        $grupo = $nuevo->grupos()->where('nombre', 'Diurno')->sole();
        $this->assertSame($antes, $this->finales($grupo), 'Mismas notas finales, con el supletorio aplicado');

        // Las notas conservan origen y comentario; la bitácora nueva dice que se restauraron
        $nota = $nuevo->divisiones()->first()->componentes()->first()->actividades()->where('nombre', 'Taller')->sole()->notas()->whereNotNull('comentario')->sole();
        $this->assertSame('Muy buen informe', $nota->comentario);
        $this->assertSame('archivo', $nota->origen);
        $this->assertTrue(NotaCambio::query()->where('nota_id', $nota->id)->where('origen', 'restauracion')->where('motivo', 'like', 'Restaurada del respaldo generado el %')->exists());

        // El original no se tocó y la bitácora original se puede consultar enlazada a sus actividades
        $this->assertSame($antes, $this->finales($this->grupo->fresh()));
        $this->get(route('dictados.show', $nuevo))->assertSee('Periodo restaurado')->assertSee('Ver la bitácora original');
        $this->get(route('restauracion.bitacora', $nuevo))->assertOk()->assertSee('Reclamo: sumé mal')->assertSee('Quiz 1');
        $this->assertCount(count(json_decode($json, true)['bitacora']), Restauracion::query()->sole()->bitacora_original);
        $this->assertNotNull(Restauracion::query()->sole()->bitacora_original[0]['actividad_restaurada'], 'Con la versión 2, cada cambio queda enlazado a su actividad');
        Storage::disk('local')->assertMissing("restauraciones/{$this->docente->id}/{$sha}.json");
    }

    #[Test]
    public function en_otra_instalacion_crea_la_materia_y_un_periodo_cerrado_vuelve_cerrado(): void
    {
        $this->dictado->update(['estado' => 'cerrado']);
        $json = $this->respaldo();
        $otro = User::factory()->create();
        $this->actingAs($otro);

        $this->subir($json)->assertOk()->assertSee('no existe en su cuenta: se creará')->assertSee('value="2026A"', false);
        $this->post(route('restauracion.restaurar'), ['periodo' => '2026A', 'sha' => hash('sha256', $json)])->assertRedirect();
        $materia = Materia::query()->where('user_id', $otro->id)->sole();
        $this->assertSame('910303', $materia->codigo);
        $nuevo = $materia->dictados()->sole();
        $this->assertSame('cerrado', $nuevo->estado);
        $this->assertSame($this->finales($this->grupo), $this->finales($nuevo->grupos()->sole()));
    }

    #[Test]
    public function acepta_respaldos_de_la_version_1_y_rechaza_archivos_dañados(): void
    {
        $r = json_decode($this->respaldo(), true);
        $r['version'] = 1;
        foreach ($r['divisiones'] as &$d) {
            foreach ($d['componentes'] as &$c) {
                foreach ($c['actividades'] as &$a) {
                    unset($a['clave'], $a['valor_max'], $a['es_grupal'], $a['examen']);
                }
            }
        }
        unset($d, $c, $a);
        $this->subir(json_encode($r))->assertOk()->assertSee('versión 1')->assertSee('Restaurar como periodo nuevo');

        $anterior = json_decode($this->respaldo(), true);
        $anterior['formato'] = 'nombre-anterior/respaldo-dictado';                // hecho antes del cambio de nombre de la plataforma
        $this->subir(json_encode($anterior))->assertOk()->assertSee('Restaurar como periodo nuevo');

        $this->subir('esto no es json')->assertSessionHasErrors(['respaldo' => 'El archivo no es JSON válido.']);
        $this->subir(json_encode(['formato' => 'otra-cosa']))->assertSee('no es un respaldo de periodo de esta plataforma');
        $malo = json_decode($this->respaldo(), true);
        $malo['divisiones'][0]['componentes'][0]['actividades'][0]['notas'][0]['valor'] = '9.0';
        $malo['estudiantes'][] = $malo['estudiantes'][0];
        $this->subir(json_encode($malo))->assertSee('fuera de la escala')->assertSee('documentos repetidos')->assertDontSee('Restaurar como periodo nuevo');
        $this->assertSame(1, Dictado::query()->count());
    }

    #[Test]
    public function nadie_confirma_el_archivo_de_otro_ni_ve_su_bitacora(): void
    {
        $json = $this->respaldo();
        $this->subir($json);
        $this->actingAs(User::factory()->create());
        $this->post(route('restauracion.restaurar'), ['periodo' => 'X', 'sha' => hash('sha256', $json)])->assertStatus(410);
        $this->assertSame(1, Dictado::query()->count());

        $this->actingAs($this->docente);
        $this->subir($json);
        $this->post(route('restauracion.restaurar'), ['periodo' => '2026A-R', 'sha' => hash('sha256', $json)]);
        $this->actingAs(User::factory()->create());
        $this->get(route('restauracion.bitacora', Dictado::query()->where('periodo', '2026A-R')->sole()))->assertForbidden();
    }
}
