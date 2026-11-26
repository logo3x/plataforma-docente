<?php

namespace App\Http\Controllers;

use App\Evaluacion\EnsayoExamen;
use App\Evaluacion\GeneradorExamen;
use App\Evaluacion\PruebasMinimas;
use App\Models\Actividad;
use App\Models\Ensayo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

/** Modo de ensayo de una actividad (5.6): pruebas mínimas, examen de ensayo y cierre del ciclo con el calificador. */
class EnsayoController extends Controller
{
    public function show(Actividad $actividad, PruebasMinimas $pruebas): View
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404, 'Esta actividad no tiene examen digital.');
        $resultados = $pruebas->ejecutar($actividad);

        return view('calificador.ensayo', [
            'actividad' => $actividad->load('artefacto', 'componente.division.dictado.materia'),
            'pruebas' => $resultados,
            'aprobadas' => PruebasMinimas::aprobadas($resultados),
            'ensayos' => Ensayo::query()->where('actividad_id', $actividad->id)->latest()->get(),
        ]);
    }

    public function examen(Actividad $actividad, GeneradorExamen $generador): Response
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404);

        return response($generador->html($actividad, ensayo: true), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ENSAYO_'.$actividad->artefacto->sigla.'.html"',
        ]);
    }

    /** Sube la entrega del examen de ensayo: se descifra y se califica sin crear notas. */
    public function subir(Request $request, Actividad $actividad, EnsayoExamen $ensayo): View|RedirectResponse
    {
        $this->autorizar($actividad);
        $request->validate(['entrega' => ['required', 'file', 'max:2048']]);
        try {
            $r = $ensayo->procesar($actividad, $request->user(), (string) file_get_contents($request->file('entrega')->getRealPath()));
        } catch (RuntimeException $e) {
            return back()->withErrors(['entrega' => $e->getMessage()]);
        }

        return view('calificador.ensayo-resultado', $r + ['actividad' => $actividad]);
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
