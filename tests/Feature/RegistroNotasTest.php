<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaCambio;
use App\Notas\RegistroNotas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Registro de notas con auditoría (3.5), deshacer por operación (3.1.9) y vacías ≠ cero (3.1.6). */
class RegistroNotasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private RegistroNotas $registro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario();
        $this->registro = app(RegistroNotas::class);
    }

    #[Test]
    public function cada_cambio_de_nota_queda_en_la_bitacora(): void
    {
        $actividad = $this->crearActividad();
        $e = $this->dictado->estudiantes()->first();

        $this->registro->asignar($actividad, $e, '3.5', 'digitada');
        $this->registro->asignar($actividad, $e, '4.0', 'digitada', 'Reclamo aceptado');

        $nota = Nota::query()->sole();
        $cambios = $nota->cambios;

        $this->assertSame('4.0000', $nota->valor);
        $this->assertCount(2, $cambios);
        $this->assertNull($cambios[0]->valor_anterior);
        $this->assertSame('3.5000', $cambios[1]->valor_anterior);
        $this->assertSame('Reclamo aceptado', $cambios[1]->motivo);
    }

    #[Test]
    public function asignar_el_mismo_valor_no_genera_entrada(): void
    {
        $actividad = $this->crearActividad();
        $e = $this->dictado->estudiantes()->first();

        $this->registro->asignar($actividad, $e, '3.5', 'digitada');
        $this->registro->asignar($actividad, $e, '3.50', 'digitada');

        $this->assertSame(1, NotaCambio::query()->count());
    }

    #[Test]
    public function la_bitacora_no_se_puede_editar_ni_borrar_ni_por_eloquent_ni_por_sql(): void
    {
        $actividad = $this->crearActividad();
        $this->registro->asignar($actividad, $this->dictado->estudiantes()->first(), '3', 'digitada');
        $cambio = NotaCambio::query()->sole();

        try {
            $cambio->update(['valor_nuevo' => '5']);
            $this->fail('Eloquent permitió editar la bitácora.');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class);
        DB::table('nota_cambios')->where('id', $cambio->id)->update(['valor_nuevo' => '5']);
    }

    #[Test]
    public function rechaza_notas_fuera_de_la_escala(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registro->asignar($this->crearActividad(), $this->dictado->estudiantes()->first(), '5.1', 'digitada');
    }

    #[Test]
    public function vacias_a_ceros_es_explicito_y_se_deshace_en_una_sola_operacion(): void
    {
        $actividad = $this->crearActividad();
        [$a, $b, $c, $d] = $this->dictado->estudiantes()->get()->all();
        $this->registro->asignar($actividad, $a, '4', 'digitada');

        $op = $this->registro->vaciasACeros($actividad, 'Cierre del corte 1');

        $this->assertSame(['4.0000', '0.0000', '0.0000', '0.0000'],
            $actividad->notas()->orderBy('estudiante_id')->pluck('valor')->all());

        $this->registro->deshacer($op);

        $this->assertSame(['4.0000', null, null, null],
            $actividad->notas()->orderBy('estudiante_id')->pluck('valor')->all());
        // Deshacer agrega entradas, no borra: 1 + 3 + 3
        $this->assertSame(7, NotaCambio::query()->count());
    }

    #[Test]
    public function eliminar_actividad_exige_confirmar_cuantas_notas_borra_y_conserva_la_bitacora(): void
    {
        $actividad = $this->crearActividad();
        foreach ($this->dictado->estudiantes as $e) {
            $this->registro->asignar($actividad, $e, '3', 'digitada');
        }

        try {
            $this->registro->eliminarActividad($actividad, 2, 'Error de creación');
            $this->fail('Se eliminó sin confirmar el número correcto de notas.');
        } catch (InvalidArgumentException $ex) {
            $this->assertStringContainsString('4 notas', $ex->getMessage());
        }

        $this->registro->eliminarActividad($actividad, 4, 'Error de creación');

        $this->assertSame(0, Nota::query()->count());
        $this->assertSame(4, NotaCambio::query()->where('origen', 'eliminacion')->count());
    }

    #[Test]
    public function el_mismo_documento_puede_estar_en_otro_dictado_y_en_dos_grupos(): void
    {
        $e = $this->dictado->estudiantes()->first();
        $nocturno = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);
        $nocturno->estudiantes()->attach($e->id);

        $this->assertSame(2, $e->grupos()->count());

        $otro = $this->materia->dictados()->create(['periodo' => '2026B']);
        $otro->estudiantes()->create(['documento' => $e->documento, 'apellidos' => 'X', 'nombres' => 'Y']);

        $this->expectException(QueryException::class);
        $this->dictado->estudiantes()->create(['documento' => $e->documento, 'apellidos' => 'X', 'nombres' => 'Y']);
    }
}
