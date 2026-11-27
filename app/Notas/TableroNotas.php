<?php

namespace App\Notas;

use App\Models\Actividad;
use App\Models\Division;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Nota;
use App\Models\SegundaOportunidad;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Arma los datos del registro de notas de un grupo: una vista por división (corte, periodo)
 * y la vista consolidada del periodo completo (3.3.1). No escribe nada.
 */
final class TableroNotas
{
    private CalculadoraNotas $calc;

    private Escala $escala;

    /** @var Collection<int, Estudiante> */
    private Collection $estudiantes;

    /** @var array<int, array<int, ?string>> [actividad_id][estudiante_id] => valor */
    private array $notas = [];

    /** @var Collection<int, Division> */
    private Collection $divisiones;

    /** @var array<string, SegundaOportunidad> vigente por «tipo_ámbito:id:estudiante» (3.6) */
    private array $segundas = [];

    /**
     * @param  ?SegundaOportunidad  $hipotetica  evento sin guardar, para simular su efecto (3.7.4)
     */
    public function __construct(private readonly Grupo $grupo, ?SegundaOportunidad $hipotetica = null)
    {
        $dictado = $grupo->dictado;
        $this->escala = $dictado->escala();
        $this->calc = new CalculadoraNotas($this->escala);

        $this->estudiantes = $grupo->estudiantes()
            ->wherePivot('activo', true)
            ->orderBy('apellidos')->orderBy('nombres')
            ->get();

        $this->divisiones = $dictado->divisiones()
            ->with(['componentes.actividades' => fn ($q) => $this->delGrupo($q)])
            ->get();

        $actividadIds = $this->divisiones->flatMap->componentes->flatMap->actividades->pluck('id');

        Nota::query()
            ->whereIn('actividad_id', $actividadIds)
            ->whereIn('estudiante_id', $this->estudiantes->pluck('id'))
            ->get(['actividad_id', 'estudiante_id', 'valor'])
            ->each(function (Nota $n) {
                $this->notas[$n->actividad_id][$n->estudiante_id] = $n->valor;
            });

        // La última segunda oportunidad vigente de cada ámbito es la que cuenta
        SegundaOportunidad::query()->vigentes()
            ->whereIn('estudiante_id', $this->estudiantes->pluck('id'))
            ->orderBy('fecha')->orderBy('id')
            ->get()
            ->push(...array_filter([$hipotetica]))
            ->each(function (SegundaOportunidad $s) {
                $this->segundas[$this->claveSegunda($s->ambito_type, $s->ambito_id, $s->estudiante_id)] = $s;
            });
    }

    /** Nota que cuenta para el cálculo: la original o la que deja la segunda oportunidad. */
    public function notaEfectiva(int $actividadId, int $estudianteId): ?string
    {
        $s = $this->segunda((new Actividad)->getMorphClass(), $actividadId, $estudianteId);

        return $s ? SegundasOportunidades::aplicar($this->nota($actividadId, $estudianteId), $s, $this->escala)
            : $this->nota($actividadId, $estudianteId);
    }

    public function segunda(string $tipo, int $id, int $estudianteId): ?SegundaOportunidad
    {
        return $this->segundas[$this->claveSegunda($tipo, $id, $estudianteId)] ?? null;
    }

    private function claveSegunda(string $tipo, int $id, int $estudianteId): string
    {
        return "{$tipo}:{$id}:{$estudianteId}";
    }

    public function escala(): Escala
    {
        return $this->escala;
    }

    /** @return Collection<int, Estudiante> */
    public function estudiantes(): Collection
    {
        return $this->estudiantes;
    }

    /** Divisiones de primer nivel, en orden: son las pestañas. */
    public function pestanas(): Collection
    {
        return $this->divisiones->whereNull('parent_id')->values();
    }

    public function division(int $id): ?Division
    {
        return $this->divisiones->firstWhere('id', $id);
    }

    /** Copia del tablero con una nota hipotética: para simular, nunca para guardar (3.7.5). */
    public function conNotas(array $actividadIds, int $estudianteId, string $valor): self
    {
        $c = clone $this;
        foreach ($actividadIds as $a) {
            $c->notas[$a][$estudianteId] = $valor;
        }

        return $c;
    }

    public function nota(int $actividadId, int $estudianteId): ?string
    {
        return $this->notas[$actividadId][$estudianteId] ?? null;
    }

    /**
     * Vista de una división: por cada estudiante, sus notas, el promedio de cada componente,
     * la definitiva y el estado.
     *
     * @return array<int, array{estudiante: Estudiante, resultado: ResultadoDivision}>
     */
    public function filasDivision(Division $division): array
    {
        return $this->estudiantes
            ->map(fn (Estudiante $e) => ['estudiante' => $e, 'resultado' => $this->resultado($division, $e)])
            ->all();
    }

