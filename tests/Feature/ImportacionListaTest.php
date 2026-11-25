<?php

namespace Tests\Feature;

use App\Estudiantes\ImportadorListaClase;
use App\Models\Estudiante;
use App\Models\User;
use App\Notas\RegistroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Cargue masivo con la plantilla oficial (3.4). */
class ImportacionListaTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
    }

    private function csv(string $contenido, string $nombre = 'lista.csv'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, 'text/csv', null, true);
    }

    private const LISTA = "numero_documento,nombre_alumno,email,numero_matriculas,telefono,numero_convocatorias,observacion\n"
        ."1001,\"GOMEZ PEREZ, ANA MARIA\",ana@ejemplo.edu.co,1,300,1,\n"
        ."1002,\"RUIZ, JUAN\",correo-malo,3,,1,Repitente\n"
        ."1003,SIN COMA NOMBRE,,1,,1,\n"
        ."1002,\"DUPLICADO, X\",,1,,1,\n"
        .",\"SIN DOC, Y\",,1,,1,\n";

    #[Test]
    public function la_vista_previa_valida_sin_importar_nada(): void
    {
        $r = $this->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv(self::LISTA)]);

        $r->assertOk()
            ->assertSee('Documento repetido en el archivo')
            ->assertSee('Documento vacío')
            ->assertSee('Correo mal formado')
            ->assertSee('no trae coma')
            ->assertSee('3 veces');
        $this->assertSame(3, $r->viewData('resumen')['nuevos']);
        $this->assertSame(2, $r->viewData('resumen')['con_errores']);
        $this->assertSame(0, Estudiante::query()->count(), 'Nada se importa antes de confirmar.');
    }

    #[Test]
    public function confirmar_importa_las_filas_validas_y_parte_el_nombre_por_la_coma(): void
    {
        $token = $this->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv(self::LISTA)])->viewData('token');

        $this->post(route('importacion.confirmar', $this->grupo), ['token' => $token])
            ->assertOk()->assertSee('3 estudiantes creados');

        $ana = Estudiante::query()->where('documento', '1001')->sole();
        $this->assertSame(['GOMEZ PEREZ', 'ANA MARIA'], [$ana->apellidos, $ana->nombres]);
        $sinComa = Estudiante::query()->where('documento', '1003')->sole();
        $this->assertTrue($sinComa->revisar_nombre);
        $this->assertSame('SIN COMA NOMBRE', $sinComa->apellidos);
        $this->assertNull(Estudiante::query()->where('documento', '1002')->value('email'));
        $this->assertSame(3, $this->grupo->estudiantes()->count());

        // El token se consume: no se puede confirmar dos veces
        $this->post(route('importacion.confirmar', $this->grupo), ['token' => $token])->assertRedirect(route('importacion.create', $this->grupo));
    }

    #[Test]
    public function reimportar_no_duplica_conserva_notas_y_lista_ausentes_sin_borrarlos(): void
    {
        $token = $this->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv(self::LISTA)])->viewData('token');
        $this->post(route('importacion.confirmar', $this->grupo), ['token' => $token]);

        $actividad = $this->crearActividad();
        $ana = Estudiante::query()->where('documento', '1001')->sole();
        app(RegistroNotas::class)->asignar($actividad, $ana, '4.5', 'digitada');

        $nueva = "nombre_alumno,numero_documento\n\"GOMEZ PEREZ, ANA MARIA\",1001\n\"NUEVO, PEDRO\",1004\n";
        $r = $this->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv($nueva)]);
        $r->assertSee('ya no aparecen')->assertSee('RUIZ, JUAN');
        $this->assertSame(['nuevos' => 1, 'existentes' => 1, 'ausentes' => 2],
            array_intersect_key($r->viewData('resumen'), array_flip(['nuevos', 'existentes', 'ausentes'])));

        $this->post(route('importacion.confirmar', $this->grupo), ['token' => $r->viewData('token')]);

        $this->assertSame(4, Estudiante::query()->count());
        $this->assertSame('4.5000', $ana->notas()->value('valor'));
    }

    #[Test]
    public function acepta_csv_de_excel_en_espanol_con_punto_y_coma_y_windows_1252(): void
    {
        $contenido = mb_convert_encoding("nombre_alumno;numero_documento\nNUÑEZ OCHOA, JOSÉ;2001\n", 'Windows-1252', 'UTF-8');

        $token = $this->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv($contenido)])->viewData('token');
        $this->post(route('importacion.confirmar', $this->grupo), ['token' => $token]);

        $e = Estudiante::query()->sole();
        $this->assertSame(['NUÑEZ OCHOA', 'JOSÉ'], [$e->apellidos, $e->nombres]);
    }

    #[Test]
    public function rechaza_un_archivo_que_no_es_la_plantilla(): void
    {
        $this->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv("nombre,cedula\nA,1\n")])
            ->assertSessionHasErrors(['archivo' => 'El archivo no tiene el formato de la plantilla oficial. Faltan las columnas: nombre_alumno, numero_documento.']);
    }

    #[Test]
    public function la_plantilla_descargable_se_puede_volver_a_cargar(): void
    {
        $r = $this->get(route('importacion.plantilla'));
        $r->assertOk()->assertDownload('plantilla_lista_clase.xlsx');

        $ruta = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
        app(ImportadorListaClase::class)->plantilla($ruta);
        $filas = app(ImportadorListaClase::class)->leer($ruta, 'xlsx');

        $this->assertSame('1098765432', $filas[0]['numero_documento']);
        $this->assertSame(ImportadorListaClase::COLUMNAS, array_keys($filas[0]));
    }

    #[Test]
    public function otro_docente_no_puede_importar_en_este_grupo(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('importacion.previa', $this->grupo), ['archivo' => $this->csv(self::LISTA)])
            ->assertForbidden();
    }
}
