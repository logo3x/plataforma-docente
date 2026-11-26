<?php

namespace Tests\Feature;

use App\Calificacion\Similitud;
use App\Evaluacion\ImportadorBanco;
use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Dictado;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\Respuesta;
use App\Models\RevisionSimilitud;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Detección de similitud entre entregas (6.11). */
class SimilitudTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private const ORIGINAL = "function invertir(texto) {\n  // recorre de atrás hacia adelante\n  const pila = [];\n  for (const c of texto) pila.push(c);\n  let r = '';\n  while (pila.length > 0) r += pila.pop();\n  return r;\n}";

    /** La misma solución con los nombres cambiados, sin comentarios y con otro espaciado. */
    private const DISFRAZADA = "function voltear(cadena){const st=[];for(const letra of cadena)st.push(letra);let salida='';while(st.length>0)salida+=st.pop();return salida;}";

    private const DISTINTA = "function invertir(texto) {\n  return texto.split('').reverse().join('');\n}";

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        app(ImportadorBanco::class)->importar($this->materia, ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'ED-SIM-P1', 'titulo' => 'Parcial', 'valor' => '5', 'items' => [
            ['tipo' => 'reto', 'peso' => '3', 'enunciado' => 'Escriba invertir(texto) con una pila.', 'plantilla_codigo' => "function invertir(texto) {\n}\n",
                'verificaciones' => [['id' => 'v1', 'nombre' => 'Invierte', 'fraccion' => '1', 'prueba' => 'invertir("ab") === "ba"']]],
            ['tipo' => 'abierta', 'peso' => '2', 'enunciado' => 'Explique cuándo conviene usar una pila en lugar de una cola.'],
        ]]);
        $this->actividad = $this->parcial->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Parcial', 'valor_max' => '5']);
    }

    private function entregar(Actividad $actividad, string $doc, string $codigo, string $texto): Entrega
    {
        $e = Estudiante::query()->where('documento', $doc)->first() ?? $this->crearEstudiante($doc, 'APELLIDO'.$doc, 'NOMBRE'.$doc);
        $entrega = $this->crearEntrega($actividad, $e);
        foreach (Artefacto::query()->sole()->items()->get() as $i => $item) {
            Respuesta::query()->create(['entrega_id' => $entrega->id, 'artefacto_pregunta_id' => $item->id, 'respuesta' => ['valor' => $i === 0 ? $codigo : $texto], 'puntaje_max' => $item->peso]);
        }

        return $entrega;
    }

    #[Test]
    public function renombrar_variables_quitar_comentarios_y_cambiar_espacios_no_esconde_la_copia(): void
    {
        $s = app(Similitud::class);
        $a = array_keys($s->fragmentos(self::ORIGINAL, true));
        $b = array_keys($s->fragmentos(self::DISFRAZADA, true));
        $c = array_keys($s->fragmentos(self::DISTINTA, true));
        $this->assertEquals(1, count(array_intersect($a, $b)) / min(count($a), count($b)));
        $this->assertLessThan(0.3, count(array_intersect($a, $c)) / max(1, min(count($a), count($c))));

        // En texto: mayúsculas, tildes y signos no cuentan
        $t1 = array_keys($s->fragmentos('Conviene usar una pila cuando lo último que llega debe salir primero, como el botón Atrás.', false));
        $t2 = array_keys($s->fragmentos('conviene usar UNA PILA: cuando lo ultimo que llega debe salir primero (como el boton atras)', false));
        $this->assertSame($t1, $t2);
    }

    #[Test]
    public function senala_el_par_copiado_y_no_los_que_resolvieron_parecido_por_su_cuenta(): void
    {
        $texto = 'Una pila sirve cuando lo último que entra es lo primero que sale, por ejemplo para deshacer cambios en un editor de texto o volver atrás en el navegador.';
        $ana = $this->entregar($this->actividad, '101', self::ORIGINAL, $texto);
        $beto = $this->entregar($this->actividad, '102', self::DISFRAZADA, 'Cuando el orden importa al revés: la pila atiende al último. Las colas son para turnos.');
        $carla = $this->entregar($this->actividad, '103', self::DISTINTA, 'Copio parte: '.$texto.' Además las colas atienden por orden de llegada.');
        $this->entregar($this->actividad, '104', "function invertir(texto) {\n}\n", 'No sé.');           // la plantilla sin tocar no es señal

        $pares = app(Similitud::class)->pares($this->actividad);
        $this->assertCount(2, $pares);
        $porPar = $pares->mapWithKeys(fn ($p) => [$p['a']->id.'-'.$p['b']->id => $p]);
        $this->assertSame([1], array_column($porPar["{$ana->id}-{$beto->id}"]['items'], 'numero'), 'Ana y Beto: el código, aunque renombrado');
        $this->assertSame('reto', $porPar["{$ana->id}-{$beto->id}"]['items'][0]['tipo']);
        $this->assertSame([2], array_column($porPar["{$ana->id}-{$carla->id}"]['items'], 'numero'), 'Ana y Carla: el texto, copiado en parte');

        $this->get(route('calificador.similitud', $this->actividad))->assertOk()
            ->assertSee('Es una señal, no un veredicto')->assertSee('NOMBRE101')->assertSee('2 par(es)')
            ->assertSee('Muy alta')->assertSee('código, 100 %');
    }

    #[Test]
    public function lado_a_lado_resalta_lo_que_coincide_y_escapa_el_codigo(): void
    {
        $ana = $this->entregar($this->actividad, '101', self::ORIGINAL.'<script>alert(1)</script>', 'Texto propio de Ana sobre pilas y colas en la práctica diaria.');
        $beto = $this->entregar($this->actividad, '102', self::DISFRAZADA, 'Otro texto distinto escrito por Beto acerca de estructuras.');

        $r = $this->get(route('calificador.similitud.ver', [$this->actividad, $ana, $beto]))->assertOk()
            ->assertSee('Pregunta 1')->assertSee('<mark>', false)->assertSee('sin coincidencia relevante');
        $html = $r->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // El comentario de Ana no está resaltado: no cuenta para la coincidencia
        $this->assertStringContainsString('// recorre de atrás hacia adelante', $html);
        $this->assertMatchesRegularExpression('~</mark>\s*// recorre de atrás hacia adelante\s*<mark>~u', $html);
    }

    #[Test]
    public function la_conclusion_del_docente_queda_registrada_y_ninguna_nota_cambia(): void
    {
        $ana = $this->entregar($this->actividad, '101', self::ORIGINAL, 'a');
        $beto = $this->entregar($this->actividad, '102', self::DISFRAZADA, 'b');
        $notas = Nota::query()->count();

        $this->post(route('calificador.similitud.revisar', [$this->actividad, $ana, $beto]), ['conclusion' => 'posible_copia', 'nota' => 'Hablar con ambos el lunes.'])
            ->assertRedirect(route('calificador.similitud', $this->actividad))->assertSessionHas('estado', fn ($s) => str_contains($s, 'Ninguna nota cambió'));
        $this->assertSame('posible_copia', RevisionSimilitud::query()->sole()->conclusion);
        $this->assertSame($notas, Nota::query()->count());
        $this->get(route('calificador.similitud', $this->actividad))->assertSee('Revisado: Posible copia');
        $this->get(route('calificador.similitud.ver', [$this->actividad, $ana, $beto]))->assertSee('Hablar con ambos el lunes.');
    }

    #[Test]
    public function compara_con_otro_periodo_que_uso_el_mismo_examen_y_otro_docente_no_ve_nada(): void
    {
        $ana = $this->entregar($this->actividad, '101', self::ORIGINAL, 'texto uno de prueba');
        // El mismo examen aplicado en 2026B: alguien entrega la solución heredada
        $otro = Dictado::query()->create(['materia_id' => $this->materia->id, 'periodo' => '2026B']);
        $comp = $otro->divisiones()->create(['nombre' => 'Corte 1', 'peso' => '1', 'orden' => 0])->componentes()->create(['nombre' => 'Parcial', 'peso' => '1', 'orden' => 0]);
        $act2 = $comp->actividades()->create(['artefacto_id' => Artefacto::query()->sole()->id, 'nombre' => 'Parcial', 'valor_max' => '5']);
        $heredada = $this->entregar($act2, '201', self::DISFRAZADA, 'texto dos distinto');

        $this->assertCount(0, app(Similitud::class)->pares($this->actividad));
        $this->get(route('calificador.similitud', $this->actividad))->assertSee('Comparar también con otros periodos');
        $this->get(route('calificador.similitud', ['actividad' => $this->actividad, 'otros' => 1]))->assertSee('de 2026B')->assertSee('NOMBRE201');
        $this->get(route('calificador.similitud.ver', [$this->actividad, $ana, $heredada]))->assertOk()->assertSee('del periodo 2026B');

        $this->actingAs(User::factory()->create());
        $this->get(route('calificador.similitud', $this->actividad))->assertForbidden();
        $this->get(route('calificador.similitud.ver', [$this->actividad, $ana, $heredada]))->assertForbidden();
    }
}
