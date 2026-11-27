<?php

namespace App\Calificacion;

use App\Models\Entrega;
use App\Models\Respuesta;
use App\Notas\Calculo;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta la calificación de una entrega ya descifrada:
 *  1. el servidor califica los ítems deterministas;
 *  2. los retos quedan pendientes del navegador y las abiertas del docente;
 *  3. cuando no queda nada pendiente, se obtiene la nota de la entrega.
 */
final class CalificadorEntrega
{
    public function __construct(private readonly CalificadorDeterminista $determinista) {}

    /**
     * @param  array<int, mixed>  $respuestasPorItem  respuesta del estudiante por id de artefacto_pregunta
     */
    public function calificarServidor(Entrega $entrega, array $respuestasPorItem): void
    {
        // Solo los ítems de la versión que respondió (5.4.2); los de la otra versión no le cuentan
        $items = $entrega->actividad->artefacto->items()->with('pregunta')
            ->when($entrega->version, fn ($q, $v) => $q->where('banco', $v))->get();
        $hayRetos = false;

        DB::transaction(function () use ($entrega, $respuestasPorItem, $items, &$hayRetos) {
            foreach ($items as $item) {
                $respuesta = $respuestasPorItem[$item->id] ?? null;
                $r = $this->determinista->calificar($item->pregunta->tipo, $item->pregunta->clave, $respuesta, $item->peso);
                $hayRetos = $hayRetos || $r->pendienteDe === 'navegador';

                Respuesta::query()->updateOrCreate(
                    ['entrega_id' => $entrega->id, 'artefacto_pregunta_id' => $item->id],
                    [
                        'respuesta' => ['valor' => $respuesta],
                        'puntaje' => $r->puntaje === null ? null : Calculo::paraGuardar($r->puntaje),
                        'puntaje_max' => $item->peso,
                        'calificado_por' => $r->puntaje === null ? null : 'servidor',
                        'desglose' => $r->desglose,
                    ]
                );
            }

            $entrega->update([
                'estado_codigo' => $hayRetos ? 'pendiente' : 'no_aplica',
                'procesada_en' => now(),
            ]);
        });
    }

    /**
     * Nota de la entrega en la escala de la actividad, o null si algo sigue pendiente.
     *
     * Anulación de ítem (6.5.5):
     *  - dar_por_buena:       el ítem cuenta con su puntaje máximo para todos;
     *  - retirar_y_reescalar: el ítem sale del cálculo y los demás se reescalan al valor total.
     */
    public function nota(Entrega $entrega): ?string
    {
        // Entrega de archivo: el docente la calificó directamente en la escala de la actividad
        if ($entrega->tipo === 'archivo') {
            return $entrega->puntaje === null ? null : Calculo::normalizar(BigDecimal::of($entrega->puntaje));
        }

        $partes = $this->partes($entrega);
        if ($partes === null) {
            return null;
        }
        $valorMax = BigDecimal::of($entrega->actividad->valor_max);
        $conPuntos = array_filter($partes, fn ($p) => ! $p['posible']->isZero());
        if ($conPuntos === []) {
            return null;
        }

        // 3.2: por partes, cada parte pesa lo que el docente fijó; si solo hay una, es la proporción de puntos
        if (count($conPuntos) === 2 && ($peso = self::pesoEscrita($entrega)) !== null) {
            $fraccion = fn ($p) => $p['obtenido']->dividedBy($p['posible'], Calculo::ESCALA_INTERNA, RoundingMode::HalfEven);
            $total = $fraccion($partes['escrita'])->multipliedBy($peso)
                ->plus($fraccion($partes['practica'])->multipliedBy(BigDecimal::one()->minus($peso)));

            return Calculo::normalizar($total->multipliedBy($valorMax));
        }

        $obtenido = $partes['escrita']['obtenido']->plus($partes['practica']['obtenido']);
        $posible = $partes['escrita']['posible']->plus($partes['practica']['posible']);

        return Calculo::normalizar(
            $obtenido->multipliedBy($valorMax)->dividedBy($posible, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven)
        );
    }

    /** Fracción de la nota que vale la parte escrita si el examen se califica por partes (3.2), o null si va por puntos. */
    public static function pesoEscrita(Entrega $entrega): ?BigDecimal
    {
        $c = $entrega->actividad->artefacto?->configuracion ?? [];
        if (($c['calculo_nota'] ?? 'puntos') !== 'partes') {
            return null;
        }

        return BigDecimal::of((int) ($c['peso_escrita'] ?? 50))->dividedBy(100, 4);
    }

    /** La cuenta por partes en una frase, para el detalle y el informe (6.4); null si la nota va por puntos. */
    public function explicacionPartes(Entrega $entrega): ?string
    {
        $peso = self::pesoEscrita($entrega);
        $partes = $peso ? $this->partes($entrega) : null;
        if (! $partes || $partes['escrita']['posible']->isZero() || $partes['practica']['posible']->isZero()) {
            return null;
        }
        $n = fn (BigDecimal $x) => str_replace('.', ',', (string) $x->strippedOfTrailingZeros());
        $pct = fn (BigDecimal $x) => $n($x->multipliedBy(100));

        return "Nota por partes: escrita {$n($partes['escrita']['obtenido'])} de {$n($partes['escrita']['posible'])} puntos (pesa {$pct($peso)} %) "
            ."y práctica {$n($partes['practica']['obtenido'])} de {$n($partes['practica']['posible'])} puntos (pesa {$pct(BigDecimal::one()->minus($peso))} %).";
    }

    /**
     * Puntos obtenidos y posibles de la parte escrita y de la práctica (los retos de código),
     * con las anulaciones ya aplicadas, o null si algún ítem sigue pendiente.
     *
     * @return array{escrita: array{obtenido: BigDecimal, posible: BigDecimal}, practica: array{obtenido: BigDecimal, posible: BigDecimal}}|null
     */
    public function partes(Entrega $entrega): ?array
    {
        $partes = ['escrita' => ['obtenido' => BigDecimal::zero(), 'posible' => BigDecimal::zero()],
            'practica' => ['obtenido' => BigDecimal::zero(), 'posible' => BigDecimal::zero()]];

        foreach ($entrega->respuestas()->with('item.pregunta')->get() as $r) {
            $regla = $r->item->anulada_en ? $r->item->regla_anulacion : null;
            $k = $r->item->pregunta?->tipo === 'reto' ? 'practica' : 'escrita';

            if ($regla === 'retirar_y_reescalar') {
                continue;
            }
            if ($regla === 'dar_por_buena') {
                $partes[$k]['obtenido'] = $partes[$k]['obtenido']->plus($r->puntaje_max);
                $partes[$k]['posible'] = $partes[$k]['posible']->plus($r->puntaje_max);

                continue;
            }
            if ($r->puntaje === null) {
                return null;
            }
            $partes[$k]['obtenido'] = $partes[$k]['obtenido']->plus($r->puntaje);
            $partes[$k]['posible'] = $partes[$k]['posible']->plus($r->puntaje_max);
        }

        return $partes;
    }
}
