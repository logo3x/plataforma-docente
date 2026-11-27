<?php

namespace Tests\Feature;

use App\Calificacion\RevisionEstatica;
use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\PruebasMinimas;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Entrega;
use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Retos de PHP y Laravel con revisión estática, sin ejecución (4.6). */
class RetosPhpTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private const RUTAS = 'routes/web.php';

    private const CONTROLADOR = 'app/Http/Controllers/ProductoController.php';

    private const VISTA = 'resources/views/productos/index.blade.php';

    private Actividad $actividad;

    private Estudiante $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        app(ImportadorBanco::class)->importar($this->materia, $this->banco());
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Laravel: listado', 'valor_max' => '5']);
        $this->actividad->grupos()->attach($this->grupo->id);
    }

    private function banco(array $reto = []): array
    {
        return ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'DW2-PROD', 'titulo' => 'Listado de productos', 'valor' => '5',
            'items' => [array_replace([
                'tipo' => 'reto', 'lenguaje' => 'php', 'peso' => '5', 'enunciado' => 'Liste los productos en /productos.',
                'archivos' => [['nombre' => self::RUTAS, 'plantilla' => "<?php\n"], ['nombre' => self::CONTROLADOR, 'plantilla' => "<?php\n"], ['nombre' => self::VISTA, 'plantilla' => '']],
                'alcance' => 'No se comprobó que la página cargue.',
                'verificaciones' => [
                    ['id' => 'v1', 'nombre' => 'La ruta GET /productos llama a ProductoController@index', 'fraccion' => '0.4', 'archivo' => self::RUTAS,
                        'patron' => "Route::get\\(\\s*['\"]/productos['\"]\\s*,\\s*\\[\\s*ProductoController::class\\s*,\\s*['\"]index['\"]"],
                    ['id' => 'v2', 'nombre' => 'El controlador usa la vista productos.index', 'fraccion' => '0.2', 'archivo' => self::CONTROLADOR, 'contiene' => "view('productos.index'"],
                    ['id' => 'v3', 'nombre' => 'No usa consultas SQL a mano', 'fraccion' => '0.2', 'patron' => 'DB::(select|statement)', 'ausente' => true],
                    ['id' => 'v4', 'nombre' => 'Los archivos no tienen errores de sintaxis', 'fraccion' => '0.2', 'sintaxis' => true, 'reservada' => true],
                ]], $reto)]];
    }

    private function entregar(array $archivos): Entrega
    {
        $item = Artefacto::query()->sole()->items()->first();
        $carga = ['sigla' => 'DW2-PROD', 'artefacto' => $this->actividad->artefacto->uuid, 'documento' => $this->ana->documento, 'nombre' => $this->ana->nombreCompleto(),
            'integrantes' => [], 'inicio' => now()->subMinutes(20)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual',
            'respuestas' => [$item->id => $archivos], 'sesion' => ['salidas' => [], 'pegados' => 0]];
        $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'DW2-PROD', 'artefacto' => $this->actividad->artefacto->uuid,
            'documento' => $this->ana->documento, 'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))];
        $ruta = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($ruta, json_encode($sobre));
        $this->post(route('calificador.subir', $this->actividad), ['entregas' => [new UploadedFile($ruta, 'e.json', 'application/json', null, true)]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] === 'procesada');

        return Entrega::query()->latest('id')->first();
    }

    private function solucion(): array
    {
        return [
            self::RUTAS => "<?php\n\nuse App\\Http\\Controllers\\ProductoController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/productos', [ProductoController::class, 'index'])->name('productos.index');\n",
            self::CONTROLADOR => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\Producto;\n\nclass ProductoController extends Controller\n{\n    public function index()\n    {\n        return view(\n            'productos.index', ['productos' => Producto::orderBy('nombre')->get()]);\n    }\n}\n",
            self::VISTA => "<ul>\n@foreach (\$productos as \$p)\n    <li>{{ \$p->nombre }}</li>\n@endforeach\n</ul>\n",
        ];
    }

    #[Test]
    public function la_solucion_modelo_de_php_se_revisa_en_las_pruebas_minimas(): void
    {
        $reto = Artefacto::query()->sole()->items()->with('pregunta')->first()->pregunta;
        $buena = [self::RUTAS => "<?php\nRoute::get('/productos', [ProductoController::class, 'index']);\n",
            self::CONTROLADOR => "<?php\nclass ProductoController { public function index() { return view('productos.index'); } }\n", self::VISTA => '<h1>Productos</h1>'];
        $reto->update(['clave' => ['solucion' => $buena] + $reto->clave]);
        $p8 = collect(app(PruebasMinimas::class)->ejecutar($this->actividad->fresh()))->firstWhere('numero', 8);
        $this->assertSame(['ok', '1 reto(s) de PHP revisados con su solución modelo.'], [$p8['estado'], $p8['detalle']]);

        $mala = [self::CONTROLADOR => "<?php\nclass ProductoController { public function index() { return view('otra'); } }\n"] + $buena;
        $reto->update(['clave' => ['solucion' => $mala] + $reto->clave]);
        $p8 = collect(app(PruebasMinimas::class)->ejecutar($this->actividad->fresh()))->firstWhere('numero', 8);
        $this->assertSame('falla', $p8['estado']);
        $this->assertStringContainsString('ítem 1: la solución modelo no supera «El controlador usa la vista productos.index»', $p8['detalle']);
    }

    #[Test]
    public function la_solucion_de_php_debe_traer_el_codigo_de_cada_archivo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('«solucion» debe ser un objeto con el código de cada archivo');
        app(ImportadorBanco::class)->importar($this->materia, ['sigla' => 'DW2-OTRO'] + $this->banco(['solucion' => 'texto suelto']));
    }

    #[Test]
    public function el_examen_lista_los_criterios_visibles_y_el_alcance_pero_no_los_patrones(): void
    {
        $reto = Artefacto::query()->sole()->items()->with('pregunta')->first()->pregunta;
        $this->assertSame(['La ruta GET /productos llama a ProductoController@index', 'El controlador usa la vista productos.index', 'No usa consultas SQL a mano'],
            $reto->enunciado['criterios_visibles']);
        $this->assertSame(1, $reto->enunciado['reservadas']);

        $html = $this->get(route('calificador.examen', $this->actividad))->assertOk()->getContent();
        $this->assertStringContainsString('"lenguaje":"php"', $html);
        $this->assertStringContainsString('No se comprobó que la página cargue.', $html);
        $this->assertStringContainsString('resources/views/productos/index.blade.php', $html);
        $this->assertStringNotContainsString('DB::(select', $html);
        $this->assertStringNotContainsString('Los archivos no tienen errores de sintaxis', $html, 'El criterio reservado no se nombra');
        $this->assertStringNotContainsString('id="motor-sql"', $html);
    }

    #[Test]
    public function el_servidor_revisa_la_entrega_sin_ejecutarla_y_la_nota_sale_de_inmediato(): void
    {
        $e = $this->entregar($this->solucion());
        $r = $e->respuestas()->first();
        $this->assertSame('5.0000', $r->puntaje);
        $this->assertSame('servidor', $r->calificado_por);
        $this->assertTrue($r->desglose['estatica']);
        $this->assertSame([], $this->getJson(route('calificador.retos', $this->actividad))->json('pendientes'), 'No queda nada para el navegador');

        $detalle = $this->get(route('calificador.detalle', $e))->assertOk();
        $detalle->assertSee('Revisión estática, sin ejecutar el código.')->assertSee('No se comprobó que la página cargue.')
            ->assertSee('Criterio revisado')->assertSee('✓ Cumple')->assertSee('// ===== routes/web.php =====');
    }

    #[Test]
    public function los_comentarios_no_cumplen_y_la_sintaxis_se_explica(): void
    {
        $s = $this->solucion();
        $s[self::RUTAS] = "<?php\n// Route::get('/productos', [ProductoController::class, 'index']);\n";
        $s[self::CONTROLADOR] = str_replace("->get()]);\n", "->get()])\n", $s[self::CONTROLADOR]).'DB::select("select * from productos");';
        $s[self::VISTA] = "@foreach (\$productos as \$p)\n<li>{{ \$p->nombre }}</li>\n";
        $r = $this->entregar($s)->respuestas()->first();

        $d = collect($r->desglose['verificaciones'])->keyBy('nombre');
        $this->assertFalse($d['La ruta GET /productos llama a ProductoController@index']['superada'], 'Escrito en un comentario no cuenta');
        $this->assertTrue($d['El controlador usa la vista productos.index']['superada']);
        $this->assertFalse($d['No usa consultas SQL a mano']['superada']);
        $this->assertFalse($d['Los archivos no tienen errores de sintaxis']['superada']);
        $this->assertStringContainsString('app/Http/Controllers/ProductoController.php, línea', $d['Los archivos no tienen errores de sintaxis']['nota']);
        $this->assertSame('1.0000', $r->puntaje);
    }

    #[Test]
    public function una_vista_blade_con_una_directiva_sin_cerrar_no_pasa_la_sintaxis(): void
    {
        $rev = new RevisionEstatica;
        $this->assertNull($rev->errorDeSintaxis('v.blade.php', "@if (\$x)\n<p>{{ \$x }}</p>\n@endif"));
        $this->assertStringContainsString('la vista no compila', $rev->errorDeSintaxis('v.blade.php', "@if (\$x)\n<p>{{ \$x }}</p>\n"));
        $this->assertStringContainsString('se abrió «{» en la línea 2', $rev->errorDeSintaxis('c.php', "<?php\nfunction f() {\n  return 1;\n"));
    }

    #[Test]
    public function valida_criterios_archivos_y_patrones(): void
    {
        $i = app(ImportadorBanco::class);
        $b = $this->banco();
        $b['items'][0]['verificaciones'][0]['contiene'] = 'Route::get';
        $this->assertTrue(collect($i->validar($b))->contains(fn ($e) => str_contains($e, 'un solo criterio')));

        $b = $this->banco();
        $b['items'][0]['verificaciones'][0]['patron'] = 'Route::get(';
        $this->assertTrue(collect($i->validar($b))->contains(fn ($e) => str_contains($e, 'no es una expresión regular válida')));

        $b = $this->banco();
        $b['items'][0]['verificaciones'][1]['archivo'] = 'app/Models/Producto.php';
        $this->assertTrue(collect($i->validar($b))->contains(fn ($e) => str_contains($e, 'que el reto no tiene')));

        $this->assertTrue(collect($i->validar($this->banco(['archivos' => [['nombre' => 'a.php'], ['nombre' => 'a.php']]])))
            ->contains(fn ($e) => str_contains($e, '«archivos» admite hasta 6')));

        // Exportado conserva archivos y alcance
        $exp = $i->aBanco(Artefacto::query()->sole()->items()->with('pregunta')->first());
        $this->assertSame(self::VISTA, $exp['archivos'][2]['nombre']);
        $this->assertSame('No se comprobó que la página cargue.', $exp['alcance']);
    }
}
