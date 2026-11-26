<?php

namespace App\Http\Controllers;

use App\Calificacion\PoliticaAlertas;
use App\Models\Actividad;
use App\Models\Alerta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Política frente a las alertas y su revisión por actividad (decisión pendiente 6). */
class AlertasController extends Controller
{
    public function __construct(private readonly PoliticaAlertas $politica) {}

    public function politica(Request $request): View
    {
        return view('alertas.politica', ['politica' => $this->politica->de($request->user())]);
    }

    public function guardarPolitica(Request $request): RedirectResponse
    {
        $this->politica->guardar($request->user(), (array) $request->input('politica', []));

        return back()->with('estado', 'Política de alertas guardada. Rige para todas sus actividades, también las ya calificadas.');
    }

    public function index(Request $request, Actividad $actividad): View
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);

        return view('alertas.actividad', ['actividad' => $actividad->load('componente.division.dictado.materia')] + $this->politica->deActividad($actividad, $request->user()));
    }

    public function revisar(Request $request, Alerta $alerta): RedirectResponse
    {
        Gate::authorize('gestionar', $alerta->actividad->componente->division->dictado);
        $d = $request->validate(['decision' => ['required', Rule::in(array_keys(PoliticaAlertas::DECISIONES))],
            'nota' => ['nullable', 'string', 'max:500'], 'informar' => ['boolean']]);
        $this->politica->revisar($alerta, $request->user(), $d['decision'], $d['nota'] ?? null, (bool) ($d['informar'] ?? false));

        return back()->with('estado', 'Alerta revisada.');
    }
}
