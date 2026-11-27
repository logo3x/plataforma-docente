<?php

namespace App\Http\Controllers;

use App\Historial\HistorialMateria;
use App\Models\Materia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Historial de actividades de la materia y del periodo (7.3.4). */
class HistorialController extends Controller
{
    public function index(Request $request, Materia $materia, HistorialMateria $historial): View
    {
        Gate::authorize('gestionar', $materia);
        $f = $request->validate([
            'tipo' => ['nullable', Rule::in(array_keys(HistorialMateria::TIPOS))],
            'dictado' => ['nullable', 'integer'],
            'origen' => ['nullable', Rule::in(['ia', 'manual'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return view('historial.index', [
            'materia' => $materia,
            'otrasMaterias' => Materia::query()->where('user_id', $materia->user_id)->whereKeyNot($materia->id)->orderBy('nombre')->get(['id', 'nombre']),
            'entradas' => $historial->entradas($materia, $f),
            'conteos' => $historial->conteos($materia),
            'dictados' => $materia->dictados()->latest()->get(),
            'filtros' => $f,
        ]);
    }

    /** Copia una actividad para ajustarla sin tocar la original y abre la copia para editarla. */
    public function copiar(Request $request, Materia $materia, HistorialMateria $historial): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['tipo' => ['required', Rule::in(array_keys(HistorialMateria::TIPOS))], 'id' => ['required', 'integer'],
            'destino' => ['nullable', 'integer']]);
        abort_if($d['tipo'] === 'evaluacion', 422, 'Las evaluaciones se vuelven a aplicar; no se copian.');
        // 7.9: a otra materia, solo del mismo docente
        $destino = filled($d['destino'] ?? null) ? Materia::query()->findOrFail($d['destino']) : null;
        if ($destino) {
            Gate::authorize('gestionar', $destino);
        }
        $copia = $historial->copiar($materia, $d['tipo'], $d['id'], $destino);

        return redirect($historial->urlEdicion($copia))->with('estado', $destino && $destino->isNot($materia)
            ? "Copiado a {$destino->nombre}: «{$copia->titulo}». Revise lo que dependa de la materia (temas, nivel); la original no cambia."
            : "Copia creada: «{$copia->titulo}». Ajústela; la original no cambia.");
    }
}
