<?php

namespace App\Notas;

use App\Models\Actividad;
use App\Models\Division;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Nota;
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

    public function __construct(private readonly Grupo $grupo)
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
            $filas[] = [
                'estudiante' => $e,
                'divisiones' => $resultados,
                'aportes' => $aportes,
                'acumulado' => $acum,
                'estado' => ! $todasFinales
                    ? EstadoCorte::Incompleto
                    : ($this->escala->aprueba($acum) ? EstadoCorte::Aprobado : EstadoCorte::Reprobado),
            ];
        }

        return $filas;
    }

    /** Resultado de un estudiante en una división; si tiene subdivisiones, se pondera desde ellas (2.7.4). */
    public function resultado(Division $division, Estudiante $e): ResultadoDivision
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
                'notas' => $c->actividades->map(fn (Actividad $a) => $this->nota($a->id, $e->id))->all(),
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
