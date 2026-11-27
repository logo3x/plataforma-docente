<?php

namespace App\Dictados;

use App\Models\Actividad;
use App\Models\CambioEstructura;
use App\Models\Componente;
use App\Models\Dictado;
use App\Models\Division;
use App\Models\Nota;
use App\Models\User;
use App\Notas\CalculadoraNotas;
use App\Notas\Escala;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Edición de la estructura del periodo después del alta (2.7.1, 2.7.2, 3.1.2), aunque ya haya notas:
 * nombres, pesos, fechas, divisiones y componentes nuevos. Las notas no se tocan: las definitivas
 * se calculan al vuelo con los pesos vigentes. Lo único que no se permite es quitar una división
 * o un componente que tenga actividades, porque se perderían sus notas; primero se mueven
 * las actividades a otro componente (3.1.4). Cada guardado queda registrado con cómo estaba.
 */
final class EditorEstructura
{
    /** La estructura de primer nivel tal como la edita el formulario, con cuántas actividades tiene cada parte. */
    public function datos(Dictado $dictado): array
    {
        $porComponente = Actividad::query()->whereIn('componente_id', Componente::query()->whereIn('division_id', $dictado->divisiones()->select('id'))->select('id'))
            ->selectRaw('componente_id, count(*) as n')->groupBy('componente_id')->pluck('n', 'componente_id');

        return $dictado->divisiones()->whereNull('parent_id')->with('componentes')->get()->map(fn (Division $d) => [
            'id' => $d->id,
            'nombre' => $d->nombre,
            'peso' => $this->texto($d->peso),
            'fecha_inicio' => $d->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $d->fecha_fin?->format('Y-m-d'),
            'componentes' => $d->componentes->map(fn (Componente $c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'peso' => $this->texto($c->peso), 'actividades' => (int) ($porComponente[$c->id] ?? 0),
            ])->all(),
        ])->all();
    }

    public function notasCargadas(Dictado $dictado): int
    {
        return Nota::query()->whereNotNull('valor')->whereHas('actividad.componente.division', fn ($q) => $q->where('dictado_id', $dictado->id))->count();
    }

