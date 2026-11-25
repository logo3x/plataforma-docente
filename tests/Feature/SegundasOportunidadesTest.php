<?php

namespace Tests\Feature;

use App\Models\Componente;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\NotaCambio;
use App\Models\SegundaOportunidad;
use App\Models\User;
use App\Notas\CierrePeriodo;
use App\Notas\EstadoCorte;
use App\Notas\RegistroNotas;
use App\Notas\ResultadoDivision;
use App\Notas\TableroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Supletorios, recuperaciones y habilitaciones (3.6) con simulación previa (3.7.4). */
class SegundasOportunidadesTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Estudiante $ana;

    private Estudiante $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->ana = $this->crearEstudiante('1', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('2', 'BELTRAN', 'BETO');
        $this->actingAs($this->docente);
    }

    private function nota($actividad, Estudiante $e, ?string $v): void
    {
        app(RegistroNotas::class)->asignar($actividad, $e, $v, 'digitada');
    }

    private function registrar(Estudiante $e, array $d)
    {
        return $this->post(route('segundas.store', [$this->grupo, $e]), $d + ['fecha' => now()->toDateString(), 'regla' => 'reemplaza']);
    }

    private function corte1(Estudiante $e): ResultadoDivision
    {
        $t = new TableroNotas($this->grupo);

        return $t->resultado($t->division($this->cortes[0]->id), $e);
    }

    #[Test]
    public function el_supletorio_completa_el_corte_sin_tocar_la_nota_original(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1']);
        $this->nota($quiz, $this->ana, '4');
        $this->nota($parcial, $this->beto, '3');                // el parcial ya se aplicó
        $this->assertSame(EstadoCorte::Incompleto, $this->corte1($this->ana)->estado);

        $this->registrar($this->ana, ['ambito' => "actividad:{$parcial->id}", 'tipo' => 'supletorio', 'valor' => '3', 'motivo' => 'Incapacidad médica'])
            ->assertRedirect()->assertSessionHas('estado');

        $r = $this->corte1($this->ana);
        $this->assertSame(['3.5', EstadoCorte::Aprobado], [$r->definitiva, $r->estado]);
        $this->assertNull(Nota::query()->where(['actividad_id' => $parcial->id, 'estudiante_id' => $this->ana->id])->value('valor'), 'La nota original se conserva.');
        $c = NotaCambio::query()->where('origen', 'supletorio')->sole();
        $this->assertStringContainsString('La nota original se conserva. Incapacidad médica', $c->motivo);

        // La revisión de ceros ya no la cuenta como vacía
        $vacias = collect((new CierrePeriodo($this->grupo))->ceros())->where('tipo', 'vacia')->pluck('estudiante.id');
        $this->assertNotContains($this->ana->id, $vacias);

        $this->get(route('registro', $this->grupo))->assertSee('S 3.0');
    }

    #[Test]
    public function la_recuperacion_puede_promediar_con_la_nota_original(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial);
        $this->nota($quiz, $this->ana, '2');
        $this->nota($parcial, $this->ana, '4');

        $this->registrar($this->ana, ['ambito' => "actividad:{$quiz->id}", 'tipo' => 'recuperacion', 'valor' => '4', 'regla' => 'promedia']);

        // quiz vigente (2 + 4) / 2 = 3 → corte (3 + 4) / 2 = 3.5
        $this->assertSame('3.5', $this->corte1($this->ana)->definitiva);
        $this->assertSame('2.0000', Nota::query()->where(['actividad_id' => $quiz->id, 'estudiante_id' => $this->ana->id])->value('valor'));
    }

    #[Test]
    public function la_habilitacion_del_corte_con_tope_no_supera_la_aprobatoria(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial);
        $this->nota($quiz, $this->ana, '2');
        $this->nota($parcial, $this->ana, '2');
        $this->assertSame(EstadoCorte::Reprobado, $this->corte1($this->ana)->estado);

        $this->registrar($this->ana, ['ambito' => "division:{$this->cortes[0]->id}", 'tipo' => 'habilitacion', 'valor' => '4.5', 'regla' => 'tope']);

        $r = $this->corte1($this->ana);
        $this->assertSame(['3', '2', EstadoCorte::Aprobado], [$r->definitiva, $r->original, $r->estado]);
        $this->assertSame('3.0000', SegundaOportunidad::query()->sole()->tope, 'El tope por defecto es la nota aprobatoria.');
        $this->get(route('registro', $this->grupo))->assertSee('H · antes 2.0');
    }

    #[Test]
    public function la_habilitacion_de_un_corte_incompleto_no_se_aplica_todavia(): void
    {
        $this->crearActividad($this->trabajos);
        $this->crearActividad($this->parcial);
        $this->registrar($this->ana, ['ambito' => "division:{$this->cortes[0]->id}", 'tipo' => 'habilitacion', 'valor' => '4']);

        $this->assertSame(EstadoCorte::Incompleto, $this->corte1($this->ana)->estado);
    }

    #[Test]
    public function la_habilitacion_de_la_materia_aplica_sobre_la_nota_final(): void
    {
        // Un componente único por corte para cerrar el periodo: 2.0 · 2.0 · 3.0 → 0.6 + 0.6 + 1.2 = 2.4
        foreach ($this->cortes as $i => $corte) {
            $comp = $i === 0 ? $this->trabajos : Componente::query()->create(['division_id' => $corte->id, 'nombre' => 'Todo', 'peso' => '1']);
            if ($i === 0) {
                $this->parcial->delete();
                $this->trabajos->update(['peso' => '1']);
            }
            $this->nota($this->crearActividad($comp), $this->ana, ['2', '2', '3'][$i]);
        }
        $final = fn () => collect((new TableroNotas($this->grupo))->filasConsolidado())->firstWhere('estudiante.id', $this->ana->id);
        $this->assertSame(['2.4', EstadoCorte::Reprobado], [$final()['acumulado'], $final()['estado']]);

        $this->registrar($this->ana, ['ambito' => 'dictado', 'tipo' => 'habilitacion', 'valor' => '3.2', 'motivo' => 'Acta 12 de 2026']);

        $f = $final();
        $this->assertSame(['3.2', '2.4', EstadoCorte::Aprobado], [$f['acumulado'], $f['original'], $f['estado']]);
        $this->get(route('registro', [$this->grupo, 'vista' => 'consolidado']))->assertSee('H · antes 2.4');
    }

    #[Test]
    public function simular_muestra_el_efecto_sin_registrar_nada(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $parcial = $this->crearActividad($this->parcial);
        $this->nota($quiz, $this->ana, '2');
        $this->nota($parcial, $this->ana, '2');

        $r = $this->post(route('segundas.simular', [$this->grupo, $this->ana]), [
            'ambito' => "actividad:{$parcial->id}", 'tipo' => 'recuperacion', 'valor' => '4', 'regla' => 'reemplaza', 'fecha' => now()->toDateString(),
        ])->assertOk()->assertSee('así quedaría');

        $sim = $r->viewData('simulacion');
        $this->assertSame(['Definitiva de Corte 1', '2', '3'], [$sim[0]['que'], $sim[0]['antes'], $sim[0]['despues']]);
        $this->assertSame('✓ Aprobado', $sim[0]['estado_despues']);
        $this->assertSame(0, SegundaOportunidad::query()->count());
        $this->assertSame(2, NotaCambio::query()->count(), 'Simular no escribe en la bitácora.');
    }

    #[Test]
    public function valida_el_tipo_segun_el_ambito_y_la_escala(): void
    {
        $quiz = $this->crearActividad($this->trabajos);

        $this->registrar($this->ana, ['ambito' => "actividad:{$quiz->id}", 'tipo' => 'habilitacion', 'valor' => '3'])
            ->assertSessionHasErrors(['valor' => 'Sobre una actividad se registra un supletorio o una recuperación.']);
        $this->registrar($this->ana, ['ambito' => 'dictado', 'tipo' => 'supletorio', 'valor' => '3'])
            ->assertSessionHasErrors(['valor' => 'Sobre un corte o la materia se registra una habilitación.']);
        $this->registrar($this->ana, ['ambito' => "actividad:{$quiz->id}", 'tipo' => 'supletorio', 'valor' => '6'])
            ->assertSessionHasErrors('valor');
        $this->assertSame(0, SegundaOportunidad::query()->count());
    }

    #[Test]
    public function no_se_edita_ni_se_borra_se_anula_con_motivo(): void
    {
        $parcial = $this->crearActividad($this->parcial);
        $quiz = $this->crearActividad($this->trabajos);
        $this->nota($quiz, $this->ana, '4');
        $this->registrar($this->ana, ['ambito' => "actividad:{$parcial->id}", 'tipo' => 'supletorio', 'valor' => '3']);
        $s = SegundaOportunidad::query()->sole();

        try {
            $s->update(['valor' => '5']);
            $this->fail('Se pudo editar una segunda oportunidad.');
        } catch (LogicException) {
        }
        try {
            $s->delete();
            $this->fail('Se pudo borrar una segunda oportunidad.');
        } catch (LogicException) {
        }

        $this->post(route('segundas.anular', [$this->grupo, $s]), ['motivo_anulacion' => 'Se registró al estudiante equivocado'])->assertRedirect();

        $this->assertNotNull($s->fresh()->anulada_en);
        $this->assertSame(EstadoCorte::Incompleto, $this->corte1($this->ana)->estado, 'Sin el supletorio, el corte vuelve a quedar incompleto.');
        $this->assertSame('anula_supletorio', NotaCambio::query()->latest('id')->value('origen'));
        $this->get(route('segundas.create', [$this->grupo, $this->ana]))->assertSee('Anulado el')->assertSee('Se registró al estudiante equivocado');
        $this->post(route('segundas.anular', [$this->grupo, $s]), ['motivo_anulacion' => 'otra vez'])->assertStatus(409);
    }

    #[Test]
    public function aparece_en_la_lista_del_periodo_y_en_el_respaldo(): void
    {
        $parcial = $this->crearActividad($this->parcial, ['nombre' => 'Parcial 1']);
        $this->registrar($this->ana, ['ambito' => "actividad:{$parcial->id}", 'tipo' => 'supletorio', 'valor' => '3.5', 'motivo' => 'Calamidad']);

        $this->get(route('segundas.index', $this->dictado))->assertOk()->assertSee('ARIAS, ANA')->assertSee('Supletorio')->assertSee('Calamidad');
        $json = json_decode($this->get(route('respaldo', $this->dictado))->streamedContent(), true);
        $this->assertSame(['1', 'supletorio', 'actividad: Parcial 1', '3.5000'], array_values(array_intersect_key(
            $json['segundas_oportunidades'][0], array_flip(['documento', 'tipo', 'sobre', 'valor']))));
    }

    #[Test]
    public function solo_el_docente_dueno_y_solo_estudiantes_del_grupo(): void
    {
        $quiz = $this->crearActividad($this->trabajos);
        $otro = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);
        $ajeno = $this->dictado->estudiantes()->create(['documento' => '9', 'apellidos' => 'NOCHE', 'nombres' => 'X']);
        $otro->estudiantes()->attach($ajeno->id);

        $this->get(route('segundas.create', [$this->grupo, $ajeno]))->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get(route('segundas.create', [$this->grupo, $this->ana]))->assertForbidden();
        $this->registrar($this->ana, ['ambito' => "actividad:{$quiz->id}", 'tipo' => 'supletorio', 'valor' => '3'])->assertForbidden();
        $this->get(route('segundas.index', $this->dictado))->assertForbidden();
    }
}
