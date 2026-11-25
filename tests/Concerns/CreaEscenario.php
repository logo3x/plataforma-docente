<?php

namespace Tests\Concerns;

use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\ArtefactoPregunta;
use App\Models\Componente;
use App\Models\Dictado;
use App\Models\Division;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Materia;
use App\Models\Pregunta;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Escenario de prueba: Estructura de Datos 2026A, escala 0–5, cortes 30/30/40,
 * componentes 50/50, grupo Diurno con estudiantes.
 */
trait CreaEscenario
{
    protected User $docente;

    protected Materia $materia;

    protected Dictado $dictado;

    protected Grupo $grupo;

    /** @var array<int, Division> */
    protected array $cortes = [];

    protected Componente $trabajos;

    protected Componente $parcial;

    protected function crearEscenario(int $estudiantes = 4): void
    {
        $this->docente = User::factory()->create();

        $this->materia = Materia::query()->create([
            'user_id' => $this->docente->id,
            'nombre' => 'Estructura de Datos',
            'codigo' => '910303',
            'perfil_codigo' => true,
            'modalidad_practica' => 'integrada',
        ]);

        $this->dictado = Dictado::query()->create([
            'materia_id' => $this->materia->id,
            'periodo' => '2026A',
        ]);

        foreach (['Corte 1' => '0.3', 'Corte 2' => '0.3', 'Corte 3' => '0.4'] as $i => $peso) {
            $this->cortes[] = Division::query()->create([
                'dictado_id' => $this->dictado->id, 'nombre' => $i, 'peso' => $peso, 'orden' => count($this->cortes),
            ]);
        }

        $this->trabajos = Componente::query()->create([
            'division_id' => $this->cortes[0]->id, 'nombre' => 'Trabajos y quices', 'peso' => '0.5', 'orden' => 0,
        ]);
        $this->parcial = Componente::query()->create([
            'division_id' => $this->cortes[0]->id, 'nombre' => 'Parcial', 'peso' => '0.5', 'orden' => 1,
        ]);

        $this->grupo = Grupo::query()->create(['dictado_id' => $this->dictado->id, 'nombre' => 'Diurno']);

        for ($i = 1; $i <= $estudiantes; $i++) {
            $this->crearEstudiante((string) (1000 + $i), "APELLIDO{$i}", "Nombre{$i}");
        }
    }

    protected function crearEstudiante(string $documento, string $apellidos, string $nombres): Estudiante
    {
        $e = Estudiante::query()->create([
            'dictado_id' => $this->dictado->id,
            'documento' => $documento,
            'apellidos' => $apellidos,
            'nombres' => $nombres,
        ]);
        $this->grupo->estudiantes()->attach($e->id);

        return $e;
    }

    protected function crearActividad(?Componente $componente = null, array $atributos = []): Actividad
    {
        return Actividad::query()->create(array_merge([
            'componente_id' => ($componente ?? $this->trabajos)->id,
            'nombre' => 'Quiz '.Str::random(4),
            'valor_max' => '5',
        ], $atributos));
    }

    /**
     * Artefacto con ítems. Cada ítem: [tipo, clave, peso].
     *
     * @param  array<int, array{0:string, 1:array, 2:string}>  $items
     * @return array{0: Artefacto, 1: array<int, ArtefactoPregunta>}
     */
    protected function crearArtefacto(array $items): array
    {
        $artefacto = Artefacto::query()->create([
            'materia_id' => $this->materia->id,
            'sigla' => 'ED-2026A-'.Str::upper(Str::random(4)),
            'tipo' => 'parcial',
            'titulo' => 'Parcial de prueba',
            'configuracion' => ['modo_desconectado' => true],
        ]);

        $creados = [];
        foreach ($items as $orden => [$tipo, $clave, $peso]) {
            $pregunta = Pregunta::query()->create([
                'materia_id' => $this->materia->id,
                'tipo' => $tipo,
                'enunciado' => ['texto' => "Pregunta {$orden}"],
                'clave' => $clave,
            ]);
            $creados[] = ArtefactoPregunta::query()->create([
                'artefacto_id' => $artefacto->id,
                'pregunta_id' => $pregunta->id,
                'peso' => $peso,
                'orden' => $orden,
            ]);
        }

        return [$artefacto, $creados];
    }

    protected function crearEntrega(Actividad $actividad, Estudiante ...$integrantes): Entrega
    {
        $entrega = Entrega::query()->create([
            'actividad_id' => $actividad->id,
            'subida_por_id' => $integrantes[0]->id,
            'ruta' => 'entregas/'.Str::random(8).'.json',
            'sha256' => hash('sha256', Str::random(16)),
        ]);
        $entrega->integrantes()->attach(array_map(fn ($e) => $e->id, $integrantes));

        return $entrega;
    }
}
