<?php

namespace Tests\Feature;

use App\Calificacion\Similitud;
use App\Evaluacion\Rubricas;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Materia;
use App\Models\Nota;
use App\Models\Rubrica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Trabajos entregados como archivo, calificados a mano o con rúbrica (5.7 formato de entrega). */
class EntregasArchivoTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Actividad $actividad;

    private Estudiante $ana;

    private Estudiante $beto;

    private Estudiante $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('1098765432', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('1098765433', 'BELTRAN', 'BETO');
        $this->carla = $this->crearEstudiante('1098765434', 'CASTRO', 'CARLA');
        $this->actividad = $this->crearActividad(null, ['nombre' => 'Taller de listas']);
    }

    private function pdf(string $nombre, int $paginas = 2, string $extra = ''): UploadedFile
    {
        $cuerpo = "%PDF-1.4\n1 0 obj << /Type /Pages /Count {$paginas} >> endobj\n";
        for ($i = 0; $i < $paginas; $i++) {
            $cuerpo .= ($i + 2)." 0 obj << /Type /Page /Parent 1 0 R >> endobj\n";
        }

        return UploadedFile::fake()->createWithContent($nombre, $cuerpo.$extra."%%EOF\n");
    }

    private function rubrica(): Rubrica
    {
        $niveles = [['nombre' => 'Excelente', 'descripcion' => 'Completo y correcto', 'porcentaje' => '100'],
            ['nombre' => 'Básico', 'descripcion' => 'Con errores menores', 'porcentaje' => '50'],
            ['nombre' => 'Insuficiente', 'descripcion' => 'No lo resuelve', 'porcentaje' => '0']];

        return Rubrica::query()->create(['materia_id' => $this->materia->id] + app(Rubricas::class)->normalizar(['titulo' => 'Rúbrica del taller', 'proposito' => '', 'criterios' => [
            ['nombre' => 'Implementación', 'descripcion' => '', 'peso' => '60', 'niveles' => $niveles],
            ['nombre' => 'Documentación', 'descripcion' => '', 'peso' => '40', 'niveles' => $niveles],
        ]]));
    }

    #[Test]
    public function con_grupos_del_docente_el_archivo_de_uno_queda_a_nombre_de_su_grupo(): void
    {
        // 6.8: el taller va por defecto con el estudiante escogiendo; el docente fuerza sus grupos
        $this->actividad->update(['es_grupal' => true]);
        $this->put(route('calificador.grupos.guardar', $this->actividad), ['grupos_por' => 'docente',
            'numero' => [$this->ana->id => 1, $this->carla->id => 1, $this->beto->id => 2]])->assertSessionHasNoErrors();

        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [$this->pdf('1098765432_taller.pdf')]]);

        $e = Entrega::query()->sole();
        $this->assertSame($this->ana->id, $e->subida_por_id);
        $this->assertEqualsCanonicalizing([$this->ana->id, $this->carla->id], $e->integrantes()->pluck('estudiantes.id')->all());
    }

    #[Test]
    public function avisa_si_el_mismo_archivo_ya_esta_en_otra_actividad_del_periodo(): void
    {
        // 6.1.4: el consolidado del laboratorio subido a la jornada equivocada
        $otra = $this->crearActividad(null, ['nombre' => 'Taller de pilas']);
        $this->post(route('calificador.archivos.subir', $otra), ['archivos' => [$this->pdf('1098765432_taller.pdf', 3)]]);
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [$this->pdf('1098765432_taller.pdf', 3)]])
            ->assertSessionHas('resultados', fn ($r) => str_contains($r[0]['mensaje'], 'este mismo archivo ya está en «Taller de pilas». ¿Se subió a la actividad equivocada?'));
        $alerta = Alerta::query()->where('tipo', 'otra_actividad')->sole();
        $this->assertSame([$this->actividad->id, $this->ana->id], [$alerta->actividad_id, $alerta->estudiante_id]);
    }

    #[Test]
    public function la_similitud_compara_el_texto_de_los_archivos_entregados(): void
    {
        // 6.11.1: no solo archivos idénticos; también un trabajo copiado con cambios
        $this->put(route('calificador.archivos.configurar', $this->actividad), ['formato_entrega' => 'libre'])->assertSessionHasNoErrors();
        $informe = "Informe del taller de listas enlazadas.\nUna lista enlazada guarda cada nodo con un puntero al siguiente; insertar al inicio cuesta O(1) "
            .'porque solo cambia la cabeza, y buscar cuesta O(n) porque hay que recorrer desde el primero hasta encontrar el valor. '
            .'En el taller medimos la inserción de mil nodos y comparamos con un arreglo que debe correr sus elementos.';
        $archivo = fn ($nombre, $texto) => UploadedFile::fake()->createWithContent($nombre, $texto);
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [
            $archivo('1098765432_informe.txt', $informe),
            $archivo('1098765433_informe.txt', str_replace(['Informe del taller', 'mil nodos'], ['Trabajo del taller', 'mil elementos'], $informe)),
            $archivo('1098765434_informe.txt', 'Las pilas y las colas se diferencian en el orden de salida; una pila atiende primero lo último que llegó y una cola lo primero, como en la fila del banco del centro comercial.'),
        ]]);
        $this->assertNotNull(Entrega::query()->whereHas('integrantes', fn ($q) => $q->whereKey($this->ana->id))->value('texto_extraido'));

        $pares = app(Similitud::class)->pares($this->actividad);
        $this->assertCount(1, $pares, 'Solo el par copiado, no el trabajo distinto');
        $this->assertSame([$this->ana->id, $this->beto->id], [$pares[0]['a']->integrantes->first()->id, $pares[0]['b']->integrantes->first()->id]);
        $this->assertSame('archivo', $pares[0]['items'][0]['tipo']);
        $this->get(route('calificador.similitud', $this->actividad))->assertOk()->assertSee('texto del archivo')->assertSee('Entregas de archivo');
        $this->get(route('calificador.similitud.ver', [$this->actividad, $pares[0]['a'], $pares[0]['b']]))->assertOk()->assertSee('Archivo entregado');
    }

    #[Test]
    public function reconoce_al_estudiante_por_el_documento_y_rechaza_lo_que_no_es_pdf(): void
    {
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [
            $this->pdf('1098765432_taller.pdf', 3),
            $this->pdf('informe final.pdf'),
            UploadedFile::fake()->image('1098765433_captura.png'),
        ]])->assertRedirect()->assertSessionHas('resultados', fn ($r) => array_column($r, 'estado') === ['recibida', 'sin_identificar', 'rechazada']);

        $ana = Entrega::query()->where('archivo_nombre', '1098765432_taller.pdf')->sole();
        $this->assertSame('archivo', $ana->tipo);
        $this->assertSame(3, $ana->paginas);
        $this->assertSame([$this->ana->id], $ana->integrantes->pluck('id')->all());
        Storage::disk('local')->assertExists($ana->ruta);
        $this->assertNull(Entrega::query()->where('archivo_nombre', 'informe final.pdf')->sole()->subida_por_id);

        // El mismo archivo otra vez no duplica
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [$this->pdf('1098765432_taller.pdf', 3)]])
            ->assertSessionHas('resultados', fn ($r) => $r[0]['estado'] === 'duplicada');

        // En formato libre se acepta cualquier archivo, y se descarga en vez de mostrarse
        $this->put(route('calificador.archivos.configurar', $this->actividad), ['formato_entrega' => 'libre'])->assertSessionHasNoErrors();
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [UploadedFile::fake()->createWithContent('1098765433_proyecto.zip', "PK\x03\x04 zip")]]);
        $zip = Entrega::query()->where('archivo_nombre', '1098765433_proyecto.zip')->sole();
        $this->assertSame([$this->beto->id], $zip->integrantes->pluck('id')->all());
        $this->assertStringContainsString('attachment', $this->get(route('calificador.archivos.archivo', $zip))->assertOk()->headers->get('Content-Disposition'));
        $this->assertStringContainsString('inline', $this->get(route('calificador.archivos.archivo', $ana))->headers->get('Content-Disposition'));

        $this->get(route('calificador.archivos', $this->actividad))->assertOk()
            ->assertSee('ARIAS, ANA')->assertSee('Sin identificar')->assertSee('CASTRO, CARLA');
    }

    #[Test]
    public function la_nota_escrita_a_mano_con_su_comentario_pasa_al_registro(): void
    {
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [$this->pdf('informe.pdf')]]);
        $e = Entrega::query()->sole();
        $this->get(route('calificador.archivos.calificar', $e))->assertOk()->assertSee('Entrega sin identificar')->assertSee('Nota (0 a 5)');

        $this->put(route('calificador.archivos.guardar', $e), ['integrantes' => [$this->beto->id], 'puntaje' => '6'])
            ->assertSessionHasErrors('puntaje');
        $this->put(route('calificador.archivos.guardar', $e), ['integrantes' => [$this->beto->id], 'puntaje' => '4,2', 'comentario' => 'Buen análisis; faltó la complejidad.'])
            ->assertRedirect(route('calificador.archivos.calificar', $e));

        $e->refresh();
        $this->assertSame('4.2000', $e->puntaje);
        $this->assertSame($this->beto->id, $e->subida_por_id);

        $this->post(route('calificador.registro', $this->actividad))->assertSessionHas('estado', '1 entregas pasadas al registro.');
        $nota = Nota::query()->where('estudiante_id', $this->beto->id)->sole();
        $this->assertEquals(4.2, (float) $nota->valor);
        $this->assertSame('Buen análisis; faltó la complejidad.', $nota->comentario);
        $this->assertSame($e->id, $nota->entrega_id);

        // Ya registrada no se elimina
        $this->delete(route('calificador.archivos.eliminar', $e))->assertStatus(422);
    }

    #[Test]
    public function la_rubrica_calcula_la_nota_y_la_entrega_grupal_respeta_el_tope(): void
    {
        $rub = $this->rubrica();
        $this->put(route('calificador.archivos.configurar', $this->actividad), [
            'formato_entrega' => 'pdf', 'rubrica_id' => $rub->id, 'es_grupal' => '1', 'tamano_grupo' => 2, 'tope_exceso' => '3.5',
        ])->assertSessionHasNoErrors();
        $this->assertSame($rub->id, $this->actividad->fresh()->rubrica_id);

        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [$this->pdf('1098765432-1098765433-1098765434.pdf')]]);
        $e = Entrega::query()->sole();
        $this->assertCount(3, $e->integrantes);
        $this->get(route('calificador.archivos.calificar', $e))->assertOk()->assertSee('Implementación')->assertSee('Con errores menores');

        // Falta un criterio: queda pendiente y no pasa
        $this->put(route('calificador.archivos.guardar', $e), ['integrantes' => $e->integrantes->pluck('id')->all(), 'nivel' => [0 => 0]]);
        $this->assertNull($e->fresh()->puntaje);
        $this->post(route('calificador.registro', $this->actividad))->assertSessionHas('estado', '0 entregas pasadas al registro; 1 siguen con ítems por calificar.');

        // Excelente (60 %) y Básico (40 % × 50 %): 5 × 0.8 = 4
        $this->put(route('calificador.archivos.guardar', $e), ['integrantes' => $e->integrantes->pluck('id')->all(), 'nivel' => [0, 1], 'comentario' => 'Documenten las funciones.']);
        $e->refresh();
        $this->assertSame('4.0000', $e->puntaje);
        $this->assertSame('Básico', $e->desglose['rubrica']['criterios'][1]['nivel']);

        // Tres integrantes en un grupo de dos: la nota queda topada en 3.5
        $this->post(route('calificador.registro', $this->actividad));
        $this->assertSame([3.5], Nota::query()->pluck('valor')->map(fn ($v) => (float) $v)->unique()->values()->all());
        $this->assertSame(3, Nota::query()->count());

        // El informe del estudiante muestra la rúbrica y el comentario, no el archivo
        $html = $this->get(route('calificador.devolucion.ver', [$this->actividad, $this->ana]))->assertOk()->getContent();
        $this->assertStringContainsString('Calificada con la rúbrica «Rúbrica del taller»', $html);
        $this->assertStringContainsString('Documenten las funciones.', $html);
        $this->assertStringNotContainsString(route('calificador.archivos.archivo', $e), $html);
    }

    #[Test]
    public function solo_el_docente_de_la_materia_ve_y_califica_los_archivos(): void
    {
        $this->post(route('calificador.archivos.subir', $this->actividad), ['archivos' => [$this->pdf('1098765432_taller.pdf')]]);
        $e = Entrega::query()->sole();

        $this->actingAs(User::factory()->create());
        $this->get(route('calificador.archivos', $this->actividad))->assertForbidden();
        $this->get(route('calificador.archivos.archivo', $e))->assertForbidden();
        $this->put(route('calificador.archivos.guardar', $e), ['puntaje' => '5'])->assertForbidden();
        $this->assertNull($e->fresh()->puntaje);

        // Una rúbrica de otra materia no se puede asociar
        $this->actingAs($this->docente);
        $otra = Rubrica::query()->create(['materia_id' => $this->crearOtraMateria()->id, 'titulo' => 'Ajena', 'criterios' => []]);
        $this->put(route('calificador.archivos.configurar', $this->actividad), ['formato_entrega' => 'pdf', 'rubrica_id' => $otra->id])->assertSessionHasErrors('rubrica_id');

        // Integrantes que no son del periodo se ignoran
        $this->put(route('calificador.archivos.guardar', $e), ['integrantes' => [$this->ana->id, 99999], 'puntaje' => '3']);
        $this->assertSame([$this->ana->id], $e->fresh()->integrantes->pluck('id')->all());
    }

    private function crearOtraMateria(): Materia
    {
        return Materia::query()->create(['user_id' => User::factory()->create()->id, 'nombre' => 'Ajena']);
    }
}