    /**
     * Vista consolidada: definitiva y aporte de cada división, acumulado y estado del periodo.
     *
     * @return array<int, array{estudiante: Estudiante, divisiones: array<int, ResultadoDivision>, aportes: array<int, ?string>, acumulado: string, estado: EstadoCorte}>
     */
    public function filasConsolidado(): array
    {
        $filas = [];
        foreach ($this->estudiantes as $e) {
            $resultados = [];
            $aportes = [];
            $acumulado = BigDecimal::zero();
            $todasFinales = true;

            foreach ($this->pestanas() as $d) {
                $r = $this->resultado($d, $e);
                $resultados[$d->id] = $r;
                $todasFinales = $todasFinales && $r->esFinal();
                $aportes[$d->id] = $r->definitiva === null ? null : $this->calc->aporte($r->definitiva, $d->peso);
                if ($aportes[$d->id] !== null) {
                    $acumulado = $acumulado->plus($aportes[$d->id]);
                }
            }

            $acum = Calculo::normalizar($acumulado);
            $original = null;
            // Habilitación de la materia: aplica sobre la nota final, cuando el periodo está completo
            $hab = $todasFinales ? $this->segunda($this->grupo->dictado->getMorphClass(), $this->grupo->dictado_id, $e->id) : null;
            if ($hab) {
                $original = $acum;
                $acum = SegundasOportunidades::aplicar($acum, $hab, $this->escala);
            }
            $filas[] = [
                'estudiante' => $e,
                'divisiones' => $resultados,
                'aportes' => $aportes,
                'acumulado' => $acum,
                'estado' => ! $todasFinales
                    ? EstadoCorte::Incompleto
                    : ($this->escala->aprueba($acum) ? EstadoCorte::Aprobado : EstadoCorte::Reprobado),
                'segunda' => $hab,
                'original' => $original,
            ];
        }

        return $filas;
    }

    /** Resultado de un estudiante en una división; si tiene subdivisiones, se pondera desde ellas (2.7.4). */
    public function resultado(Division $division, Estudiante $e): ResultadoDivision
    {
        $base = $this->resultadoBase($division, $e);

        // Habilitación del corte: solo sobre una definitiva final (aprobada o reprobada)
        $hab = $base->esFinal() ? $this->segunda($division->getMorphClass(), $division->id, $e->id) : null;
        if ($hab === null) {
            return $base;
        }
        $vigente = SegundasOportunidades::aplicar($base->definitiva, $hab, $this->escala);

        return new ResultadoDivision(
            $this->escala->aprueba($vigente) ? EstadoCorte::Aprobado : EstadoCorte::Reprobado,
            $vigente, $base->promedios, $hab, $base->definitiva,
        );
    }

    private function resultadoBase(Division $division, Estudiante $e): ResultadoDivision
    {
        $hijas = $this->divisiones->where('parent_id', $division->id);

        if ($hijas->isNotEmpty()) {
            return $this->desdeHijas($hijas, $e);
        }

        $componentes = [];
        foreach ($division->componentes as $c) {
            $componentes[$c->id] = [
                'peso' => $c->peso,
                'actividades' => $c->actividades->count(),
                'notas' => $c->actividades->map(fn (Actividad $a) => $this->notaEfectiva($a->id, $e->id))->all(),
            ];
        }

        if ($componentes === []) {
            return new ResultadoDivision(EstadoCorte::SinActividades, null);
        }

        return $this->calc->definitivaDivision($componentes);
    }

    private function desdeHijas(Collection $hijas, Estudiante $e): ResultadoDivision
    {
        $definitiva = BigDecimal::zero();
        $incompleto = false;
        $alguna = false;

        foreach ($hijas as $h) {
            $r = $this->resultado($h, $e);
            if ($r->estado === EstadoCorte::SinActividades) {
                continue;
            }
            $alguna = true;
            $incompleto = $incompleto || $r->estado === EstadoCorte::Incompleto;
            if ($r->definitiva !== null) {
                $definitiva = $definitiva->plus(BigDecimal::of($r->definitiva)->multipliedBy($h->peso));
            }
        }

        if (! $alguna) {
            return new ResultadoDivision(EstadoCorte::SinActividades, null);
        }

        $valor = Calculo::normalizar($definitiva);
        $estado = $incompleto ? EstadoCorte::Incompleto
            : ($this->escala->aprueba($valor) ? EstadoCorte::Aprobado : EstadoCorte::Reprobado);

        return new ResultadoDivision($estado, $valor);
    }

    /** Actividades sin grupos asignados aplican a todos; si tienen, solo a esos grupos. */
    private function delGrupo(mixed $q): void
    {
        $q->where(fn (Builder $w) => $w
            ->whereDoesntHave('grupos')
            ->orWhereHas('grupos', fn (Builder $g) => $g->whereKey($this->grupo->id)));
    }
}
