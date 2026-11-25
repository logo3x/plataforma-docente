<?php

namespace App\Calificacion;

use App\Models\Entrega;
use App\Models\Respuesta;
use App\Notas\Calculo;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Parte del navegador de la calificación híbrida (revisión A1).
 *
 * El navegador del docente ejecuta el código del estudiante en el entorno aislado de la
 * sección 4 y devuelve SOLO qué verificaciones pasaron, con la salida de cada caso.
 * Los nombres y valores de las verificaciones viven en el servidor (Pregunta::clave), así
 * que el navegador no puede inventar puntajes: el servidor los calcula.
 *
 * Formato de entrada, por ítem:
 *   [
 *     'item' => <id de artefacto_pregunta>,
 *     'verificaciones' => ['id_verificacion' => true|false, ...],
 *     'casos' => [ ['entrada' => ..., 'salida' => ..., 'esperada' => ..., 'consola' => [...], 'estado' => ...], ... ],
 *   ]
 */
final class ResultadosNavegador
{
    /**
     * @param  array<int, array{item:int, verificaciones:array<string,bool>, casos?:array}>  $resultados
     */
    public function registrar(Entrega $entrega, array $resultados): void
    {
        DB::transaction(function () use ($entrega, $resultados) {
            foreach ($resultados as $r) {
                $this->registrarItem($entrega, $r);
            }

            $pendientes = $entrega->respuestas()
                ->whereNull('puntaje')
                ->whereHas('item.pregunta', fn ($q) => $q->where('tipo', 'reto'))
                ->exists();

            $entrega->update(['estado_codigo' => $pendientes ? 'pendiente' : 'calificado']);
        });
    }

    private function registrarItem(Entrega $entrega, array $r): void
    {
        /** @var Respuesta|null $respuesta */
        $respuesta = $entrega->respuestas()
            ->where('artefacto_pregunta_id', $r['item'])
            ->with('item.pregunta')
            ->first();

        if ($respuesta === null) {
            throw new InvalidArgumentException("El ítem {$r['item']} no pertenece a esta entrega.");
        }

        $pregunta = $respuesta->item->pregunta;
        if ($pregunta->tipo !== 'reto') {
            throw new InvalidArgumentException("El ítem {$r['item']} no es un reto de código; lo califica el servidor.");
        }

        $definidas = collect($pregunta->clave['verificaciones'] ?? [])->keyBy('id');
        $recibidas = $r['verificaciones'];

        $faltan = $definidas->keys()->diff(array_keys($recibidas));
        $sobran = collect(array_keys($recibidas))->diff($definidas->keys());
        if ($faltan->isNotEmpty() || $sobran->isNotEmpty()) {
            throw new InvalidArgumentException(
                "Las verificaciones del ítem {$r['item']} no coinciden con la especificación "
                .'(faltan: '.$faltan->implode(', ').'; sobran: '.$sobran->implode(', ').').'
            );
        }

        $max = BigDecimal::of($respuesta->puntaje_max);
        $puntaje = BigDecimal::zero();
        $detalle = [];

        foreach ($definidas as $id => $v) {
            $superada = (bool) $recibidas[$id];
            $valor = $max->multipliedBy($v['fraccion']);
            if ($superada) {
                $puntaje = $puntaje->plus($valor);
            }
            // 6.4.1: nombre legible en español, resultado y valor
            $detalle[] = [
                'nombre' => $v['nombre'],
                'superada' => $superada,
                'valor' => Calculo::normalizar($valor),
            ];
        }

        $respuesta->update([
            'puntaje' => Calculo::paraGuardar((string) $puntaje),
            'calificado_por' => 'navegador',
            'desglose' => [
                'verificaciones' => $detalle,
                'casos' => $r['casos'] ?? [],     // incluye los reservados, ya calificada (6.4.4)
            ],
        ]);
    }
}
