<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Evaluacion\ImportadorBanco;
use App\Livewire\CalificarAbiertas;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Artefacto;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Módulos 2 y 3: banco, examen autocontenido, recepción de entregas cifradas y calificación híbrida. */
class CalificadorEntregasTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private array $banco;

    private Estudiante $ana;

    private Estudiante $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->ana = $this->crearEstudiante('10001', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('10002', 'BELTRAN', 'BETO');
        $this->banco = json_decode(file_get_contents(base_path('docs/ejemplos/banco-ejemplo.json')), true);
        $this->actingAs($this->docente);
    }

    private function archivoJson(array $datos, string $nombre = 'banco.json'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'j');
        file_put_contents($ruta, json_encode($datos));

        return new UploadedFile($ruta, $nombre, 'application/json', null, true);
    }

    /** Importa el banco de ejemplo y lo aplica en el parcial del corte 1. */
    private function aplicar(array $extra = []): Actividad
    {
        $this->post(route('artefactos.importar', $this->materia), ['banco' => $this->archivoJson($this->banco)])->assertSessionHasNoErrors();
        $this->post(route('artefactos.aplicar', Artefacto::query()->sole()), [
            'componente_id' => $this->parcial->id, 'grupos' => [$this->grupo->id], ...$extra,
        ])->assertRedirect();

        return Actividad::query()->sole();
    }

    /** Respuestas por orden de ítem → por id del ítem, como las guarda el examen. */
    private function respuestas(Actividad $a, array $porOrden): array
    {
        $ids = $a->artefacto->items()->orderBy('orden')->pluck('id')->all();

        return collect($porOrden)->mapWithKeys(fn ($v, $i) => [$ids[$i] => $v])->all();
    }

    /** Arma la entrega igual que el examen en el navegador (mismo algoritmo de cifrado). */
    private function entrega(Actividad $a, Estudiante $e, array $respuestas, array $cambios = [], bool $cifrar = true): UploadedFile
    {
        $carga = array_replace_recursive([
            'sigla' => $a->artefacto->sigla, 'artefacto' => $a->artefacto->uuid, 'documento' => $e->documento,
            'nombre' => $e->nombreCompleto(), 'grupo' => 'Diurno', 'integrantes' => [],
            'inicio' => now()->subMinutes(20)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual',
            'respuestas' => $respuestas,
            'sesion' => ['salidas' => [], 'pegados' => 0, 'ejecuciones' => 3, 'errores_sintaxis' => 0, 'reconexiones' => []],
        ], $cambios['carga'] ?? []);
        $sobre = array_replace([
            'formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => $carga['sigla'], 'artefacto' => $carga['artefacto'],
            'documento' => $e->documento, 'generado' => now()->toIso8601String(),
            'cifrado' => $cifrar ? app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga)) : null,
        ], $cambios['sobre'] ?? []);
        if (! $cifrar) {
            $sobre['datos_planos'] = $carga;
        }

        return $this->archivoJson($sobre, "{$carga['sigla']}_{$e->documento}.json");
    }

    private const CODIGO_BUENO = "function total(precios) {\n  return precios.reduce((s, p) => s + p, 0);\n}";

    private function perfectas(Actividad $a): array
    {
        return $this->respuestas($a, ['b', true, ['a', 'c'], ['Function', ' return '], self::CODIGO_BUENO]);
    }

    #[Test]
    public function el_banco_se_valida_antes_de_importar(): void
    {
        $i = app(ImportadorBanco::class);
        $this->assertSame([], $i->validar($this->banco));

        $malo = $this->banco;
        $malo['items'][0]['correcta'] = 'z';
        $malo['items'][1]['peso'] = '0.7';
        $malo['items'][4]['verificaciones'][0]['fraccion'] = '0.6';
        $errores = implode("\n", $i->validar($malo));

        $this->assertStringContainsString('la respuesta correcta no está entre las opciones', $errores);
        $this->assertStringContainsString('Los pesos suman 5.2', $errores);
        $this->assertStringContainsString('las fracciones de las verificaciones suman 1.1', $errores);

        $this->post(route('artefactos.importar', $this->materia), ['banco' => $this->archivoJson($malo)])->assertSessionHasErrors('banco');
        $this->assertSame(0, Artefacto::query()->count());
    }

    #[Test]
    public function el_examen_es_autocontenido_y_no_lleva_respuestas(): void
    {
        $a = $this->aplicar();
        $r = $this->get(route('calificador.examen', $a));
        $r->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="ED-DEMO-Q1.html"');
        $html = $r->getContent();

        $this->assertStringContainsString('ED-DEMO-Q1', $html);
        $this->assertStringContainsString(app(ClavesDictado::class)->publicaSpkiBase64($this->dictado), $html);
        // 9.2.7: ni solucionario, ni pruebas de verificación, ni casos reservados
        foreach (['"correcta"', '"correctas"', '"huecos":[', '"verificaciones"', 'total([]) === 0', 'Un carrito vacío suma cero', 'length cuenta los elementos'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $html, "El examen contiene «{$secreto}».");
        }
        $this->assertStringContainsString('"reservadas":1', $html);
        // 4.5.5: exactamente dos bloques de script y ninguno cortado por el contenido
        $this->assertSame(2, substr_count($html, '</script>'));
        // Regresión verificada en navegador real: sin 'unsafe-eval' el Worker no puede ejecutar el código
        // del estudiante y «Probar» responde «No compila» para todo (sección 4)
        $this->assertMatchesRegularExpression("/script-src[^;]*'unsafe-eval'/", $html);
        $this->assertMatchesRegularExpression("/default-src 'none'/", $html);
        $this->assertStringNotContainsString('https://', str_replace('https://www.google.com/generate_204', '', $html), 'Sin recursos externos.');
    }

    #[Test]
    public function una_entrega_cifrada_se_descifra_y_califica_lo_determinista(): void
    {
        $a = $this->aplicar();

        $this->post(route('calificador.subir', $a), ['entregas' => [$this->entrega($a, $this->ana, $this->perfectas($a))]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] === 'procesada');

        $e = Entrega::query()->sole();
        $this->assertTrue($e->cifrada);
        $this->assertSame('pendiente', $e->estado_codigo);
        $this->assertSame(1200, $e->duracion_seg);
        // 1 + 0.5 + 1 + 0.5 = 3.0 en el servidor; el reto (2.0) espera al navegador
        $this->assertEquals(3.0, $e->respuestas()->whereNotNull('puntaje')->get()->sum(fn ($r) => (float) $r->puntaje));
        $this->get(route('calificador.show', $a))->assertOk()->assertSee('ARIAS, ANA')->assertSee('1 reto(s)');
    }

    #[Test]
    public function acepta_bancos_y_entregas_con_el_nombre_de_formato_anterior(): void
    {
        // Bancos guardados y exámenes repartidos antes del cambio de nombre de la plataforma siguen sirviendo
        $this->assertTrue(ImportadorBanco::esFormatoBanco('anterior-banco'));
        $this->assertFalse(ImportadorBanco::esFormatoBanco('otra-cosa'));
        $this->assertSame([], app(ImportadorBanco::class)->validar(['formato' => 'anterior-banco'] + $this->banco));

        $a = $this->aplicar();
        $this->post(route('calificador.subir', $a), ['entregas' => [$this->entrega($a, $this->ana, $this->perfectas($a), ['sobre' => ['formato' => 'anterior-entrega']])]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] === 'procesada');
        $this->post(route('calificador.subir', $a), ['entregas' => [$this->entrega($a, $this->ana, $this->perfectas($a), ['sobre' => ['formato' => 'otra-cosa']])]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] !== 'procesada');
    }

    #[Test]
    public function cuarenta_entregas_se_procesan_en_menos_de_dos_minutos(): void
    {
        // 9.3: rendimiento del calificador con un grupo lleno (40 estudiantes) subido de una vez
        $a = $this->aplicar();
        $archivos = [];
        for ($i = 3; $i <= 40; $i++) {
            $e = $this->crearEstudiante((string) (10000 + $i), 'APELLIDO'.$i, 'NOMBRE');
            $archivos[] = $this->entrega($a, $e, $this->perfectas($a));
        }
        $archivos[] = $this->entrega($a, $this->ana, $this->perfectas($a));
        $archivos[] = $this->entrega($a, $this->beto, $this->perfectas($a));

        $t = microtime(true);
        $this->post(route('calificador.subir', $a), ['entregas' => $archivos])
            ->assertSessionHas('resultados', fn ($r) => count($r) === 40 && collect($r)->every(fn ($x) => $x['estado'] === 'procesada'));
        $segundos = microtime(true) - $t;
        $this->get(route('calificador.show', $a))->assertOk();
        $total = microtime(true) - $t;

        $this->assertSame(40, Entrega::query()->count());
        $this->assertLessThan(120, $total, "40 entregas tardaron {$total} s ({$segundos} s procesando)");
    }

    #[Test]
    public function ciclo_completo_hasta_el_registro(): void
    {
        $a = $this->aplicar();
        $this->post(route('calificador.subir', $a), ['entregas' => [$this->entrega($a, $this->ana, $this->perfectas($a))]]);
        $e = Entrega::query()->sole();

        $retos = $this->getJson(route('calificador.retos', $a))->assertOk()->json('pendientes');
        $this->assertCount(1, $retos);
        $this->assertSame(self::CODIGO_BUENO, $retos[0]['codigo']);
        $this->assertSame(['dos', 'vacio'], array_column($retos[0]['verificaciones'], 'id'));

        // El navegador del docente ejecutó: una verificación pasa y la reservada no
        $this->postJson(route('calificador.entregas.resultados-navegador', $e), ['resultados' => [
            ['item' => $retos[0]['item'], 'verificaciones' => ['dos' => true, 'vacio' => false]],
        ]])->assertOk();

        $this->get(route('calificador.detalle', $e))->assertOk()
            ->assertSee('Un carrito vacío suma cero')->assertSee('✗ No superada')->assertSee('length cuenta los elementos');

        // Una respuesta sin desglose (traída de otra herramienta) no tumba el detalle: muestra el puntaje sin explicación
        $e->respuestas()->whereHas('item.pregunta', fn ($q) => $q->where('tipo', 'unica'))->update(['desglose' => null]);
        $this->get(route('calificador.detalle', $e))->assertOk()->assertSee('No hay detalle guardado de esta respuesta');

        $this->post(route('calificador.registro', $a))->assertSessionHas('estado');
        $nota = Nota::query()->sole();
        $this->assertSame('4.0000', $nota->valor);           // 3.0 + 1.0 del reto
        $this->assertSame('calificador', $nota->origen);
        $this->assertSame($e->id, $nota->entrega_id);
    }

    #[Test]
    public function rechaza_entregas_de_otro_examen_de_fuera_de_la_lista_y_duplicadas(): void
    {
        $a = $this->aplicar();
        $otro = $this->banco;
        $otro['sigla'] = 'ED-DEMO-Q2';
        app(ImportadorBanco::class)->importar($this->materia, $otro);
        $q2 = Artefacto::query()->where('sigla', 'ED-DEMO-Q2')->sole();
        $ajeno = $this->crearEstudiante('99999', 'AJENO', 'X');
        $this->grupo->estudiantes()->detach($ajeno->id);
        $ajeno->delete();

        $equivocada = $this->entrega($a, $this->ana, [], ['carga' => ['sigla' => 'ED-DEMO-Q2', 'artefacto' => $q2->uuid]]);
        $fuera = $this->entrega($a, new Estudiante(['documento' => '55555', 'apellidos' => 'NO', 'nombres' => 'ESTA']), []);
        $buena = $this->entrega($a, $this->beto, []);
        $copia = new UploadedFile($buena->getRealPath(), 'copia.json', 'application/json', null, true);

        $r = $this->post(route('calificador.subir', $a), ['entregas' => [$equivocada, $fuera, $buena, $copia]]);
        $res = session('resultados');

        $this->assertSame(['rechazada', 'rechazada', 'procesada', 'duplicada'], array_column($res, 'estado'));
        $this->assertStringContainsString('es del examen ED-DEMO-Q2', $res[0]['mensaje']);
        $this->assertStringContainsString('no está en la lista del periodo', $res[1]['mensaje']);
        $this->assertSame(1, Entrega::query()->count());
    }

    #[Test]
    public function alertas_de_identidad_sesion_y_cifrado(): void
    {
        $a = $this->aplicar();
        $salidas = [['inicio' => now()->toIso8601String(), 'segundos' => 40], ['inicio' => now()->toIso8601String(), 'segundos' => 35]];

        $this->post(route('calificador.subir', $a), ['entregas' => [
            $this->entrega($a, $this->ana, [], ['sobre' => ['documento' => '77777'],
                'carga' => ['sesion' => ['salidas' => $salidas, 'pegados' => 2], 'inicio' => now()->subMinutes(2)->toIso8601String(), 'cierre' => 'automatico']]),
            $this->entrega($a, $this->beto, [], [], cifrar: false),
        ]]);

        $tipos = fn (Estudiante $e) => Alerta::query()->where('estudiante_id', $e->id)->pluck('tipo')->sort()->values()->all();
        $this->assertSame(['cierre_automatico', 'duracion_anomala', 'manipulado', 'pegados', 'salidas'], $tipos($this->ana));
        $this->assertSame(['sin_cifrar'], $tipos($this->beto));
        $this->assertSame(75, Alerta::query()->where('tipo', 'salidas')->sole()->datos['segundos']);
    }

    #[Test]
    public function marca_codigo_identico_aunque_cambien_comentarios_y_espacios(): void
    {
        $a = $this->aplicar();
        $copia = "function total(precios){ // mi solución\n return precios.reduce((s,p)=>s+p,0);}\n/* fin */";

        $this->post(route('calificador.subir', $a), ['entregas' => [
            $this->entrega($a, $this->ana, $this->respuestas($a, [4 => self::CODIGO_BUENO])),
            $this->entrega($a, $this->beto, $this->respuestas($a, [4 => $copia])),
        ]]);

        $this->assertSame(2, Alerta::query()->where('tipo', 'texto_identico')->count());
    }

    #[Test]
    public function la_entrega_grupal_baja_la_nota_a_todos(): void
    {
        $a = $this->aplicar(['es_grupal' => '1']);
        $this->post(route('calificador.subir', $a), ['entregas' => [
            $this->entrega($a, $this->ana, $this->perfectas($a), ['carga' => ['integrantes' => ['10002', '88888']]]),
        ]]);
        $e = Entrega::query()->sole();
        $item = $e->respuestas()->whereNull('puntaje')->sole()->artefacto_pregunta_id;
        $this->postJson(route('calificador.entregas.resultados-navegador', $e), ['resultados' => [['item' => $item, 'verificaciones' => ['dos' => true, 'vacio' => true]]]]);

        $this->post(route('calificador.registro', $a));

        $this->assertSame(['5.0000', '5.0000'], Nota::query()->orderBy('estudiante_id')->pluck('valor')->all());
        $this->assertTrue(Alerta::query()->where('tipo', 'integrante_desconocido')->exists());
    }

    #[Test]
    public function las_abiertas_se_califican_por_pregunta_dentro_del_rango(): void
    {
        $banco = $this->banco;
        $banco['items'][0]['peso'] = '0.5';
        $banco['items'][] = ['tipo' => 'abierta', 'peso' => '0.5', 'enunciado' => 'Explique qué hace reduce.', 'referencia' => 'Acumula.'];
        $this->banco = $banco;
        $a = $this->aplicar();
        $this->post(route('calificador.subir', $a), ['entregas' => [
            $this->entrega($a, $this->ana, $this->respuestas($a, [5 => "Recorre el arreglo\n- y acumula"])),
        ]]);
        $r = Entrega::query()->sole()->respuestas()->whereHas('item.pregunta', fn ($q) => $q->where('tipo', 'abierta'))->sole();

        Livewire::test(CalificarAbiertas::class, ['actividad' => $a])
            ->assertSee('Explique qué hace reduce.')->assertSee("Recorre el arreglo\n- y acumula", false)
            ->call('puntuar', $r->id, '0.9')->assertSet("errores.{$r->id}", 'Debe estar entre 0 y 0.5.')
            ->call('puntuar', $r->id, '0,4')->call('comentar', $r->id, 'Bien, falta el valor inicial.');

        $r->refresh();
        $this->assertSame(['0.4000', 'docente', 'Bien, falta el valor inicial.'], [$r->puntaje, $r->calificado_por, $r->comentario]);
    }

    #[Test]
    public function las_abiertas_se_califican_tambien_por_estudiante_y_se_exportan(): void
    {
        // 6.7.2: se pasa de un modo al otro sin perder puntajes; 6.7.8: umbral de respuesta corta; 6.7.14: exportación
        $banco = $this->banco;
        $banco['items'][0]['peso'] = '0.5';
        $banco['items'][2]['peso'] = '0.5';
        $banco['items'][] = ['tipo' => 'abierta', 'peso' => '0.5', 'enunciado' => 'Explique qué hace reduce.', 'referencia' => 'Acumula.'];
        $banco['items'][] = ['tipo' => 'abierta', 'peso' => '0.5', 'enunciado' => 'Explique qué hace map.', 'referencia' => 'Transforma.'];
        $this->banco = $banco;
        $a = $this->aplicar();
        $this->post(route('calificador.subir', $a), ['entregas' => [
            $this->entrega($a, $this->ana, $this->respuestas($a, [5 => 'Recorre el arreglo y acumula un valor', 6 => 'Devuelve otro arreglo; transforma; cada; elemento'])),
            $this->entrega($a, $this->beto, $this->respuestas($a, [5 => '', 6 => 'No sé'])),
        ]]);
        $abiertas = fn (Estudiante $e) => Entrega::query()->whereHas('integrantes', fn ($q) => $q->whereKey($e->id))->sole()
            ->respuestas()->whereHas('item.pregunta', fn ($q) => $q->where('tipo', 'abierta'))->with('item')->get()->sortBy('item.orden')->values();
        [$anaReduce, $anaMap] = $abiertas($this->ana);

        $lw = Livewire::test(CalificarAbiertas::class, ['actividad' => $a])
            ->call('puntuar', $anaReduce->id, '0.5')                                   // por pregunta…
            ->set('modo', 'estudiante')->assertSet('entregaId', $anaReduce->entrega_id)  // …y luego por estudiante
            ->assertSee('1. Explique qué hace reduce.')->assertSee('2. Explique qué hace map.')
            ->assertSee('ARIAS, ANA — faltan 1')->assertSee('BELTRAN, BETO — faltan 2')
            ->assertSeeHtml('value="0.5"')                                              // el puntaje dado en el otro modo sigue ahí
            ->call('puntuar', $anaMap->id, '0.25')->call('comentar', $anaMap->id, 'Le faltó un ejemplo.')
            ->assertSee('ARIAS, ANA — ✓');
        $lw->set('umbral', 0);
        $this->assertStringNotContainsString('Corta', strip_tags($lw->html()));
        $lw->set('umbral', 7);                                                          // reduce tiene 7 palabras; map, 6
        $this->assertSame(1, substr_count(strip_tags($lw->html()), 'Corta'));
        $lw->set('entregaId', $abiertas($this->beto)[0]->entrega_id)->assertSee('Vacía');

        $csv = $this->get(route('calificador.abiertas.csv', $a))->assertOk()->streamedContent();
        $filas = array_map(fn ($l) => str_getcsv($l, ';'), explode("\n", trim(ltrim($csv, "\u{FEFF}"))));
        $this->assertSame(['Documento', 'Estudiante', 'P1 puntaje (de 0,5)', 'P1 comentario', 'P1 respuesta', 'P2 puntaje (de 0,5)', 'P2 comentario', 'P2 respuesta', 'Total abiertas', 'Sin calificar'], $filas[0]);
        $this->assertSame(['10001', 'ARIAS, ANA', '0,5', '', 'Recorre el arreglo y acumula un valor', '0,25', 'Le faltó un ejemplo.', 'Devuelve otro arreglo; transforma; cada; elemento', '0,75', '0'], $filas[1]);
        $this->assertSame(['10002', 'BELTRAN, BETO', '', '', '', '', '', 'No sé', '0', '2'], $filas[2]);

        $this->actingAs(User::factory()->create());
        $this->get(route('calificador.abiertas.csv', $a))->assertForbidden();
    }

    #[Test]
    public function todo_el_calificador_es_solo_del_docente_dueno(): void
    {
        $a = $this->aplicar();
        $this->actingAs(User::factory()->create());

        $this->get(route('artefactos.index', $this->materia))->assertForbidden();
        $this->get(route('calificador.show', $a))->assertForbidden();
        $this->get(route('calificador.examen', $a))->assertForbidden();
        $this->getJson(route('calificador.retos', $a))->assertForbidden();
        $this->post(route('calificador.registro', $a))->assertForbidden();
        $this->get(route('calificador.abiertas', $a))->assertForbidden();
    }

    #[Test]
    public function las_claves_privadas_quedan_cifradas_en_la_base(): void
    {
        $clave = app(ClavesDictado::class)->de($this->dictado);
        $crudo = DB::table('claves_cifrado')->where('id', $clave->id)->value('privada_jwk');

        $this->assertStringNotContainsString('PRIVATE KEY', $crudo);
        $this->assertStringContainsString('PRIVATE KEY', $clave->privada_jwk);
    }
}
