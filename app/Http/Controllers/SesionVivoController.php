<?php

namespace App\Http\Controllers;

use App\Evaluacion\SesionEnVivo;
use App\Models\Actividad;
use App\Models\SesionVivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/** Lado del docente de la sesión en vivo: abrirla, proyectar el código y seguir al grupo (sección 13). */
class SesionVivoController extends Controller
{
    public function __construct(private readonly SesionEnVivo $vivo) {}

    public function abrir(Request $request, Actividad $actividad): RedirectResponse
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
        try {
            $s = $this->vivo->abrir($actividad, $request->user(), (int) $request->input('banco', 1));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['vivo' => $e->getMessage()]);
        }

        return redirect()->route('calificador.vivo.panel', $s)->with('estado', "Sesión en vivo abierta. Código: {$s->codigo}");
    }

    public function panel(SesionVivo $sesion): View
    {
        $this->autorizar($sesion);

        return view('vivo.panel', ['sesion' => $sesion->load('actividad.componente.division.dictado.materia')]);
    }

    public function proyectar(SesionVivo $sesion): View
    {
        $this->autorizar($sesion);

        return view('vivo.proyectar', ['sesion' => $sesion->load('actividad'), 'url' => SesionEnVivo::url($sesion)]);
    }

    private function autorizar(SesionVivo $sesion): void
    {
        Gate::authorize('gestionar', $sesion->actividad->componente->division->dictado);
    }
}
