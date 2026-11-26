<?php

namespace App\Http\Controllers;

use App\Estudiantes\EnDosGrupos;
use App\Models\Dictado;
use App\Models\Estudiante;
use App\Models\Grupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/** Estudiantes que aparecen en dos grupos del periodo (3.1.12, decisión pendiente 5). */
class EnDosGruposController extends Controller
{
    public function __construct(private readonly EnDosGrupos $servicio) {}

    public function index(Dictado $dictado): View
    {
        Gate::authorize('gestionar', $dictado);

        return view('dictados.en-dos-grupos', ['dictado' => $dictado->load('materia'), 'casos' => $this->servicio->casos($dictado)]);
    }

    public function resolver(Request $request, Dictado $dictado, Estudiante $estudiante): RedirectResponse
    {
        Gate::authorize('gestionar', $dictado);
        abort_unless($estudiante->dictado_id === $dictado->id, 404);
        $d = $request->validate(['decision' => ['required', 'max:20']]);
        $d['decision'] = (string) $d['decision'];

        if ($d['decision'] === 'ambos') {
            $this->servicio->mantener($estudiante);

            return back()->with('estado', "{$estudiante->nombreCompleto()} sigue en sus grupos; no se le volverá a avisar.");
        }
        $grupo = Grupo::query()->where('dictado_id', $dictado->id)->findOrFail((int) $d['decision']);
        try {
            $r = $this->servicio->resolver($estudiante, $grupo);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        $partes = array_filter([
            "{$estudiante->nombreCompleto()} queda en {$grupo->nombre}".($r['retirado_de'] ? '; retirado de '.implode(', ', $r['retirado_de']).' (sin borrar nada)' : '').'.',
            $r['trasladadas'] ? "{$r['trasladadas']} nota(s) trasladada(s), con constancia en la bitácora." : null,
            $r['conflictos'] ? "{$r['conflictos']} con nota distinta en el destino: no se tocaron, revíselas en el registro." : null,
            $r['sin_equivalente'] ? "{$r['sin_equivalente']} sin actividad equivalente en {$grupo->nombre}: regístrelas a mano." : null,
        ]);

        return back()->with('estado', implode(' ', $partes));
    }
}
