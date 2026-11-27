<?php

namespace Tests\Feature;

use App\Evaluacion\ClavesDictado;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\CuentaGoogle;
use App\Models\Entrega;
use App\Models\EnvioClassroom;
use App\Models\Estudiante;
use App\Models\User;
use App\Notas\RegistroNotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Integración con Google Classroom (decisión pendiente 4), contra una API simulada con las formas de la real. */
class ClassroomTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Estudiante $ana;

    private Estudiante $beto;

    private Estudiante $carla;

    /** Lo que la API simulada tiene: entregas por tarea, archivos de Drive y parches recibidos. */
    private array $entregas = [];

    private array $archivos = [];

    private array $parches = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.google' => ['client_id' => 'cliente.apps.googleusercontent.com', 'client_secret' => 'secreto', 'redirect' => null]]);
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->ana = $this->crearEstudiante('101', 'ARIAS', 'ANA');
        $this->beto = $this->crearEstudiante('102', 'BELTRAN', 'BETO');
        $this->carla = $this->crearEstudiante('103', 'CASTRO', 'CARLA');
        $this->ana->update(['email' => 'ana@ejemplo.edu.co']);
        $this->beto->update(['email' => 'Beto@Ejemplo.edu.co']);
        CuentaGoogle::query()->create(['user_id' => $this->docente->id, 'email' => 'docente@ejemplo.edu.co', 'access_token' => 'tok-1', 'refresh_token' => 'ref-1', 'expira_en' => now()->addHour()]);
        $this->falsoClassroom();
    }

    private function falsoClassroom(): void
    {
        Http::fake(function (Request $r) {
            $u = parse_url($r->url());
            $p = $u['path'];
            if (str_contains($r->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'tok-nuevo', 'expires_in' => 3600, 'refresh_token' => 'ref-nuevo']);
            }
            if (str_contains($r->url(), 'openidconnect.googleapis.com')) {
                return Http::response(['email' => 'docente@ejemplo.edu.co']);
            }
            if ($p === '/v1/courses') {
                return Http::response(['courses' => [['id' => 'C1', 'name' => 'Estructura de Datos', 'section' => 'Diurno']]]);
            }
            if ($p === '/v1/courses/C1/students') {
                return isset(parse_str($u['query'] ?? '', $q)['pageToken']) || ! empty($q['pageToken'])
                    ? Http::response(['students' => [['userId' => 'U-BETO', 'profile' => ['emailAddress' => 'beto@ejemplo.edu.co', 'name' => ['fullName' => 'Beto Beltrán']]],
                        ['userId' => 'U-ZOE', 'profile' => ['emailAddress' => 'zoe@gmail.com', 'name' => ['fullName' => 'Zoe']]]]])
                    : Http::response(['students' => [['userId' => 'U-ANA', 'profile' => ['emailAddress' => 'ANA@ejemplo.edu.co', 'name' => ['fullName' => 'Ana Arias']]]], 'nextPageToken' => 'p2']);
            }
            if ($p === '/v1/courses/C1/courseWork' && $r->method() === 'GET') {
                return Http::response(['courseWork' => [['id' => 'T-MANO', 'title' => 'Taller hecho en Classroom', 'maxPoints' => 100, 'associatedWithDeveloper' => false]]]);
            }
            if ($p === '/v1/courses/C1/courseWork' && $r->method() === 'POST') {
                return Http::response(['id' => 'T-APP', 'title' => $r['title'], 'maxPoints' => $r['maxPoints'], 'associatedWithDeveloper' => true, 'alternateLink' => 'https://classroom.google.com/x']);
            }
            if (preg_match('#^/v1/courses/C1/courseWork/([\w-]+)/studentSubmissions$#', $p, $m)) {
                return Http::response(['studentSubmissions' => $this->entregas[$m[1]] ?? []]);
            }
            if (preg_match('#^/v1/courses/C1/courseWork/([\w-]+)/studentSubmissions/([\w-]+)$#', $p, $m) && $r->method() === 'PATCH') {
                if ($m[1] !== 'T-APP') {
                    return Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The Developer Console project is not permitted to make this request.']], 403);
                }
                $this->parches[] = ['entrega' => $m[2], 'mascara' => $u['query'] ?? '', 'datos' => $r->data()];

                return Http::response(['id' => $m[2]]);
            }
            if (preg_match('#^/drive/v3/files/([\w-]+)$#', $p, $m)) {
                return Http::response($this->archivos[$m[1]] ?? '', 200, ['Content-Type' => str_starts_with($this->archivos[$m[1]] ?? '', '%PDF') ? 'application/pdf' : 'application/json']);
            }

            return Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'no']], 404);
        });
    }

    private function sobre(Actividad $a, Estudiante $e): string
    {
        $item = $a->artefacto->items()->first();
        $carga = ['sigla' => $a->artefacto->sigla, 'artefacto' => $a->artefacto->uuid, 'documento' => $e->documento, 'nombre' => $e->nombreCompleto(), 'integrantes' => [],
            'inicio' => now()->subMinutes(30)->toIso8601String(), 'fin' => now()->toIso8601String(), 'cierre' => 'manual', 'respuestas' => [$item->id => true], 'sesion' => ['salidas' => []]];

        return json_encode(['formato' => 'plataforma-entrega', 'version' => 1, 'sigla' => $a->artefacto->sigla, 'artefacto' => $a->artefacto->uuid, 'documento' => $e->documento,
            'cifrado' => app(ClavesDictado::class)->cifrar($this->dictado, json_encode($carga))]);
    }

    private function vincular(): void
    {
        $this->post(route('classroom.grupo.vincular', $this->grupo), ['curso' => 'C1'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function conecta_con_oauth_y_guarda_los_tokens_cifrados(): void
    {
        CuentaGoogle::query()->delete();
        $r = $this->get(route('classroom.conectar'))->assertRedirect();
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('classroom.coursework.students', $q['scope']);

        $this->get(route('classroom.callback', ['state' => 'otro', 'code' => 'x']))->assertSessionHasErrors('classroom');
        $this->get(route('classroom.conectar'));
        $this->get(route('classroom.callback', ['state' => session('classroom.estado'), 'code' => 'codigo']))->assertSessionHas('estado', 'Cuenta de Google conectada: docente@ejemplo.edu.co.');
        $this->assertSame('tok-nuevo', CuentaGoogle::query()->sole()->access_token);
        $this->assertStringNotContainsString('tok-nuevo', (string) DB::table('google_cuentas')->value('access_token'), 'Cifrado en la base');

        // Vencido: se renueva solo
        CuentaGoogle::query()->update(['expira_en' => now()->subMinute()]);
        $this->get(route('classroom.grupo', $this->grupo))->assertOk()->assertSee('Estructura de Datos · Diurno');
        Http::assertSent(fn (Request $x) => str_contains($x->url(), 'oauth2.googleapis.com/token') && $x['grant_type'] === 'refresh_token');
    }

    #[Test]
    public function cruza_por_correo_y_reporta_lo_que_no_cruzo(): void
    {
        $this->vincular();
        $c = session('cruce');
        $this->assertSame(2, $c['cruzados']);
        $this->assertSame(['CASTRO, CARLA'], $c['sin_correo']);
        $this->assertSame(['Zoe (zoe@gmail.com)'], $c['solo_en_classroom']);
        $this->assertSame(['U-ANA', 'U-BETO'], [$this->ana->fresh()->classroom_usuario_id, $this->beto->fresh()->classroom_usuario_id]);
        $this->assertSame('Estructura de Datos Diurno', $this->grupo->fresh()->classroom_curso_nombre);
    }

    #[Test]
    public function trae_las_entregas_del_examen_y_detecta_lo_raro(): void
    {
        $this->vincular();
        [$art] = $this->crearArtefacto([['vf', ['correcta' => true], '5']]);
        $a = $this->crearActividad($this->parcial, ['artefacto_id' => $art->id, 'nombre' => 'Parcial 1']);
        $this->post(route('classroom.actividad.vincular', $a), ['tarea' => 'T-MANO'])->assertSessionHas('estado', 'Tarea vinculada.');

        $this->archivos = ['F-ANA' => $this->sobre($a, $this->ana), 'F-BETO' => $this->sobre($a, $this->ana), 'F-FOTO' => "\x89PNG captura"];
        $this->entregas['T-MANO'] = [
            ['id' => 'S1', 'userId' => 'U-ANA', 'state' => 'TURNED_IN', 'late' => true, 'assignmentSubmission' => ['attachments' => [['driveFile' => ['id' => 'F-ANA', 'title' => 'ED_101.json']]]]],
            // Beto subió el archivo de Ana (otra copia): el examen dice que es de Ana
            ['id' => 'S2', 'userId' => 'U-BETO', 'state' => 'TURNED_IN', 'assignmentSubmission' => ['attachments' => [['driveFile' => ['id' => 'F-FOTO', 'title' => 'captura.png']]]]],
            ['id' => 'S3', 'userId' => 'U-ZOE', 'state' => 'CREATED'],
        ];
        $this->post(route('classroom.actividad.importar', $a))->assertSessionHas('resultados');
        $r = collect(session('resultados'));
        $this->assertSame(['procesada', 'rechazada'], $r->pluck('estado')->all());
        $this->assertStringContainsString('ARIAS, ANA', $r[0]['mensaje']);
        $this->assertStringContainsString('no es el archivo .json del examen', $r[1]['mensaje']);
        $this->assertSame('S1', Entrega::query()->sole()->classroom_entrega_id);
        $this->assertTrue(Alerta::query()->where('tipo', 'entrega_tardia')->exists());

        // Volver a traer no duplica; el archivo de Ana subido por Beto entra con alerta de autor distinto
        $this->entregas['T-MANO'][1]['assignmentSubmission']['attachments'][0] = ['driveFile' => ['id' => 'F-BETO', 'title' => 'ED_101.json']];
        $this->archivos['F-BETO'] .= ' ';                        // otra copia, otro hash
        $this->post(route('classroom.actividad.importar', $a));
        $this->assertSame('duplicada', session('resultados')[0]['estado']);
        $alerta = Alerta::query()->where('tipo', 'autor_distinto')->sole();
        $this->assertSame($this->beto->id, $alerta->estudiante_id);
    }

    #[Test]
    public function trae_los_trabajos_en_pdf_con_su_autor(): void
    {
        $this->vincular();
        $a = $this->crearActividad(null, ['nombre' => 'Taller de listas']);
        $this->post(route('classroom.actividad.vincular', $a), ['tarea' => 'T-MANO']);
        $this->archivos = ['F1' => "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%%EOF", 'F2' => 'notas sueltas'];
        $this->entregas['T-MANO'] = [['id' => 'S1', 'userId' => 'U-BETO', 'state' => 'RETURNED', 'assignmentSubmission' => ['attachments' => [
            ['driveFile' => ['id' => 'F2', 'title' => 'notas.txt']], ['driveFile' => ['id' => 'F1', 'title' => 'informe.pdf']]]]]];
        $this->post(route('classroom.actividad.importar', $a));
        $e = Entrega::query()->sole();
        $this->assertSame(['archivo', 'informe.pdf', [$this->beto->id]], [$e->tipo, $e->archivo_nombre, $e->integrantes->pluck('id')->all()]);
        $this->assertStringContainsString('se tomó este', session('resultados')[0]['mensaje']);
    }

    #[Test]
    public function solo_envia_notas_a_tareas_creadas_por_la_aplicacion_y_como_borrador(): void
    {
        $this->vincular();
        $a = $this->crearActividad(null, ['nombre' => 'Quiz de pilas']);
        $a->grupos()->attach($this->grupo->id);
        $registro = app(RegistroNotas::class);
        $registro->operacion(fn () => $registro->asignar($a, $this->ana, '4.2', 'digitada'));
        $registro->operacion(fn () => $registro->asignar($a, $this->beto, '3', 'digitada'));

        // Tarea hecha a mano en Classroom: se puede leer, no calificar
        $this->post(route('classroom.actividad.vincular', $a), ['tarea' => 'T-MANO']);
        $this->entregas['T-MANO'] = [['id' => 'S1', 'userId' => 'U-ANA', 'state' => 'TURNED_IN']];
        $this->get(route('classroom.actividad', $a))->assertSee('no deja que otra aplicación le ponga notas')->assertDontSee('Enviar las notas');
        $this->post(route('classroom.actividad.enviar', $a))->assertSessionHasErrors('classroom');

        // Creada desde la plataforma: con los puntos de la escala
        $this->post(route('classroom.actividad.vincular', $a), ['tarea' => 'nueva', 'descripcion' => 'Resuelva el quiz.'])->assertSessionHas('estado');
        Http::assertSent(fn (Request $x) => $x->method() === 'POST' && str_ends_with(parse_url($x->url(), PHP_URL_PATH), '/courseWork') && $x['maxPoints'] == 5 && $x['state'] === 'DRAFT');
        $this->entregas['T-APP'] = [['id' => 'SA', 'userId' => 'U-ANA', 'state' => 'TURNED_IN'], ['id' => 'SB', 'userId' => 'U-BETO', 'state' => 'TURNED_IN', 'draftGrade' => 3]];

        $this->get(route('classroom.actividad', $a))->assertOk()->assertSee('Enviar las notas')->assertSee('4.2');
        $this->post(route('classroom.actividad.enviar', $a))->assertSessionHas('estado', '1 nota(s) enviada(s) a Classroom como borrador; 1 ya tenían esa nota; 1 sin nota o sin entrega en Classroom.');
        $this->assertSame([['entrega' => 'SA', 'mascara' => 'updateMask=draftGrade', 'datos' => ['draftGrade' => 4.2]]], $this->parches);
        $this->assertSame(['borrador', $this->docente->id], [EnvioClassroom::query()->sole()->como, EnvioClassroom::query()->sole()->user_id]);

        $this->post(route('classroom.actividad.enviar', $a), ['asignar' => '1']);
        $this->assertSame('updateMask=draftGrade,assignedGrade', end($this->parches)['mascara']);
    }

    #[Test]
    public function sin_credenciales_explica_y_solo_el_docente_ve_sus_grupos(): void
    {
        config(['services.google.client_id' => null]);
        $this->get(route('classroom.index'))->assertOk()->assertSee('docs/classroom.md');
        $this->get(route('classroom.conectar'))->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get(route('classroom.grupo', $this->grupo))->assertForbidden();
        $this->post(route('classroom.actividad.importar', $this->crearActividad()))->assertForbidden();
    }
}
