<?php

namespace Tests\Feature;

use App\IA\ConstructorPrompt;
use App\IA\PerfilEscritura;
use App\Models\Nota;
use App\Models\Respuesta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 8.5.3: perfil de escritura del docente con muestras de sus propios comentarios, solo si lo activa. */
class PerfilEscrituraTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private const PROPIO = 'Bien la idea de la pila; le faltó decir qué pasa cuando está vacía, revíselo con el ejemplo de clase.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 2);
        $this->actingAs($this->docente);
        [$a, $e] = [$this->crearActividad($this->trabajos), $this->grupo->estudiantes()->get()];
        Nota::query()->create(['actividad_id' => $a->id, 'estudiante_id' => $e[0]->id, 'valor' => '4', 'origen' => 'digitada', 'comentario' => self::PROPIO]);
        Nota::query()->create(['actividad_id' => $a->id, 'estudiante_id' => $e[1]->id, 'valor' => '3', 'origen' => 'digitada', 'comentario' => 'Obtuvo 3 de 5 puntos en el taller de colas.']);
        [$art, $items] = $this->crearArtefacto([['abierta', ['referencia' => 'x'], '5']]);
        $examen = $this->crearActividad($this->parcial, ['artefacto_id' => $art->id]);
        $entrega = $this->crearEntrega($examen, $e[0]);
        $ia = 'Muy buen trabajo con la estructura, aunque podría profundizar más en los casos borde del algoritmo.';
        Respuesta::query()->create(['entrega_id' => $entrega->id, 'artefacto_pregunta_id' => $items[0]->id, 'respuesta' => ['valor' => 'r'], 'puntaje_max' => '5',
            'comentario' => $ia, 'comentario_borrador' => $ia]);
    }

    #[Test]
    public function toma_solo_lo_que_escribio_el_docente_y_entra_al_prompt_si_lo_activa(): void
    {
        $p = app(PerfilEscritura::class);
        $this->assertSame([self::PROPIO], $p->candidatas($this->docente)->pluck('texto')->all(), 'ni la propuesta de la IA aceptada tal cual ni la que delata un cálculo');
        $this->assertSame('', $p->bloque($this->docente), 'desactivado no se usa');

        $this->get(route('ia.perfil-escritura'))->assertOk()->assertSee(self::PROPIO);
        $this->put(route('ia.perfil-escritura.guardar'), ['activo' => 1, 'usar' => [PerfilEscritura::huella(self::PROPIO)]])
            ->assertSessionHas('estado', fn ($m) => str_contains($m, 'con 1 muestra'));

        $prompt = app(ConstructorPrompt::class)->construir($this->docente->fresh(), $this->materia, $this->dictado->fresh(), 'comentarios',
            ['pregunta' => 'P', 'referencia' => 'R', 'rubrica' => '—', 'respuestas' => '—'], collect());
        $this->assertStringContainsString('## Cómo escribe este docente', $prompt['sistema']);
        $this->assertStringContainsString(self::PROPIO, $prompt['sistema']);
        $otro = app(ConstructorPrompt::class)->construir($this->docente->fresh(), $this->materia, $this->dictado->fresh(), 'apuntes',
            ['tema' => 'T', 'grupo' => 'G', 'memoria' => '', 'extension' => 'corta', 'nivel' => 'n', 'enfoque' => 'e', 'glosario' => ''], collect());
        $this->assertStringNotContainsString(self::PROPIO, $otro['sistema'], 'solo en el texto que va al estudiante');

        // Quitar una muestra la saca del prompt
        $this->put(route('ia.perfil-escritura.guardar'), ['activo' => 1]);
        $this->assertSame('', app(PerfilEscritura::class)->bloque($this->docente->fresh()));
    }
}
