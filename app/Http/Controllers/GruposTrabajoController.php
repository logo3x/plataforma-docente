<?php

namespace App\Http\Controllers;

use App\Calificacion\GruposTrabajo;
use App\Models\Actividad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Quién arma los grupos de una actividad grupal y, si es el docente, cuáles son (6.8.1 a 6.8.3). */
class GruposTrabajoController extends Controller
{
    public function __construct(private readonly GruposTrabajo $grupos) {}

    public function edit(Actividad $actividad): View
    {
        $this->autorizar($actividad);
        $actividad->load('componente.division.dictado.materia', 'artefacto', 'grupos');

        return view('calificador.grupos-trabajo', [
            'actividad' => $actividad,
            'modo' => $this->grupos->modo($actividad),
            'porDefecto' => $this->grupos->modoPorDefecto($actividad),
            'forzado' => array_key_exists((string) $actividad->grupos_por, GruposTrabajo::MODOS),
            'candidatos' => $this->grupos->candidatos($actividad),
            'asignacion' => session('propuesta', $this->grupos->asignacion($actividad)),
            'propuesta' => session()->has('propuesta'),
            'conEntregas' => $actividad->entregas()->exists(),
        ]);
    }

    public function update(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $d = $request->validate([
            'grupos_por' => ['required', Rule::in(['auto', ...array_keys(GruposTrabajo::MODOS)])],
            'numero' => ['nullable', 'array'],
            'numero.*' => ['nullable', 'integer', 'between:0,200'],
        ]);
        $actividad->update(['grupos_por' => $d['grupos_por'] === 'auto' ? null : $d['grupos_por']]);

        $mensaje = 'Se guardó quién arma los grupos.';
        if ($this->grupos->modo($actividad) === 'docente') {
            try {
                $r = $this->grupos->guardar($actividad, $d['numero'] ?? []);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['numero' => $e->getMessage()]);
            }
            $sueltos = $this->grupos->candidatos($actividad)->count() - $r['asignados'];
            $mensaje = "{$r['grupos']} ".($r['grupos'] === 1 ? 'grupo guardado' : 'grupos guardados')." con {$r['asignados']} estudiantes"
                .($sueltos > 0 ? "; {$sueltos} sin grupo (si entregan, la entrega queda solo a su nombre y se marca)." : '.')
                .' Descargue el examen de nuevo si ya lo había descargado.';
        }

        return redirect()->route('calificador.grupos', $actividad)->with('estado', $mensaje);
    }

    /** Propone grupos al azar para quienes no tienen; no guarda hasta que el docente lo confirme. */
    public function repartir(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $d = $request->validate(['tamano' => ['required', 'integer', 'between:2,10'], 'grupos_por' => ['nullable', Rule::in(array_keys(GruposTrabajo::MODOS))]]);
        $tamano = (int) $d['tamano'];
        // Proponer grupos solo tiene sentido si los arma el docente: la vía queda elegida
        $actividad->update(['grupos_por' => $d['grupos_por'] ?? 'docente']);

        return redirect()->route('calificador.grupos', $actividad)->with('propuesta', $this->grupos->repartir($actividad, $tamano))
            ->with('estado', "Propuesta de grupos de {$tamano} para quienes no tenían grupo. Revísela y guarde.");
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
