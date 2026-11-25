<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Notas\CierrePeriodo;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Simulador de cierre y revisión de ceros (3.7). Solo lectura. */
class CierreController extends Controller
{
    public function __invoke(Grupo $grupo): View
    {
        Gate::authorize('gestionar', $grupo);
        $cierre = new CierrePeriodo($grupo->load('dictado.materia'));

        return view('cierre', [
            'grupo' => $grupo,
            'escala' => $cierre->tablero()->escala(),
            'simulacion' => $cierre->simulacion(),
            'ceros' => $cierre->ceros(),
        ]);
    }
}