    /**
     * @param  array<int, array{id?:?int, nombre:string, peso:string, fecha_inicio?:?string, fecha_fin?:?string,
     *               componentes: array<int, array{id?:?int, nombre:string, peso:string}>}>  $divisiones  pesos en fracción
     * @return array<int, string> los cambios hechos, en frases; vacío si no cambió nada
     */
    public function guardar(Dictado $dictado, array $divisiones, ?User $quien = null): array
    {
        $divisiones = array_values($divisiones);
        $calc = new CalculadoraNotas($dictado->escala());
        $calc->validarPesos(array_column($divisiones, 'peso'), 'las divisiones del periodo');
        foreach ($divisiones as $d) {
            $calc->validarPesos(array_column($d['componentes'], 'peso'), "los componentes de «{$d['nombre']}»");
            $ini = $d['fecha_inicio'] ?? null;
            $fin = $d['fecha_fin'] ?? null;
            if ($ini && $fin && $fin < $ini) {
                throw new InvalidArgumentException("«{$d['nombre']}» termina antes de empezar.");
            }
        }

        return DB::transaction(function () use ($dictado, $divisiones, $quien) {
            $antes = $this->datos($dictado);
            $actuales = $dictado->divisiones()->whereNull('parent_id')->with('componentes.actividades')->get()->keyBy('id');
            $cambios = [];
            $vistasDiv = [];
            $vistosComp = [];

            foreach ($divisiones as $i => $d) {
                $div = isset($d['id']) ? $actuales->get((int) $d['id']) : null;
                if (isset($d['id']) && ! $div) {
                    throw new InvalidArgumentException('Una de las divisiones ya no existe. Recargue la página.');
                }
                $valores = ['nombre' => trim($d['nombre']), 'peso' => $d['peso'], 'orden' => $i,
                    'fecha_inicio' => ($d['fecha_inicio'] ?? null) ?: null, 'fecha_fin' => ($d['fecha_fin'] ?? null) ?: null];
                if ($div) {
                    $cambios = [...$cambios, ...$this->diferencias($div, $valores, 'División')];
                    $div->update($valores);
                } else {
                    $div = $dictado->divisiones()->create($valores);
                    $cambios[] = "División nueva «{$div->nombre}» con peso {$this->pct($div->peso)} %";
                }
                $vistasDiv[] = $div->id;

                foreach (array_values($d['componentes']) as $j => $c) {
                    $comp = isset($c['id']) ? Componente::query()->find((int) $c['id']) : null;
                    if (isset($c['id']) && (! $comp || ! $this->esDelDictado($comp, $dictado))) {
                        throw new InvalidArgumentException('Uno de los componentes ya no existe. Recargue la página.');
                    }
                    $vc = ['nombre' => trim($c['nombre']), 'peso' => $c['peso'], 'orden' => $j, 'division_id' => $div->id];
                    if ($comp) {
                        $cambios = [...$cambios, ...$this->diferencias($comp, $vc, "Componente de «{$div->nombre}»")];
                        if ($comp->division_id !== $div->id) {
                            $cambios[] = "«{$comp->nombre}» pasa a «{$div->nombre}» con sus actividades";
                        }
                        $comp->update($vc);
                    } else {
                        $comp = Componente::query()->create($vc);
                        $cambios[] = "Componente nuevo «{$comp->nombre}» en «{$div->nombre}» con peso {$this->pct($comp->peso)} %";
                    }
                    $vistosComp[] = $comp->id;
                }
            }

            // Lo que no vino en el formulario se quita, salvo que tenga actividades
            $sobrantes = Componente::query()->whereIn('division_id', $actuales->keys())->whereNotIn('id', $vistosComp)->withCount('actividades')->get();
            foreach ($sobrantes as $c) {
                if ($c->actividades_count) {
                    $n = $c->actividades_count === 1 ? 'una actividad' : "{$c->actividades_count} actividades";
                    throw new InvalidArgumentException("«{$c->nombre}» tiene {$n}: muévalas a otro componente antes de quitarlo.");
                }
                $cambios[] = "Se quita el componente «{$c->nombre}»";
                $c->delete();
            }
            foreach ($actuales->except($vistasDiv) as $div) {
                if ($div->hijas()->exists()) {
                    throw new InvalidArgumentException("«{$div->nombre}» tiene divisiones dentro y no se puede quitar desde aquí.");
                }
                $cambios[] = "Se quita la división «{$div->nombre}»";
                $div->delete();
            }

            if ($cambios) {
                CambioEstructura::query()->create(['dictado_id' => $dictado->id, 'user_id' => $quien?->id,
                    'cambios' => $cambios, 'antes' => $antes, 'notas_cargadas' => $this->notasCargadas($dictado)]);
            }

            return $cambios;
        });
    }

