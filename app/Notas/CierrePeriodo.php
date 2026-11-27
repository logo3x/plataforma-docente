<?php

namespace App\Notas;

use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Grupo;
use Brick\Math\BigDecimal;

/**
 * Cierre del periodo (3.7): simulador «qué necesita para pasar» y revisión de ceros y vacíos.
 * SOLO CALCULA: no cambia ninguna nota ni propone subirlas para cuadrar un resultado.
 */
final class CierrePeriodo
{
    private TableroNotas $tablero;

    private CalculadoraNotas $calc;

    public function __construct(private readonly Grupo $grupo, private readonly string $margen = '0.1')
    {
        $this->tablero = new TableroNotas($grupo);
        $this->calc = new CalculadoraNotas($this->tablero->escala());
    }

    public function tablero(): TableroNotas
    {
        return $this->tablero;
    }

    /**
     * Por estudiante: acumulado de las divisiones cerradas, peso pendiente, clasificación y meta.
     *
     * @return array<int, array{estudiante: Estudiante, acumulado: string, pendiente: string, simulacion: ResultadoSimulacion, pendientes: array<int, string>, beneficio_vacias: bool}>
     */
    public function simulacion(): array
    {
        $filas = [];
        foreach ($this->tablero->filasConsolidado() as $f) {
            $acumulado = BigDecimal::zero();
            $pendiente = BigDecimal::zero();
            $pendientes = [];

            foreach ($this->tablero->pestanas() as $d) {
                $r = $f['divisiones'][$d->id];
                if ($r->esFinal()) {
                    $acumulado = $acumulado->plus($this->calc->aporte($r->definitiva, $d->peso));
                } else {
                    $pendiente = $pendiente->plus($d->peso);
                    $pendientes[] = $r->estado === EstadoCorte::Incompleto ? "{$d->nombre} (incompleto)" : $d->nombre;
                }
            }

            $acum = Calculo::normalizar($acumulado);
            $filas[] = [
                'estudiante' => $f['estudiante'],
                'acumulado' => $acum,
                'pendiente' => Calculo::normalizar($pendiente),
                'pendientes' => $pendientes,
                'simulacion' => $this->calc->simularCierre($acum, (string) $pendiente, $this->margen),
                'beneficio_vacias' => $this->apruebaPorVacias($f['estudiante']),
            ];
        }

        // Primero los que dependen de lo pendiente y los que están al margen: merecen una segunda mirada
        usort($filas, fn ($a, $b) => [$this->prioridad($a), $a['estudiante']->apellidos] <=> [$this->prioridad($b), $b['estudiante']->apellidos]);

        return $filas;
    }

    /**
     * Ceros y vacíos que importan, ordenados por cercanía del estudiante al límite (3.7.6, 3.7.7).
     *
     *  - cero:      tiene 0 registrado
     *  - vacía:     la actividad ya se aplicó (otros tienen nota) y él no tiene: no promedia
     *  - pendiente: la actividad aún no tiene ninguna nota
     *
     * @return array<int, array{estudiante: Estudiante, division: string, componente: string, actividad: string, actividad_id: int, division_id: int, tipo: string, definitiva: ?string, distancia: string}>
     */
    public function ceros(): array
    {
        $escala = $this->tablero->escala();
        $estudiantes = $this->tablero->estudiantes();
        $items = [];
        $companeros = $this->companeros();

        foreach ($this->tablero->pestanas() as $d) {
            foreach ($d->componentes as $c) {
                foreach ($c->actividades as $a) {
                    $aplicada = $estudiantes->contains(fn ($e) => $this->tablero->notaEfectiva($a->id, $e->id) !== null);

                    foreach ($estudiantes as $e) {
                        $v = $this->tablero->notaEfectiva($a->id, $e->id);
                        $tipo = match (true) {
                            $v !== null && BigDecimal::of($v)->isEqualTo($escala->minimo) => 'cero',
                            $v === null && $aplicada => 'vacia',
                            $v === null => 'pendiente',
                            default => null,
                        };
                        if ($tipo === null || $tipo === 'pendiente') {
                            continue; // las pendientes se ven en el simulador, no son ceros que revisar
                        }

                        $def = $this->tablero->resultado($d, $e)->definitiva;
                        // 3.7.9: un compañero de entregas grupales del periodo sí tiene nota aquí: ¿trabajo conjunto subido por uno solo?
                        $conNota = collect($companeros[$e->id] ?? [])->filter(function ($otro) use ($a, $escala) {
                            $n = $this->tablero->notaEfectiva($a->id, $otro->id);

                            return $n !== null && BigDecimal::of($n)->isGreaterThan($escala->minimo);
                        })->values();
                        $items[] = [
                            'companeros_con_nota' => $conNota,
                            'estudiante' => $e,
                            'division' => $d->nombre,
                            'division_id' => $d->id,
                            'componente' => $c->nombre,
                            'actividad' => $a->nombre,
                            'actividad_id' => $a->id,
                            'tipo' => $tipo,
                            'definitiva' => $def,
                            'distancia' => $def === null ? '999'
                                : (string) BigDecimal::of($def)->minus($escala->aprobatoria)->abs(),
                        ];
                    }
                }
            }
        }

        usort($items, fn ($a, $b) => BigDecimal::of($a['distancia'])->compareTo($b['distancia']) ?: strcmp($a['estudiante']->apellidos, $b['estudiante']->apellidos));

        return $items;
    }

