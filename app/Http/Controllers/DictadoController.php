<?php

namespace App\Http\Controllers;

use App\Dictados\AltaMateria;
use App\Models\Dictado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DictadoController extends Controller
{
    public function show(Dictado $dictado): View
    {
        Gate::authorize('gestionar', $dictado);

        $dictado->load(['materia', 'divisiones.componentes', 'grupos' => fn ($q) => $q->withCount([
            'estudiantes' => fn ($e) => $e->where('grupo_estudiante.activo', true),
        ])]);

        return view('dictados.ver', compact('dictado'));
    }

    /** Dictado nuevo que hereda la estructura del actual (2.6.3). */
    public function store(Request $request, Dictado $dictado, AltaMateria $alta): RedirectResponse
    {
        Gate::authorize('gestionar', $dictado);

        $datos = $request->validate([
            'periodo' => ['required', 'string', 'max:20',
                Rule::unique('dictados')->where('materia_id', $dictado->materia_id)],
        ]);

        $nuevo = $alta->nuevoDictado($dictado, $datos['periodo']);

        return redirect()->route('dictados.show', $nuevo)
            ->with('estado', "Dictado {$nuevo->periodo} creado con la estructura de {$dictado->periodo}. Las notas y la lista no se copian.");
    }
}
