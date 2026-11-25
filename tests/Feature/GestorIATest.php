<?php

namespace Tests\Feature;

use App\IA\Drivers\OllamaDriver;
use App\IA\GestorIA;
use App\IA\MotorIA;
use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\ResolutorMotor;
use App\IA\RespuestaIA;
use App\IA\SolicitudIA;
use App\Models\ConfigMotorIa as MotorConfig;
use App\Models\GeneracionIa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Reglas del motor de IA configurable (1.5). */
class GestorIATest extends TestCase
{
    use RefreshDatabase;

    private User $docente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docente = User::factory()->create();
    }

    private function motor(string $driver, bool $local): MotorConfig
    {
        return MotorConfig::query()->create([
            'user_id' => $this->docente->id,
            'nombre' => $local ? 'Ollama del laboratorio' : 'Nube',
            'driver' => $driver,
            'base_url' => $local ? 'http://192.168.1.20:11434' : null,
            'modelo' => $local ? 'qwen3:14b' : 'claude-opus-5-5',
            'es_local' => $local,
            'api_key' => $local ? null : 'sk-prueba',
            'predeterminado' => true,
        ]);
    }

    #[Test]
    public function genera_con_ollama_y_registra_la_trazabilidad(): void
    {
        Http::fake([
            '192.168.1.20:11434/api/tags' => Http::response(['models' => []]),
            '192.168.1.20:11434/api/chat' => Http::response([
                'message' => ['content' => '{"preguntas":[]}'],
                'prompt_eval_count' => 120,
                'eval_count' => 30,
            ]),
        ]);
        $this->motor('ollama', true);

        $r = app(GestorIA::class)->generar($this->docente, new SolicitudIA(
            sistema: 'Capa 1 y 2',
            usuario: 'Genere un quiz',
            esquemaJson: ['type' => 'object'],
        ), trazabilidad: ['plantillas' => ['tarea' => 'quiz@v3']]);

        $this->assertSame(['preguntas' => []], $r->json());
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/api/chat') && $req['format'] === ['type' => 'object']);
        $g = GeneracionIa::query()->sole();
        $this->assertSame(120, $g->tokens_entrada);
        $this->assertSame(['tarea' => 'quiz@v3'], $g->plantillas);
    }

    #[Test]
    public function si_el_motor_local_esta_caido_avisa_y_no_llama_a_ningun_otro(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);
        $this->motor('ollama', true);

        try {
            app(GestorIA::class)->generar($this->docente, new SolicitudIA('s', 'u'));
            $this->fail('Debió lanzar MotorNoDisponible.');
        } catch (MotorNoDisponible $e) {
            $this->assertStringContainsString('No se usó ningún otro motor', $e->getMessage());
        }

        Http::assertSentCount(1);                                    // solo el ping a /api/tags
        Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), '192.168.1.20'));
        $this->assertSame(0, GeneracionIa::query()->count());
    }

    #[Test]
    public function datos_de_estudiantes_no_van_a_la_nube_sin_autorizacion(): void
    {
        $config = $this->motor('anthropic', false);
        $nube = new class implements MotorIA
        {
            public bool $llamado = false;

            public function generar(SolicitudIA $s): RespuestaIA
            {
                $this->llamado = true;

                return new RespuestaIA('x');
            }

            public function disponible(): bool
            {
                return true;
            }

            public function esLocal(): bool
            {
                return false;
            }
        };
        $this->app->instance(ResolutorMotor::class, new class($nube) extends ResolutorMotor
        {
            public function __construct(private MotorIA $m) {}

            public function para(MotorConfig $config): MotorIA
            {
                return $this->m;
            }
        });

        $solicitud = new SolicitudIA('s', 'Califique estas respuestas abiertas...', contieneDatosEstudiantes: true);

        try {
            app(GestorIA::class)->generar($this->docente, $solicitud, $config);
            $this->fail('Debió lanzar MotorNoPermitido.');
        } catch (MotorNoPermitido) {
            $this->assertFalse($nube->llamado);
        }

        config(['ia.permitir_nube_con_datos_estudiantes' => true]);
        app(GestorIA::class)->generar($this->docente, $solicitud, $config);
        $this->assertTrue($nube->llamado);
    }

    #[Test]
    public function el_driver_compatible_con_openai_pide_json_schema(): void
    {
        Http::fake([
            'localhost:1234/v1/models' => Http::response(['data' => []]),
            'localhost:1234/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"ok":true}']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ]),
        ]);
        MotorConfig::query()->create([
            'user_id' => $this->docente->id, 'nombre' => 'LM Studio', 'driver' => 'openai_compatible',
            'base_url' => 'http://localhost:1234', 'modelo' => 'local-model', 'es_local' => true, 'predeterminado' => true,
        ]);

        $r = app(GestorIA::class)->generar($this->docente, new SolicitudIA('s', 'u', esquemaJson: ['type' => 'object']));

        $this->assertSame(['ok' => true], $r->json());
        Http::assertSent(fn (Request $req) => ($req['response_format']['type'] ?? null) === 'json_schema');
    }

    #[Test]
    public function ollama_sin_conexion_se_reporta_como_no_disponible(): void
    {
        Http::fake(fn () => throw new ConnectionException('rechazada'));

        $this->assertFalse((new OllamaDriver('http://127.0.0.1:11434', 'm'))->disponible());
    }
}
