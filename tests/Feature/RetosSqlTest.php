<?php

namespace Tests\Feature;

use App\Evaluacion\ImportadorBanco;
use App\Models\Actividad;
use App\Models\Artefacto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Retos de SQL con motor SQLite embebido en el navegador (4.6). */
class RetosSqlTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private const ESQUEMA = "CREATE TABLE clientes (id INTEGER PRIMARY KEY, nombre TEXT, ciudad TEXT);\nINSERT INTO clientes VALUES (1, 'Ana', 'Barrancabermeja'), (2, 'Beto', 'Cali');";

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
    }

    private function banco(array $reto = [], array $extra = []): array
    {
        return ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'BD-Q1', 'titulo' => 'Consultas básicas', 'valor' => '5',
            'items' => array_merge([
                array_replace(['tipo' => 'reto', 'lenguaje' => 'sql', 'peso' => '5', 'enunciado' => 'Liste los clientes de Barrancabermeja.',
                    'esquema' => self::ESQUEMA, 'plantilla_codigo' => "SELECT\n",
                    'casos_visibles' => [['entrada' => 'SELECT COUNT(*) FROM clientes', 'esperada' => '2', 'descripcion' => 'Clientes en total']],
                    'verificaciones' => [
                        ['id' => 'v1', 'nombre' => 'Devuelve los clientes de Barrancabermeja', 'fraccion' => '0.6', 'consulta' => "SELECT * FROM clientes WHERE ciudad = 'Barrancabermeja'"],
                        ['id' => 'v2', 'nombre' => 'Funciona con otros clientes', 'fraccion' => '0.4', 'reservada' => true,
                            'datos' => "INSERT INTO clientes VALUES (3, 'Carla', 'Barrancabermeja');", 'consulta' => "SELECT * FROM clientes WHERE ciudad = 'Barrancabermeja'"],
                    ]], $reto),
            ], $extra)];
    }

    private function actividad(): Actividad
    {
        $a = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->latest('id')->first()->id, 'nombre' => 'Quiz SQL', 'valor_max' => '5']);
        $a->grupos()->attach($this->grupo->id);

        return $a;
    }

    #[Test]
    public function el_esquema_viaja_al_examen_con_el_motor_y_las_consultas_de_referencia_no(): void
    {
        app(ImportadorBanco::class)->importar($this->materia, $this->banco());
        $reto = Artefacto::query()->sole()->items()->with('pregunta')->first()->pregunta;
        $this->assertSame('sql', $reto->enunciado['lenguaje']);
        $this->assertSame(self::ESQUEMA, $reto->enunciado['esquema']);
        $this->assertArrayNotHasKey('verificaciones', $reto->enunciado);

        $html = $this->get(route('calificador.examen', $this->actividad()))->assertOk()->getContent();
        $this->assertStringContainsString('id="motor-sql"', $html);
        $this->assertStringContainsString(base64_encode(substr(file_get_contents(public_path('vendor/sql.js/sql-wasm.wasm')), 0, 300)), $html);
        $this->assertStringContainsString('window.ejecutarSQL', $html);
        $this->assertStringNotContainsString("INSERT INTO clientes VALUES (3, 'Carla'", $html, 'Los datos de la verificación reservada no viajan');
        $this->assertStringNotContainsString('Funciona con otros clientes', $html);

        // Exportado y vuelto a importar conserva esquema, consultas y datos
        $exportado = app(ImportadorBanco::class)->aBanco(Artefacto::query()->sole()->items()->with('pregunta')->first());
        $this->assertSame(self::ESQUEMA, $exportado['esquema']);
        $this->assertSame("INSERT INTO clientes VALUES (3, 'Carla', 'Barrancabermeja');", $exportado['verificaciones'][1]['datos']);
    }

    #[Test]
    public function un_examen_sin_retos_de_sql_no_carga_con_el_motor(): void
    {
        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'BD-Q2', 'titulo' => 'Sin SQL', 'valor' => '5',
            'items' => [['tipo' => 'vf', 'peso' => '5', 'enunciado' => 'SQL es un lenguaje declarativo.', 'correcta' => true]]]);
        $html = $this->get(route('calificador.examen', $this->actividad()))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="motor-sql"', $html);
        $this->assertLessThan(300_000, strlen($html));
    }

    #[Test]
    public function valida_esquema_y_criterio_de_cada_verificacion(): void
    {
        $i = app(ImportadorBanco::class);
        $this->assertSame([], $i->validar($this->banco()));

        $sinEsquema = $i->validar($this->banco(['esquema' => ' ']));
        $this->assertTrue(collect($sinEsquema)->contains(fn ($e) => str_contains($e, 'necesita «esquema»')));

        $b = $this->banco();
        unset($b['items'][0]['verificaciones'][0]['consulta']);
        $this->assertTrue(collect($i->validar($b))->contains(fn ($e) => str_contains($e, 'una «consulta» de referencia o una «prueba»')));

        // Una prueba en lugar de consulta también vale
        $b['items'][0]['verificaciones'][0]['prueba'] = "SELECT COUNT(*) = 1 FROM clientes WHERE nombre = 'Dora'";
        $this->assertSame([], $i->validar($b));
    }

    #[Test]
    public function el_calificador_recibe_esquema_consultas_y_datos_para_ejecutarlos(): void
    {
        app(ImportadorBanco::class)->importar($this->materia, $this->banco());
        $actividad = $this->actividad();
        $ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $e = $this->crearEntrega($actividad, $ana);
        $item = Artefacto::query()->sole()->items()->first();
        $e->respuestas()->create(['artefacto_pregunta_id' => $item->id, 'respuesta' => ['valor' => "SELECT * FROM clientes WHERE ciudad = 'Barrancabermeja';"], 'puntaje_max' => '5']);

        $p = $this->getJson(route('calificador.retos', $actividad))->assertOk()->json('pendientes.0');
        $this->assertSame('sql', $p['lenguaje']);
        $this->assertSame(self::ESQUEMA, $p['esquema']);
        $this->assertSame("SELECT * FROM clientes WHERE ciudad = 'Barrancabermeja'", $p['verificaciones'][0]['consulta']);
        $this->assertFalse($p['verificaciones'][0]['orden']);
        $this->assertStringContainsString('Carla', $p['verificaciones'][1]['datos']);

        $this->postJson(route('calificador.entregas.resultados-navegador', $e), ['resultados' => [['item' => $item->id, 'verificaciones' => ['v1' => true, 'v2' => false],
            'casos' => [['entrada' => 'SELECT COUNT(*) FROM clientes', 'esperada' => '2', 'consola' => ['COUNT(*)', '2'], 'error' => null]]]]])->assertOk();
        $this->assertSame('3.0000', $e->respuestas()->first()->puntaje);
        $this->get(route('calificador.detalle', $e))->assertOk()->assertSee('Funciona con otros clientes')->assertSee('No superada');
    }

    #[Test]
    public function la_version_b_de_un_reto_de_sql_tambien_es_de_sql(): void
    {
        app(ImportadorBanco::class)->importar($this->materia, $this->banco());
        $items = $this->banco()['items'];
        $comoJs = $items;
        $comoJs[0]['lenguaje'] = 'javascript';
        foreach ($comoJs[0]['verificaciones'] as &$v) {
            $v['prueba'] = 'true';
        }
        $this->assertTrue(collect(app(ImportadorBanco::class)->validarVersion(Artefacto::query()->sole(), $comoJs, 'B'))->contains(fn ($e) => str_contains($e, 'mismos tipos')));
        $this->assertSame([], app(ImportadorBanco::class)->validarVersion(Artefacto::query()->sole(), $items, 'B'));
    }
}
