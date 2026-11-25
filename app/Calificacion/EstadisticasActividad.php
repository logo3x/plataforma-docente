<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Entrega;
use App\Models\Grupo;
use App\Notas\Calculo;
use App\Notas\Escala;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Estadísticas de grupo y análisis de ítems de una actividad calificada (6.10).
 * Describe lo que pasó; no decide: nada se anula ni se reajusta de forma automática.
 * Todo lo que devuelve es agregado y anónimo, apto para proyectar (6.10.5).
 */
final class EstadisticasActividad
{
    /** Umbrales de los candidatos a revisión (6.10.3). */
    public const ACIERTO_BAJO = 0.3;

    public const ACIERTO_ALTO = 0.9;

    public const DISCRIMINACION_MINIMA = 0.1;

    /** Con menos entregas, el índice de discriminación no es confiable y no se calcula. */
    public const MINIMO_PARA_DISCRIMINAR = 10;

    /** Con menos respuestas, marcar un ítem como «muy fácil» o «muy difícil» es ruido. */
    public const MINIMO_PARA_MARCAR = 5;

    private Escala $escala;

    /** @var Collection<int, array{entrega: Entrega, nota: string}> solo entregas con nota completa */
    private Collection $calificadas;

    public function __construct(private readonly Actividad $actividad, private readonly ?Grupo $grupo = null)
    {
        $this->escala = $actividad->componente->division->dictado->escala();
        $calificador = app(CalificadorEntrega::class);

        $entregas = $actividad->entregas()->with(['integrantes.grupos', 'respuestas.item.pregunta.tema'])->get();
        if ($grupo !== null) {
            $entregas = $entregas->filter(fn (Entrega $e) => $e->integrantes->contains(fn ($i) => $i->grupos->contains('id', $grupo->id)));
        }

        $this->calificadas = $entregas
            ->map(fn (Entrega $e) => ['entrega' => $e, 'nota' => $calificador->nota($e)])
            ->filter(fn ($x) => $x['nota'] !== null)
            ->values();
    }

    public function escala(): Escala
    {
        return $this->escala;
    }

    /**
     * @return array{n: int, promedio: ?string, maxima: ?string, minima: ?string, aprobados: int, reprobados: int, rangos: array<int, array{desde: string, hasta: string, cantidad: int}>}
     */
    public function resumen(): array
    {
        $notas = $this->calificadas->pluck('nota');
        $n = $notas->count();

        $suma = $notas->reduce(fn ($s, $v) => $s->plus($v), BigDecimal::zero());
        $aprobados = $notas->filter(fn ($v) => $this->escala->aprueba($v))->count();

        return [
            'n' => $n,
            'promedio' => $n ? Calculo::normalizar($suma->dividedBy($n, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven)) : null,
            'maxima' => $n ? $notas->reduce(fn ($m, $v) => $m === null || BigDecimal::of($v)->isGreaterThan($m) ? $v : $m) : null,
            'minima' => $n ? $notas->reduce(fn ($m, $v) => $m === null || BigDecimal::of($v)->isLessThan($m) ? $v : $m) : null,
            'aprobados' => $aprobados,
            'reprobados' => $n - $aprobados,
            'rangos' => $this->rangos($notas),
        ];
    }

