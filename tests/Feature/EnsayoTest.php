<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\EditorBanco;
use App\Evaluacion\EnsayoExamen;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\PruebasMinimas;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Ensayo;
use App\Models\Entrega;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Modo de ensayo (5.6) y pruebas mínimas antes de aplicar (9.2). */
class EnsayoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private Artefacto $artefacto;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 1);
        $this->actingAs($this->docente);
        $this->artefacto = app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-ENS-P1', 'titulo' => 'Parcial', 'valor' => '5', 'items' => [
            ['tipo' => 'unica', 'peso' => '1', 'enunciado' => '¿Qué estructura es LIFO?', 'opciones' => ['a' => 'Cola', 'b' => 'Pila'], 'correcta' => 'b', 'justificacion' => 'En una pila sale primero el último que entró.'],
            ['tipo' => 'multiple', 'peso' => '1', 'enunciado' => 'Marque las lineales.', 'opciones' => ['a' => 'Pila', 'b' => 'Árbol', 'c' => 'Cola'], 'correctas' => ['a', 'c']],
            ['tipo' => 'completar', 'peso' => '1', 'enunciado' => 'Complete.', 'plantilla' => 'pila.___(x)', 'huecos' => ['push']],
            ['tipo' => 'reto', 'peso' => '2', 'enunciado' => 'Escriba invertir(texto).', 'plantilla_codigo' => "function invertir(t) {\n}\n",
                'verificaciones' => [['id' => 'v1', 'nombre' => 'Invierte', 'fraccion' => '0.5', 'prueba' => 'invertir("hola") === "aloh"'],
                    ['id' => 'v2', 'nombre' => 'Vacío', 'fraccion' => '0.5', 'prueba' => 'invertir("") === "" && true', 'reservada' => true]]],
        ]]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => $this->artefacto->id, 'nombre' => 'Parcial 1', 'valor_max' => '5']);
    }

    private function pruebas(): array
    {
        return collect(app(PruebasMinimas::class)->ejecutar($this->actividad->fresh()))->groupBy('numero')->map(fn ($g) => $g->pluck('estado')->unique()->values()->all())->all();
    }

    private function entregaEnsayo(bool $cifrar = true, bool $ensayo = true, array $verificaciones = []): string
    {
        $ids = $this->artefacto->items()->orderBy('orden')->pluck('id')->all();
        $carga = ['sigla' => 'ED-ENS-P1', 'artefacto' => $this->artefacto->uuid, 'documento' => '00000', 'nombre' => 'ENSAYO',
            'respuestas' => [$ids[0] => 'b', $ids[1] => ['a'], $ids[3] => 'function invertir(t) { return t.split("").reverse().join(""); }'],
            'sesion' => ['salidas' => [['inicio' => now()->toIso8601String(), 'segundos' => 7]], 'ejecuciones' => 20, 'navegador' => self::UA,
                'ensayo' => ['verificaciones' => $verificaciones ?: [['nombre' => 'Cifrado de la entrega', 'ok' => true], ['nombre' => 'Entorno de código: «No compila»', 'ok' => true]],
                    'navegador' => self::UA, 'pantalla' => '1366×768']]];
        $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'ED-ENS-P1', 'artefacto' => $this->artefacto->uuid, 'documento' => '00000'];
        if ($ensayo) {
            $sobre['ensayo'] = true;
        }
        $sobre += $cifrar ? ['cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))] : ['cifrado' => null, 'datos_planos' => $carga];

        return json_encode($sobre);
    }

    private function archivo(string $json, string $nombre = 'ENSAYO.json'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($ruta, $json);

        return new UploadedFile($ruta, $nombre, 'application/json', null, true);
    }

    #[Test]
    public function la_solucion_modelo_se_prueba_en_el_ensayo_y_no_viaja_al_examen(): void
    {
        $this->assertSame(['navegador'], $this->pruebas()[8]);
        $this->assertStringContainsString('Sin «solucion» en el banco: ítem 4', collect(app(PruebasMinimas::class)->ejecutar($this->actividad))->firstWhere('numero', 8)['detalle']);

        $reto = $this->artefacto->items()->with('pregunta')->get()->firstWhere('pregunta.tipo', 'reto')->pregunta;
        $modelo = 'function invertir(t) { return t.split("").reverse().join(""); }';
        $reto->update(['clave' => ['solucion' => $modelo] + $reto->clave]);

        $p8 = collect(app(PruebasMinimas::class)->ejecutar($this->actividad->fresh()))->firstWhere('numero', 8);
        $this->assertSame('navegador', $p8['estado']);
        $this->assertStringContainsString('Probar con la solución modelo', $p8['detalle']);
        $this->get(route('calificador.ensayo', $this->actividad))->assertOk()->assertSee('id="probar-modelos"', false);
        $this->getJson(route('calificador.ensayo.modelos', $this->actividad))->assertOk()
            ->assertJsonPath('retos.0.solucion', $modelo)->assertJsonPath('retos.0.inicial', "function invertir(t) {\n}\n")
            ->assertJsonPath('retos.0.verificaciones.1.fraccion', '0.5');

        $html = $this->get(route('calificador.ensayo.examen', $this->actividad))->assertOk()->getContent();
        $this->assertStringNotContainsString('reverse().join', $html, 'la solución modelo nunca viaja al examen');
        $this->assertSame(['ok'], $this->pruebas()[7]);
    }

    #[Test]
    public function si_se_exige_el_ensayo_el_examen_no_sale_hasta_hacerlo(): void
    {
        $this->artefacto->update(['configuracion' => ['exigir_ensayo' => true] + $this->artefacto->configuracion]);

        $this->get(route('calificador.examen', $this->actividad))->assertRedirect(route('calificador.ensayo', $this->actividad))
            ->assertSessionHasErrors('ensayo');
        $this->get(route('calificador.ensayo.examen', $this->actividad))->assertOk();

        $this->artefacto->update(['ensayado_en' => now()]);
        $this->get(route('calificador.examen', $this->actividad))->assertOk()->assertHeader('Content-Disposition');

        // Cambiar las preguntas deja el ensayo pendiente otra vez
        $editor = app(EditorBanco::class);
        $editor->guardar($this->artefacto->fresh(), $editor->exportar($this->artefacto->fresh(), true));
        $this->assertNull($this->artefacto->fresh()->ensayado_en);
    }

    #[Test]
    public function un_examen_bien_armado_pasa_las_pruebas_minimas(): void
    {
        $p = $this->pruebas();
        foreach ([1, 2, 3, 4, 5, 6, 7, 10] as $n) {
            $this->assertSame(['ok'], $p[$n], "La prueba {$n} debe pasar");
        }
        $this->assertSame(['navegador'], $p[8]);
        $this->assertSame(['navegador'], $p[9]);

        $this->get(route('calificador.ensayo', $this->actividad))->assertOk()
            ->assertSee('✓ Pasan')->assertSee('Cifrado y descifrado con la clave del periodo')->assertSee('Descargar examen de ensayo')
            ->assertSee('Este examen aún no se ha ensayado');
    }

    #[Test]
    public function detecta_claves_fuera_de_las_opciones_pesos_que_no_suman_y_respuestas_filtradas(): void
    {
        $items = $this->artefacto->items()->orderBy('orden')->get();
        $p = $items[0]->pregunta;
        $p->update(['clave' => ['correcta' => 'z']]);
        $items[1]->update(['peso' => '0.5']);
        $items[2]->pregunta->update(['enunciado' => $items[2]->pregunta->enunciado + ['justificacion' => 'se filtró']]);

        $r = collect(app(PruebasMinimas::class)->ejecutar($this->actividad->fresh()))->keyBy('numero');
        $this->assertSame('falla', $r[1]['estado']);
        $this->assertSame('falla', $r[4]['estado']);
        $this->assertStringContainsString('Suman 4.5 de 5', $r[4]['detalle']);
        $this->assertSame('falla', $r[5]['estado']);
        $this->assertStringContainsString('Ítem 1: la respuesta «z» no es una de sus opciones', $r[5]['detalle']);
        $this->assertSame('falla', $r[7]['estado']);
        $this->assertStringContainsStringIgnoringCase('aparece «justificacion»', $r[7]['detalle']);
        $this->get(route('calificador.ensayo', $this->actividad))->assertSee('Hay fallas: corrija antes de aplicar');
    }

    #[Test]
    public function el_examen_de_ensayo_se_marca_como_tal_y_guarda_aparte(): void
    {
        $ensayo = $this->get(route('calificador.ensayo.examen', $this->actividad))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ENSAYO_ED-ENS-P1.html"')->getContent();
        $this->assertStringContainsString('"ensayo":true', $ensayo);
        $this->assertStringContainsString('class="marca-ensayo">ENSAYO', $ensayo);
        $this->assertStringContainsString("'plataforma-ensayo:'", $ensayo);
        $this->assertStringNotContainsString('invertir("hola")', $ensayo, 'Ni el ensayo lleva las verificaciones');

        $real = $this->get(route('calificador.examen', $this->actividad))->getContent();
        $this->assertStringContainsString('"ensayo":false', $real);
        $this->assertStringNotContainsString('class="marca-ensayo"', $real);
    }

    #[Test]
    public function la_entrega_de_ensayo_cierra_el_ciclo_sin_crear_notas_y_queda_registrada(): void
    {
        $this->post(route('calificador.ensayo.subir', $this->actividad), ['entrega' => $this->archivo($this->entregaEnsayo())])->assertOk()
            ->assertSee('El ciclo está cerrado')->assertSee('Chrome 131 · Windows · 1366×768')
            ->assertSee('Salidas de la ventana: <strong>1</strong>', false)->assertSee('20</strong>', false)
            ->assertSee('parte del servidor: 1.67')                                   // única 1 + múltiple 2/3 (dos de tres decisiones bien) + completar 0
            ->assertSee('function invertir(t) { return t.split(&quot;&quot;).reverse().join(&quot;&quot;); }', false)
            ->assertSee('Ejecutando las verificaciones en este navegador');

        $e = Ensayo::query()->sole();
        $this->assertTrue($e->equipo_listo);
        $this->assertTrue($e->cifrada);
        $this->assertSame(0, Entrega::query()->count());
        $this->assertSame(0, Nota::query()->count());
        $this->assertNotNull($this->artefacto->fresh()->ensayado_en);
        $this->get(route('calificador.show', $this->actividad))->assertSee('Ensayado el')->assertSee('Chrome 131 · Windows · 1366×768');
        $this->get(route('calificador.ensayo', $this->actividad))->assertSee('Ensayos registrados')->assertSee('✓ Sí');
    }

    #[Test]
    public function un_equipo_que_no_cifra_o_falla_una_verificacion_no_queda_listo(): void
    {
        $this->post(route('calificador.ensayo.subir', $this->actividad), ['entrega' => $this->archivo($this->entregaEnsayo(cifrar: false))])
            ->assertSee('El equipo no está listo')->assertSee('la entrega llegó sin cifrar');
        $this->post(route('calificador.ensayo.subir', $this->actividad), ['entrega' => $this->archivo($this->entregaEnsayo(verificaciones: [
            ['nombre' => 'Autoguardado en este navegador', 'ok' => false, 'detalle' => 'modo incógnito']]))])->assertSee('✗ Autoguardado en este navegador');
        $this->assertSame([false, false], Ensayo::query()->orderBy('id')->pluck('equipo_listo')->all());
    }

    #[Test]
    public function el_ensayo_y_las_entregas_reales_no_se_mezclan(): void
    {
        $this->post(route('calificador.subir', $this->actividad), ['entregas' => [$this->archivo($this->entregaEnsayo())]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] === 'rechazada' && str_contains($r[0]['mensaje'], 'es una entrega del examen de ensayo'));
        $this->assertSame(0, Entrega::query()->count());

        $this->post(route('calificador.ensayo.subir', $this->actividad), ['entrega' => $this->archivo($this->entregaEnsayo(ensayo: false))])
            ->assertSessionHasErrors(['entrega' => 'Esta es una entrega real de un estudiante, no de ensayo. Súbala en el calificador.']);
        $this->assertSame(0, Ensayo::query()->count());
    }

    #[Test]
    public function describe_el_equipo_y_solo_el_docente_ensaya(): void
    {
        $this->assertSame('Firefox 128 · Linux · 1920×1080', EnsayoExamen::equipo('Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0', '1920×1080'));
        $this->assertSame('Edge 130 · Windows', EnsayoExamen::equipo('Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36 Edg/130.0', ''));

        $this->actingAs(User::factory()->create());
        $this->get(route('calificador.ensayo', $this->actividad))->assertForbidden();
        $this->get(route('calificador.ensayo.examen', $this->actividad))->assertForbidden();
        $this->post(route('calificador.ensayo.subir', $this->actividad), ['entrega' => $this->archivo($this->entregaEnsayo())])->assertForbidden();
    }
}
