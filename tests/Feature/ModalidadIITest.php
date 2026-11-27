<?php

namespace Tests\Feature;

use App\Models\Actividad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** 6.2.3: en la modalidad II la práctica se entrega aparte y solo se registra su nota, en su propia columna. */
class ModalidadIITest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    #[Test]
    public function la_columna_de_la_practica_se_crea_una_vez_junto_al_examen(): void
    {
        $this->crearEscenario(estudiantes: 1);
        [$artefacto] = $this->crearArtefacto([['vf', ['correcta' => true], '5']]);
        $artefacto->update(['configuracion' => ['anexo' => true] + $artefacto->configuracion]);
        $examen = $this->crearActividad($this->parcial, ['artefacto_id' => $artefacto->id, 'nombre' => 'Parcial 1', 'orden' => 0]);
        $examen->grupos()->attach($this->grupo->id);
        $this->actingAs($this->docente);

        $this->get(route('calificador.show', $examen))->assertOk()->assertSee('crear la columna de la práctica en el registro');
        $this->post(route('calificador.practica', $examen))->assertRedirect(route('registro', $this->grupo))
            ->assertSessionHas('estado', 'Columna «Práctica — Parcial 1» lista en «Parcial»: digite ahí la nota de la práctica y su comentario.');
        $this->post(route('calificador.practica', $examen));

        $practica = Actividad::query()->where('practica_de_id', $examen->id)->sole();
        $this->assertSame([$this->parcial->id, '5.0000', [$this->grupo->id]], [$practica->componente_id, $practica->valor_max, $practica->grupos()->pluck('grupos.id')->all()]);
        $this->get(route('calificador.show', $examen))->assertSee('su nota va en la columna');
        $this->get(route('registro', $this->grupo))->assertOk()->assertSee('Práctica — Parcial 1');
    }
}