    /**
     * Con quién ha entregado trabajos grupales cada estudiante en este periodo.
     *
     * @return array<int, array<int, Estudiante>>
     */
    private function companeros(): array
    {
        $ids = $this->tablero->estudiantes()->pluck('id')->all();
        $mapa = [];
        Entrega::query()->whereHas('actividad.componente.division', fn ($q) => $q->where('dictado_id', $this->grupo->dictado_id))
            ->has('integrantes', '>', 1)->with('integrantes')->get()
            ->each(function (Entrega $en) use (&$mapa, $ids) {
                foreach ($en->integrantes as $x) {
                    foreach ($en->integrantes as $y) {
                        if ($x->id !== $y->id && in_array($x->id, $ids, true)) {
                            $mapa[$x->id][$y->id] = $y;
                        }
                    }
                }
            });

        return $mapa;
    }

    /**
     * 3.7.8: aprueba alguna división solo porque una celda vacía se excluyó del promedio.
     * Se recalcula tratando sus vacías (de actividades ya aplicadas) como el mínimo de la escala.
     */
    private function apruebaPorVacias(Estudiante $e): bool
    {
        $escala = $this->tablero->escala();
        $estudiantes = $this->tablero->estudiantes();

        foreach ($this->tablero->pestanas() as $d) {
            $actual = $this->tablero->resultado($d, $e);
            if ($actual->estado !== EstadoCorte::Aprobado) {
                continue;
            }

            $componentes = [];
            $hayVacias = false;
            foreach ($d->componentes as $c) {
                $notas = [];
                foreach ($c->actividades as $a) {
                    $v = $this->tablero->notaEfectiva($a->id, $e->id);
                    $aplicada = $estudiantes->contains(fn ($o) => $this->tablero->notaEfectiva($a->id, $o->id) !== null);
                    if ($v === null && $aplicada) {
                        $v = $escala->minimo;
                        $hayVacias = true;
                    }
                    $notas[] = $v;
                }
                $componentes[] = ['peso' => $c->peso, 'notas' => $notas, 'actividades' => $c->actividades->count()];
            }

            if ($hayVacias && $this->calc->definitivaDivision($componentes)->estado === EstadoCorte::Reprobado) {
                return true;
            }
        }

        return false;
    }

    private function prioridad(array $f): int
    {
        return match (true) {
            $f['simulacion']->alMargen => 0,
            $f['beneficio_vacias'] => 1,
            $f['simulacion']->clasificacion === ClasificacionCierre::DependeDeLoPendiente => 2,
            $f['simulacion']->clasificacion === ClasificacionCierre::ApruebaPorRedondeo => 0,
            $f['simulacion']->clasificacion === ClasificacionCierre::NoAlcanza => 3,
            default => 4,
        };
    }
}
