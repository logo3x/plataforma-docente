<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
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

/** Retos de HTML y CSS con vista previa aislada (4.6). */
class RetosHtmlTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

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
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Taller de maquetación', 'valor_max' => '5']);
        $this->actividad->grupos()->attach($this->grupo->id);
    }

    private function banco(array $cambios = []): array
    {
        return ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'DW-MENU', 'titulo' => 'Menú de navegación', 'valor' => '5',
            'items' => [
                ['tipo' => 'vf', 'peso' => '1', 'enunciado' => '<nav> agrupa enlaces de navegación.', 'correcta' => true],
                array_replace(['tipo' => 'reto', 'lenguaje' => 'html', 'peso' => '4', 'enunciado' => 'Maquete el menú con tres enlaces en rojo.',
                    'plantilla_codigo' => "<!DOCTYPE html>\n<html>\n<body>\n<nav>\n</nav>\n</body>\n</html>\n",
                    'casos_visibles' => [['entrada' => "document.querySelectorAll('nav a').length", 'esperada' => '3', 'descripcion' => 'Enlaces en el menú']],
                    'verificaciones' => [
                        ['id' => 'v1', 'nombre' => 'El menú tiene tres enlaces', 'fraccion' => '0.5', 'prueba' => "document.querySelectorAll('nav a').length === 3"],
                        ['id' => 'v2', 'nombre' => 'Los enlaces son rojos', 'fraccion' => '0.5', 'reservada' => true,
                            'prueba' => "getComputedStyle(document.querySelector('nav a')).color === 'rgb(255, 0, 0)'"],
                    ]], $cambios),
            ]];
    }

    #[Test]
    public function el_reto_guarda_su_lenguaje_y_el_examen_lleva_el_entorno_sin_las_pruebas(): void
    {
        $reto = Artefacto::query()->sole()->items()->with('pregunta')->get()->firstWhere('pregunta.tipo', 'reto')->pregunta;
        $this->assertSame('html', $reto->enunciado['lenguaje']);
        $this->assertSame(1, $reto->enunciado['reservadas']);

        $html = $this->get(route('calificador.examen', $this->actividad))->assertOk()->getContent();
        $this->assertStringContainsString('"lenguaje":"html"', $html);
        $this->assertStringContainsString('window.ejecutarHTML = ejecutarHTML', $html);
        $this->assertStringNotContainsString("querySelector('nav a')).color", $html, 'La verificación reservada no viaja en el examen');
        // Una sola etiqueta de cierre por bloque de script: el entorno incrustado no corta el examen (4.5.5)
        $this->assertSame(substr_count($html, '<script'), substr_count(strtolower($html), '</script>'));

        // El banco exportado conserva el lenguaje
        $ap = Artefacto::query()->sole()->items()->with('pregunta')->get()->firstWhere('pregunta.tipo', 'reto');
        $this->assertSame('html', app(ImportadorBanco::class)->aBanco($ap)['lenguaje']);
    }

    #[Test]
    public function rechaza_lenguajes_que_no_se_pueden_probar_en_el_navegador(): void
    {
        $errores = app(ImportadorBanco::class)->validar(['sigla' => 'DW-OTRO'] + $this->banco(['lenguaje' => 'php']));
        $this->assertTrue(collect($errores)->contains(fn ($e) => str_contains($e, '«lenguaje» debe ser «javascript», «html» o «sql»')));
    }

    #[Test]
    public function el_calificador_recibe_el_lenguaje_y_el_detalle_muestra_la_pagina_sin_ejecutarla(): void
    {
        $pagina = '<nav><a href="#">Inicio</a><a href="#">Blog</a><a href="#">Contacto</a></nav><script>alert("x")</script><style>a{color:red}</style>';
        $this->entregar($pagina);
        $e = Entrega::query()->sole();

        $pend = $this->getJson(route('calificador.retos', $this->actividad))->assertOk()->json('pendientes');
        $this->assertSame('html', $pend[0]['lenguaje']);
        $this->assertSame($pagina, $pend[0]['codigo']);

        $item = $pend[0]['item'];
        $this->postJson(route('calificador.entregas.resultados-navegador', $e), ['resultados' => [['item' => $item, 'verificaciones' => ['v1' => true, 'v2' => true],
            'casos' => [['entrada' => "document.querySelectorAll('nav a').length", 'esperada' => '3', 'consola' => [], 'error' => null, 'retorno' => '3']]]]])->assertOk();

        $detalle = $this->get(route('calificador.detalle', $e))->assertOk()->getContent();
        // Vista previa en un marco sin permisos: el HTML va escapado dentro de srcdoc y sus scripts no corren
        $this->assertMatchesRegularExpression('/<iframe class="vista-previa-html" sandbox=""/', $detalle);
        $this->assertStringContainsString('srcdoc="&lt;meta http-equiv=&quot;Content-Security-Policy&quot;', $detalle);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $detalle);
        $this->assertStringNotContainsString('<script>alert("x")</script>', $detalle);
        $this->assertStringContainsString('obtuvo <code>3</code>', $detalle);
    }

    #[Test]
    public function la_version_b_de_un_reto_de_html_tambien_es_de_html(): void
    {
        $items = $this->banco()['items'];
        $sinLenguaje = $items;
        unset($sinLenguaje[1]['lenguaje']);

        $errores = app(ImportadorBanco::class)->validarVersion(Artefacto::query()->sole(), $sinLenguaje, 'B');
        $this->assertTrue(collect($errores)->contains(fn ($e) => str_contains($e, 'mismos tipos de ítem')));
        $this->assertSame([], app(ImportadorBanco::class)->validarVersion(Artefacto::query()->sole(), $items, 'B'));
    }

    private function entregar(string $codigo): void
    {
        $ids = $this->actividad->artefacto->items()->orderBy('orden')->pluck('id')->all();
        $carga = ['sigla' => 'DW-MENU', 'artefacto' => $this->actividad->artefacto->uuid, 'documento' => $this->ana->documento, 'nombre' => $this->ana->nombreCompleto(),
            'integrantes' => [], 'inicio' => now()->subMinutes(20)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual',
            'respuestas' => [$ids[0] => true, $ids[1] => $codigo], 'sesion' => ['salidas' => [], 'pegados' => 0]];
        $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'DW-MENU', 'artefacto' => $this->actividad->artefacto->uuid,
            'documento' => $this->ana->documento, 'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))];
        $ruta = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($ruta, json_encode($sobre));
        $this->post(route('calificador.subir', $this->actividad), ['entregas' => [new UploadedFile($ruta, 'e.json', 'application/json', null, true)]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] === 'procesada');
    }
}
