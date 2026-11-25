<?php

namespace App\Http\Controllers;

use App\Models\Estudiante;
use App\Models\Grupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Corrección de datos de un estudiante y retiro del grupo, sin borrar sus notas. */
class EstudianteController extends Controller
{
    public function edit(Grupo $grupo, Estudiante $estudiante): View
    {
        $this->autorizar($grupo, $estudiante);

        return view('estudiantes.editar', [
            'grupo' => $grupo->load('dictado.materia'),
            'estudiante' => $estudiante->makeVisible(['email', 'telefono', 'observacion']),
            'activo' => (bool) $grupo->estudiantes()->whereKey($estudiante->id)->first()?->pivot->activo,
        ]);
    }

    public function update(Request $request, Grupo $grupo, Estudiante $estudiante): RedirectResponse
    {
        $this->autorizar($grupo, $estudiante);
        $datos = $request->validate([
            'apellidos' => ['required', 'string', 'max:120'],
            'nombres' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'activo' => ['required', 'boolean'],
        ]);

        $estudiante->update([
            ...collect($datos)->except('activo')->map(fn ($v) => is_string($v) ? trim($v) : $v)->all(),
            'revisar_nombre' => false,
        ]);
        $grupo->estudiantes()->updateExistingPivot($estudiante->id, ['activo' => $datos['activo']]);

        return redirect()->route('registro', $grupo)->with('estado',
            $datos['activo'] ? "Datos de {$estudiante->nombreCompleto()} actualizados."
                : "{$estudiante->nombreCompleto()} quedó retirado del grupo. Sus notas se conservan; puede reactivarlo cuando quiera.");
    }

    private function autorizar(Grupo $grupo, Estudiante $estudiante): void
    {
        Gate::authorize('gestionar', $grupo);
        abort_unless($grupo->estudiantes()->whereKey($estudiante->id)->exists(), 404);
    }
}
