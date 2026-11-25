<?php

namespace App\Http\Controllers\Calificador;

use App\Calificacion\ResultadosNavegador;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResultadosNavegadorRequest;
use App\Models\Entrega;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Recibe del navegador del docente el resultado de ejecutar los retos de código (calificación híbrida).
 */
class ResultadosNavegadorController extends Controller
{
    public function __invoke(ResultadosNavegadorRequest $request, Entrega $entrega, ResultadosNavegador $servicio): JsonResponse
    {
        try {
            $servicio->registrar($entrega, $request->validated('resultados'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'estado_codigo' => $entrega->fresh()->estado_codigo,
        ]);
    }
}
