<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\PruebasMinimas;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Artefacto;
use App\Models\Entrega;
use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Versiones A y B y segundo banco contra filtración entre jornadas (5.4.2). */
class VersionesExamenTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Artefacto $artefacto;

    private Actividad $actividad;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->artefacto = app(ImportadorBanco::class)->importar($this->materia, $this->banco());
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => $this->artefacto->id, 'nombre' => 'Parcial 1', 'valor_max' => '5']);
    }

    /** Dos ítems por versión, con los mismos tipos y pesos y distinto contexto. */
    private function banco(array $cambios = []): array
    {
        $item = fn (string $v, string $ctx, string $correcta) => [
            ['version' => $v, 'tipo' => 'unica', 'peso' => '3', 'enunciado' => "En {$ctx}, ¿qué estructura atiende al último que llegó?", 'opciones' => ['a' => 'Cola', 'b' => 'Pila'], 'correcta' => $correcta === 'pila' ? 'b' : 'a'],
            ['version' => $v, 'tipo' => 'vf', 'peso' => '2', 'enunciado' => "En {$ctx} se atiende por orden de llegada.", 'correcta' => true],
        ];

        return array_replace(['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-VER-P1', 'titulo' => 'Parcial con versiones', 'valor' => '5',
            'items' => [...$item('A', 'el casino', 'pila'), ...$item('B', 'el taller', 'pila'), ...$item('2A', 'la portería', 'pila'), ...$item('2B', 'el almacén', 'pila')]], $cambios);
    }

    /** Un documento al que le toca la versión pedida en el banco 1. */
    private function documentoPara(string $version): string
    {
        for ($d = 10000; ; $d++) {
            if (Artefacto::versionPara((string) $d, 'ED-VER-P1', ['A', 'B']) === $version) {
                return (string) $d;
            }
        }
    }

    private function entregar(Estudiante $e, ?string $version, array $respuestas): array
    {
        $carga = ['sigla' => 'ED-VER-P1', 'artefacto' => $this->artefacto->uuid, 'documento' => $e->documento, 'nombre' => $e->nombreCompleto(),
            'inicio' => now()->subMinutes(30)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual', 'respuestas' => $respuestas,
            'sesion' => ['salidas' => [], 'pegados' => 0]] + ($version ? ['version' => $version] : []);
        $sobre = ['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => 'ED-VER-P1', 'artefacto' => $this->artefacto->uuid, 'documento' => $e->documento,
            'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))];
        $ruta = tempnam(sys_get_temp_dir(), 'v');
        file_put_contents($ruta, json_encode($sobre));

        return $this->post(route('calificador.subir', $this->actividad), ['entregas' => [new UploadedFile($ruta, 'e.json', 'application/json', null, true)]])->getSession()->get('resultados');
    }

    private function ids(string $version): array
    {
        return $this->artefacto->items()->where('banco', $version)->orderBy('orden')->pluck('id')->all();
    }

    #[Test]
    public function el_importador_exige_versiones_parejas_que_sumen_el_valor(): void
    {
        $this->assertSame(['A', 'B', '2A', '2B'], $this->artefacto->versiones());
        $this->assertSame([1, 2], $this->artefacto->bancos());

        $imp = app(ImportadorBanco::class);
        $b = $this->banco(['sigla' => 'X1']);
        $b['items'][3]['peso'] = '1';                                       // B: vf con otro peso
        $e = implode(' | ', $imp->validar($b));
        $this->assertStringContainsString('Los pesos de la versión B suman 4', $e);
        $this->assertStringContainsString('Las versiones A y B deben tener los mismos tipos de ítem con los mismos pesos', $e);

        $soloB = $this->banco(['sigla' => 'X2', 'items' => array_slice($this->banco()['items'], 2, 2)]);
        $this->assertContains('Hay ítems de la versión B pero ninguno de la A.', $imp->validar($soloB));
        $this->assertStringContainsString('no existe', implode(' ', $imp->validar($this->banco(['sigla' => 'X3', 'items' => [['version' => 'C'] + $this->banco()['items'][0]]]))));

        // Un banco sin versiones sigue funcionando como antes: todo es A
        $simple = $imp->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'X4', 'titulo' => 'Simple', 'valor' => '1',
            'items' => [['tipo' => 'vf', 'peso' => '1', 'enunciado' => 'Sí.', 'correcta' => true]]]);
        $this->assertSame(['A'], $simple->versiones());
    }

    #[Test]
    public function cada_banco_se_descarga_aparte_con_sus_dos_versiones_y_sin_las_del_otro(): void
    {
        $this->get(route('calificador.show', $this->actividad))->assertSee('Descargar banco 1 (A y B)')->assertSee('Descargar banco 2 (2A y 2B)')
            ->assertSee('úselo en la otra jornada');
        $b1 = $this->get(route('calificador.examen', ['actividad' => $this->actividad, 'banco' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('el casino', $b1);
        $this->assertStringContainsString('el taller', $b1);
        $this->assertStringNotContainsString('la portería', $b1);
        $this->assertStringContainsString('"versiones":["A","B"]', $b1);

        $b2 = $this->get(route('calificador.examen', ['actividad' => $this->actividad, 'banco' => 2]))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ED-VER-P1_banco2.html"')->getContent();
        $this->assertStringContainsString('la portería', $b2);
        $this->assertStringNotContainsString('el casino', $b2);
        $this->get(route('calificador.examen', ['actividad' => $this->actividad, 'banco' => 3]))->assertNotFound();
    }

    #[Test]
    public function la_version_sale_del_documento_y_solo_se_califican_sus_items(): void
    {
        $docB = $this->documentoPara('B');
        $beto = $this->crearEstudiante($docB, 'BELTRAN', 'BETO');
        [$unicaB, $vfB] = $this->ids('B');

        $r = $this->entregar($beto, 'B', [$unicaB => 'b', $vfB => true]);
        $this->assertSame('procesada', $r[0]['estado']);
        $e = Entrega::query()->sole();
        $this->assertSame('B', $e->version);
        $this->assertSame(2, $e->respuestas()->count(), 'Solo los ítems de su versión: los de A no le cuentan como cero');
        $this->assertEqualsWithDelta(5.0, (float) $e->respuestas()->sum('puntaje'), 0.0001);
        $this->assertSame(0, Alerta::query()->where('tipo', 'version_no_coincide')->count());
        $this->get(route('calificador.show', $this->actividad))->assertSee('<th>Versión</th>', false)->assertSee('<td>B</td>', false);
    }

    #[Test]
    public function alerta_si_la_version_respondida_no_es_la_de_su_documento(): void
    {
        $docA = $this->documentoPara('A');
        $ana = $this->crearEstudiante($docA, 'ARIAS', 'ANA');
        $this->entregar($ana, 'B', array_fill_keys($this->ids('B'), 'b'));
        $alerta = Alerta::query()->where('tipo', 'version_no_coincide')->sole();
        $this->assertSame(['respondida' => 'B', 'esperada' => 'A'], $alerta->datos);
        $this->get(route('calificador.show', $this->actividad))->assertSee('Versión distinta a la de su documento');
    }

    #[Test]
    public function una_entrega_sin_version_declarada_se_reconoce_por_lo_que_respondio(): void
    {
        $e = $this->crearEstudiante($this->documentoPara('A'), 'CASTRO', 'CARLA');
        $this->entregar($e, null, [$this->ids('2A')[0] => 'b']);
        $this->assertSame('2A', Entrega::query()->sole()->version);
    }

    #[Test]
    public function el_calculo_de_la_version_es_el_mismo_que_en_el_navegador(): void
    {
        // Mismo FNV-1a que examen.js: semilla(doc + '|' + sigla) % número de versiones
        $html = $this->get(route('calificador.examen', $this->actividad))->getContent();
        $this->assertStringContainsString("return v[(semilla(doc + '|' + D.sigla) >>> 16) % v.length]", $html);
        $this->assertStringContainsString('h = Math.imul(h, 16777619)', $html);
        $versiones = array_unique(array_map(fn ($d) => Artefacto::versionPara((string) $d, 'ED-VER-P1', ['A', 'B']), range(10000, 10030)));
        $this->assertEqualsCanonicalizing(['A', 'B'], array_values($versiones), 'Con 31 documentos aparecen las dos versiones');
    }

    #[Test]
    public function las_pruebas_minimas_revisan_cada_version(): void
    {
        $r = collect(app(PruebasMinimas::class)->ejecutar($this->actividad))->where('numero', 1);
        $this->assertCount(4, $r);
        $this->assertSame(['ok'], $r->pluck('estado')->unique()->values()->all());
    }
}
