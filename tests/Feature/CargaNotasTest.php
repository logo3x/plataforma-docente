<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Nota;
use App\Models\NotaCambio;
use App\Models\User;
use App\Notas\ImportadorNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Carga de notas desde archivo que crea una columna (3.1.5). */
class CargaNotasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Estudiante $ana;

    private Estudiante $juan;

    private Estudiante $pedro;

    private Grupo $nocturno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->ana = $this->crearEstudiante('1001', 'GÓMEZ PÉREZ', 'ANA MARÍA');
        $this->juan = $this->crearEstudiante('1002', 'GÓMEZ RUIZ', 'JUAN');      // mismo primer apellido
        $this->pedro = $this->crearEstudiante('1003', 'SOLANO', 'PEDRO');
        $this->nocturno = $this->dictado->grupos()->create(['nombre' => 'Nocturno']);
        $luis = $this->dictado->estudiantes()->create(['documento' => '2001', 'apellidos' => 'MORA', 'nombres' => 'LUIS']);
        $this->nocturno->estudiantes()->attach($luis->id);
        $this->actingAs($this->docente);
    }

    private function archivo(string $contenido, string $nombre = 'Parcial 2.csv'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, 'text/csv', null, true);
    }

    /** Sube el archivo y devuelve la respuesta de la vista previa. */
    private function subir(string $contenido, array $consulta = []): TestResponse
    {
        $r = $this->post(route('carga-notas.subir', $this->grupo), [
            'archivo' => $this->archivo($contenido),
            'componente_id' => $this->parcial->id,
        ]);
        $r->assertRedirect();
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);

        return $this->get(route('carga-notas.previa', [$this->grupo, 'token' => $q['token'], ...$consulta]));
    }

    private function filas($r): array
    {
        return collect($r->viewData('analisis')['filas'])->keyBy('numero')->all();
    }

    #[Test]
    public function cruza_por_documento_y_por_nombre_completo_como_respaldo(): void
    {
        $r = $this->subir("Cédula;Estudiante;Calificación\n1001;cualquier cosa;4,5\n;Pedro Solano;3.2\n9999;GOMEZ RUIZ JUAN;2.8\n");

        $r->assertOk();
        $f = $this->filas($r);
        $this->assertSame(['documento', 'nombre', 'nombre'], [$f[2]['cruce'], $f[3]['cruce'], $f[4]['cruce']]);
        $this->assertStringContainsString('cruzó por nombre completo', $f[4]['detalle']);
        $this->assertSame(['4.5', '3.2', '2.8'], [$f[2]['valor'], $f[3]['valor'], $f[4]['valor']]);
        $this->assertSame(3, $r->viewData('analisis')['resumen']['importables']);
        $this->assertSame(0, Actividad::query()->count(), 'La vista previa no escribe nada.');
    }

    #[Test]
    public function nunca_cruza_por_apellido_suelto(): void
    {
        // Un archivo que solo trae apellidos no se puede cruzar: se pide documento o nombre completo
        $r = $this->subir("apellidos,nota\nGOMEZ,5\n");
        $this->assertNull($r->viewData('analisis'));
        $r->assertSee('Indique la columna del documento o la del nombre completo');

        // «GÓMEZ» coincide con Ana y con Juan; «GÓMEZ PÉREZ» es solo el apellido de Ana: ninguno cruza
        $f = $this->filas($this->subir("nombre,nota\nGOMEZ,5\nGÓMEZ PÉREZ,4\n"));
        $this->assertSame(['no_cruzo', 'no_cruzo'], [$f[2]['estado'], $f[3]['estado']]);
    }

    #[Test]
    public function nombre_completo_ambiguo_no_se_carga(): void
    {
        $this->crearEstudiante('1004', 'SOLANO', 'PEDRO');   // homónimo exacto

        $f = $this->filas($this->subir("nombre,nota\nSOLANO PEDRO,4\n"));

        $this->assertSame('ambiguo', $f[2]['estado']);
    }

    #[Test]
    public function respeta_la_columna_de_grupo_y_detecta_estudiantes_de_otro_grupo(): void
    {
        $r = $this->subir("documento,nota,grupo\n1001,4,Diurno\n1002,4,Nocturno\n2001,4,\n");

        $f = $this->filas($r);
        $this->assertSame('ok', $f[2]['estado']);
        $this->assertSame('otro_grupo', $f[3]['estado']);
        $this->assertSame('otro_grupo', $f[4]['estado']);
        $this->assertStringContainsString('Nocturno', $f[4]['detalle']);
    }

    #[Test]
    public function detecta_escala_0_a_100_y_convierte(): void
    {
        $r = $this->subir("documento,puntaje\n1001,80\n1002,45\n1003,100\n");

        $this->assertSame('100', $r->viewData('escalaOrigen'));
        $f = $this->filas($r);
        $this->assertSame(['4', '2.25', '5'], [$f[2]['valor'], $f[3]['valor'], $f[4]['valor']]);
        $r->assertSee('se convierten de 0–100');
    }

    #[Test]
    public function el_docente_puede_corregir_la_escala_y_las_columnas(): void
    {
        $r = $this->subir("id,x,y\n1001,8,3\n", ['col_documento' => '0', 'col_nota' => '1', 'escala' => '10']);

        $this->assertSame('4', $this->filas($r)[2]['valor']);
    }

    #[Test]
    public function reporta_repetidas_fuera_de_rango_no_numericas_y_vacias(): void
    {
        $r = $this->subir("documento,nota\n1001,4\n1001,2\n1002,7\n1003,abc\n1003,\n");

        $f = $this->filas($r);
        $this->assertSame(['ok', 'repetida', 'fuera_de_rango', 'no_numerica', 'sin_nota'],
            [$f[2]['estado'], $f[3]['estado'], $f[4]['estado'], $f[5]['estado'], $f[6]['estado']]);
        $this->assertSame('5', $r->viewData('escalaOrigen'), 'Con notas mayoritariamente en 0–5, un 7 no cambia la escala sugerida');
    }

    #[Test]
    public function confirmar_crea_la_columna_con_bitacora_y_se_puede_deshacer(): void
    {
        $r = $this->subir("documento,nota\n1001,4.5\n1002,3\n");
        $token = $r->viewData('token');

        $this->post(route('carga-notas.confirmar', $this->grupo), [
            'token' => $token, 'nombre' => 'Parcial 2', 'col_documento' => '0', 'col_nota' => '1', 'escala' => '5',
        ])->assertRedirect(route('registro', [$this->grupo, 'vista' => $this->cortes[0]->id]))
            ->assertSessionHas('estado');

        $actividad = Actividad::query()->sole();
        $this->assertSame('Parcial 2', $actividad->nombre);
        $this->assertSame($this->parcial->id, $actividad->componente_id);
        $this->assertTrue($actividad->grupos()->whereKey($this->grupo->id)->exists());
        $this->assertSame(['4.5000', '3.0000'], Nota::query()->orderBy('estudiante_id')->pluck('valor')->all());
        $this->assertSame(['archivo', 'archivo'], Nota::query()->pluck('origen')->all());
        $this->assertStringContainsString('Importada de «Parcial 2.csv», fila 2 (cruce por documento)',
            NotaCambio::query()->orderBy('id')->first()->motivo);

        // El token se consume
        $this->post(route('carga-notas.confirmar', $this->grupo), ['token' => $token, 'nombre' => 'X'])
            ->assertRedirect(route('carga-notas.create', $this->grupo));

        // Aparece en la pila de deshacer del registro
        $pila = session("deshacer.grupo.{$this->grupo->id}");
        $this->assertStringContainsString('Carga de «Parcial 2» desde archivo (2 notas)', end($pila)['descripcion']);
    }

    #[Test]
    public function la_columna_cargada_se_ve_en_el_registro(): void
    {
        $token = $this->subir("documento,nota\n1001,4.5\n")->viewData('token');
        $this->post(route('carga-notas.confirmar', $this->grupo), ['token' => $token, 'nombre' => 'Parcial 2']);

        $this->get(route('registro', [$this->grupo, 'vista' => $this->cortes[0]->id]))
            ->assertSee('Parcial 2')->assertSee('4.5')->assertSee('Deshacer: Carga de');
    }

    #[Test]
    public function rechaza_componente_de_otro_dictado_y_otro_docente(): void
    {
        $otra = $this->docente->materias()->create(['nombre' => 'Otra']);
        $comp = $otra->dictados()->create(['periodo' => '2026A'])->divisiones()->create(['nombre' => 'X', 'peso' => '1'])
            ->componentes()->create(['nombre' => 'Y', 'peso' => '1']);

        $this->post(route('carga-notas.subir', $this->grupo), [
            'archivo' => $this->archivo("documento,nota\n1001,4\n"), 'componente_id' => $comp->id,
        ])->assertForbidden();

        $this->actingAs(User::factory()->create())->get(route('carga-notas.create', $this->grupo))->assertForbidden();
    }

    #[Test]
    public function detecta_columnas_por_sinonimos(): void
    {
        $i = app(ImportadorNotas::class);

        $this->assertSame(
            ['documento' => 1, 'nombre' => 0, 'apellidos' => null, 'nombres' => null, 'nota' => 2, 'grupo' => 3],
            $i->detectarColumnas(['Nombre del alumno', 'Número de documento', 'Calificación', 'Jornada'])
        );
    }

    #[Test]
    public function con_mayoria_en_0_a_10_sugiere_esa_escala(): void
    {
        $r = $this->subir("documento,nota\n1001,8\n1002,6\n1003,4\n");

        $this->assertSame('10', $r->viewData('escalaOrigen'));
        $this->assertSame('2', $this->filas($r)[4]['valor']);
    }
}
