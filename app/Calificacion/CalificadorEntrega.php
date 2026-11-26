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
        $respuestas = $entrega->respuestas()->with('item')->get();
        $obtenido = BigDecimal::zero();
        $posible = BigDecimal::zero();

        foreach ($respuestas as $r) {
            $regla = $r->item->anulada_en ? $r->item->regla_anulacion : null;

            if ($regla === 'retirar_y_reescalar') {
                continue;
            }
            if ($regla === 'dar_por_buena') {
                $obtenido = $obtenido->plus($r->puntaje_max);
                $posible = $posible->plus($r->puntaje_max);

                continue;
            }
            if ($r->puntaje === null) {
                return null;
            }
            $obtenido = $obtenido->plus($r->puntaje);
            $posible = $posible->plus($r->puntaje_max);
        }

        if ($posible->isZero()) {
            return null;
        }

        $valorMax = $entrega->actividad->valor_max;

        return Calculo::normalizar(
            $obtenido->multipliedBy($valorMax)->dividedBy($posible, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven)
        );
    }
}