    /**
     * 3.2.1 y 3.2.3: la nota mínima, los decimales y la regla de redondeo se cambian siempre, porque no tocan
     * ninguna nota guardada (solo cómo se muestra y quién aprueba). El mínimo y el máximo de la escala solo
     * mientras el periodo no tenga notas: después habría que convertir notas que los estudiantes ya vieron.
     * Sin notas, las actividades que valían el máximo pasan a valer el nuevo máximo.
     *
     * @param  array{escala_min:string, escala_max:string, nota_aprobatoria:string, decimales:int, redondeo:string}  $d
     * @return array<int, string> los cambios hechos, en frases
     */
    public function cambiarEscala(Dictado $dictado, array $d, ?User $quien = null): array
    {
        $antes = $dictado->escala();
        $nueva = new Escala((string) $d['escala_min'], (string) $d['escala_max'], (string) $d['nota_aprobatoria'], (int) $d['decimales'], $d['redondeo']);
        $igual = fn ($a, $b) => BigDecimal::of($a)->compareTo(BigDecimal::of($b)) === 0;
        $rango = ! $igual($antes->minimo, $nueva->minimo) || ! $igual($antes->maximo, $nueva->maximo);
        $notas = $this->notasCargadas($dictado);
        if ($rango && $notas > 0) {
            throw new InvalidArgumentException('El periodo ya tiene '.($notas === 1 ? 'una nota' : "{$notas} notas")." en la escala de {$antes->mostrar($antes->minimo)} a {$antes->mostrar($antes->maximo)}: "
                .'el mínimo y el máximo ya no se cambian. La nota mínima, los decimales y el redondeo sí.');
        }

        $f = fn (Escala $e, string $v) => (string) BigDecimal::of($v)->strippedOfTrailingZeros();
        $reglas = ['cercano' => 'al más cercano', 'arriba' => 'hacia arriba', 'abajo' => 'hacia abajo'];
        $cambios = [];
        if ($rango) {
            $cambios[] = "Escala de {$f($antes, $antes->minimo)}–{$f($antes, $antes->maximo)} a {$f($nueva, $nueva->minimo)}–{$f($nueva, $nueva->maximo)}";
        }
        if (! $igual($antes->aprobatoria, $nueva->aprobatoria)) {
            $cambios[] = "Nota mínima para aprobar de {$f($antes, $antes->aprobatoria)} a {$f($nueva, $nueva->aprobatoria)}";
        }
        if ($antes->decimales !== $nueva->decimales) {
            $cambios[] = "Decimales al mostrar de {$antes->decimales} a {$nueva->decimales}";
        }
        if ($antes->redondeo !== $nueva->redondeo) {
            $cambios[] = "Redondeo de «{$reglas[$antes->redondeo]}» a «{$reglas[$nueva->redondeo]}»";
        }
        if (! $cambios) {
            return [];
        }

        DB::transaction(function () use ($dictado, $antes, $nueva, $rango, $cambios, $quien, $notas) {
            if ($rango) {
                Actividad::query()->whereIn('componente_id', Componente::query()->whereIn('division_id', $dictado->divisiones()->select('id'))->select('id'))
                    ->where('valor_max', $antes->maximo)->update(['valor_max' => $nueva->maximo]);
            }
            $dictado->update(['escala_min' => $nueva->minimo, 'escala_max' => $nueva->maximo, 'nota_aprobatoria' => $nueva->aprobatoria,
                'decimales' => $nueva->decimales, 'redondeo' => $nueva->redondeo]);
            CambioEstructura::query()->create(['dictado_id' => $dictado->id, 'user_id' => $quien?->id, 'cambios' => $cambios, 'notas_cargadas' => $notas,
                'antes' => ['escala' => ['minimo' => $antes->minimo, 'maximo' => $antes->maximo, 'aprobatoria' => $antes->aprobatoria,
                    'decimales' => $antes->decimales, 'redondeo' => $antes->redondeo]]]);
        });

        return $cambios;
    }

    private function esDelDictado(Componente $c, Dictado $dictado): bool
    {
        return $c->division()->value('dictado_id') === $dictado->id;
    }

    /** @return array<int, string> */
    private function diferencias(Division|Componente $m, array $nuevos, string $que): array
    {
        $r = [];
        if ($m->nombre !== $nuevos['nombre']) {
            $r[] = "{$que} «{$m->nombre}» se llama ahora «{$nuevos['nombre']}»";
        }
        if (BigDecimal::of($m->peso)->compareTo(BigDecimal::of($nuevos['peso'])) !== 0) {
            $r[] = "«{$nuevos['nombre']}»: peso de {$this->pct($m->peso)} % a {$this->pct($nuevos['peso'])} %";
        }
        if ($m instanceof Division) {
            $antes = [$m->fecha_inicio?->format('Y-m-d'), $m->fecha_fin?->format('Y-m-d')];
            if ($antes !== [$nuevos['fecha_inicio'], $nuevos['fecha_fin']]) {
                $r[] = "«{$nuevos['nombre']}»: fechas ".($nuevos['fecha_inicio'] ?? '…').' a '.($nuevos['fecha_fin'] ?? '…');
            }
        }

        return $r;
    }

    private function pct(string $fraccion): string
    {
        return (string) BigDecimal::of($fraccion)->multipliedBy(100)->strippedOfTrailingZeros();
    }

    private function texto(string $fraccion): string
    {
        return (string) BigDecimal::of($fraccion)->strippedOfTrailingZeros();
    }
}