    /**
     * Análisis por ítem: acierto, discriminación y, en las de selección, qué eligió cada quien.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        $n = $this->calificadas->count();
        $ordenadas = $this->calificadas->sortBy(fn ($x) => (float) $x['nota'])->values();
        $k = (int) floor($n * 0.27);
        $bajos = $ordenadas->take($k)->pluck('entrega.id')->all();
        $altos = $ordenadas->slice($n - $k)->pluck('entrega.id')->all();

        $porItem = [];
        foreach ($this->calificadas as $x) {
            foreach ($x['entrega']->respuestas as $r) {
                $porItem[$r->artefacto_pregunta_id][] = ['entrega' => $x['entrega']->id, 'respuesta' => $r];
            }
        }

        $items = $this->actividad->artefacto?->items()->with('pregunta.tema')->orderBy('orden')->get() ?? collect();

        return $items->map(function ($item, $i) use ($porItem, $altos, $bajos, $n) {
            $filas = collect($porItem[$item->id] ?? [])->filter(fn ($f) => $f['respuesta']->puntaje !== null);
            $fraccion = fn ($f) => (float) $f['respuesta']->puntaje / max((float) $f['respuesta']->puntaje_max, 1e-9);

            $acierto = $filas->isEmpty() ? null : $filas->avg($fraccion);
            $discriminacion = null;
            if ($n >= self::MINIMO_PARA_DISCRIMINAR && count($altos) > 0) {
                $pa = $filas->whereIn('entrega', $altos)->avg($fraccion) ?? 0;
                $pb = $filas->whereIn('entrega', $bajos)->avg($fraccion) ?? 0;
                $discriminacion = round($pa - $pb, 2);
            }

            $p = $item->pregunta;
            $marcas = [];
            if ($acierto !== null && ! $item->anulada_en && $filas->count() >= self::MINIMO_PARA_MARCAR) {
                if ($acierto < self::ACIERTO_BAJO) {
                    $marcas[] = 'Acierto muy bajo: ¿tema no aprendido o enunciado confuso?';
                } elseif ($acierto > self::ACIERTO_ALTO) {
                    $marcas[] = 'Casi todos aciertan: aporta poco a la evaluación.';
                }
                if ($discriminacion !== null && $discriminacion < self::DISCRIMINACION_MINIMA) {
                    $marcas[] = 'No separa a quienes van bien de quienes van mal.';
                }
            }

            return [
                'item' => $item,
                'numero' => $i + 1,
                'tipo' => $p->tipo,
                'texto' => $p->enunciado['texto'] ?? '',
                'tema' => $p->tema?->titulo,
                'respuestas' => $filas->count(),
                'acierto' => $acierto === null ? null : round($acierto, 3),
                'discriminacion' => $discriminacion,
                'marcas' => $marcas,
                'opciones' => in_array($p->tipo, ['unica', 'vf'], true) ? $this->distribucion($filas, $p) : null,
            ];
        })->all();
    }

    /**
     * Acierto por tema del temario (6.10.4), para resaltar en la lista de chequeo.
     *
     * @return array<int, array{acierto: float, respuestas: int}> por id de tema
     */
    public function porTema(): array
    {
        $acum = [];
        foreach ($this->calificadas as $x) {
            foreach ($x['entrega']->respuestas as $r) {
                $tema = $r->item->pregunta->temario_item_id;
                if ($tema === null || $r->puntaje === null || $r->item->anulada_en) {
                    continue;
                }
                $acum[$tema]['obtenido'] = ($acum[$tema]['obtenido'] ?? 0) + (float) $r->puntaje;
                $acum[$tema]['posible'] = ($acum[$tema]['posible'] ?? 0) + (float) $r->puntaje_max;
                $acum[$tema]['respuestas'] = ($acum[$tema]['respuestas'] ?? 0) + 1;
            }
        }

        return array_map(fn ($a) => ['acierto' => round($a['obtenido'] / max($a['posible'], 1e-9), 3), 'respuestas' => $a['respuestas']], $acum);
    }

    /** Cinco rangos iguales sobre la escala del dictado; el último incluye el máximo. */
    private function rangos(Collection $notas): array
    {
        $min = BigDecimal::of($this->escala->minimo);
        $paso = BigDecimal::of($this->escala->maximo)->minus($min)->dividedBy(5, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven);
        $rangos = [];
        for ($i = 0; $i < 5; $i++) {
            $desde = $min->plus($paso->multipliedBy($i));
            $hasta = $min->plus($paso->multipliedBy($i + 1));
            $rangos[] = [
                'desde' => $this->escala->mostrar((string) $desde),
                'hasta' => $this->escala->mostrar((string) $hasta),
                'cantidad' => $notas->filter(fn ($v) => BigDecimal::of($v)->isGreaterThanOrEqualTo($desde)
                    && ($i === 4 ? BigDecimal::of($v)->isLessThanOrEqualTo($hasta) : BigDecimal::of($v)->isLessThan($hasta)))->count(),
            ];
        }

        return $rangos;
    }

    /** @return array<string, array{texto: string, veces: int, correcta: bool}> */
    private function distribucion(Collection $filas, $pregunta): array
    {
        $opciones = $pregunta->tipo === 'vf'
            ? ['true' => 'Verdadero', 'false' => 'Falso']
            : array_map('strval', $pregunta->enunciado['opciones'] ?? []);
        $correcta = $pregunta->tipo === 'vf' ? json_encode((bool) $pregunta->clave['correcta']) : (string) $pregunta->clave['correcta'];

        $dist = [];
        foreach ($opciones as $k => $t) {
            $dist[(string) $k] = ['texto' => $t, 'veces' => 0, 'correcta' => (string) $k === $correcta];
        }
        $dist['—'] = ['texto' => 'Sin responder', 'veces' => 0, 'correcta' => false];

        foreach ($filas as $f) {
            $v = $f['respuesta']->respuesta['valor'] ?? null;
            $clave = $v === null || $v === '' ? '—' : (is_bool($v) ? json_encode($v) : (string) $v);
            if (isset($dist[$clave])) {
                $dist[$clave]['veces']++;
            }
        }

        return $dist;
    }
}
