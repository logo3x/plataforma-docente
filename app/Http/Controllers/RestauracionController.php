<?php

namespace App\Http\Controllers;

use App\Dictados\RestauradorDictado;
use App\Models\Dictado;
use App\Models\Restauracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use InvalidArgumentException;

/** Restaurar un respaldo de periodo (3.1.10): subir, revisar la vista previa y confirmar. Nunca sobrescribe. */
class RestauracionController extends Controller
{
    public function create(): View
    {
        return view('restauracion.subir');
    }

    /** Paso 1: lee y revisa el archivo sin tocar nada. */
    public function revisar(Request $request, RestauradorDictado $restaurador): View|RedirectResponse
    {
        $request->validate(['respaldo' => ['required', 'file', 'max:20480']]);
        $contenido = (string) file_get_contents($request->file('respaldo')->getRealPath());
        $datos = json_decode($contenido, true);
        if (! is_array($datos)) {
            return back()->withErrors(['respaldo' => 'El archivo no es JSON válido.']);
        }
        $sha = hash('sha256', $contenido);
        Storage::disk('local')->put($this->ruta($request, $sha), $contenido);
        $request->session()->put('restauracion.sha', $sha);

        return view('restauracion.revisar', $restaurador->revisar($request->user(), $datos) + ['sha' => $sha]);
    }

    /** Paso 2: crea el periodo restaurado. */
    public function restaurar(Request $request, RestauradorDictado $restaurador): RedirectResponse
    {
        $d = $request->validate(['periodo' => ['required', 'string', 'max:20'], 'sha' => ['required', 'string', 'size:64']]);
        abort_unless($request->session()->get('restauracion.sha') === $d['sha'] && Storage::disk('local')->exists($this->ruta($request, $d['sha'])), 410,
            'El archivo revisado ya no está disponible. Súbalo de nuevo.');

        $datos = json_decode(Storage::disk('local')->get($this->ruta($request, $d['sha'])), true);
        try {
            $dictado = $restaurador->restaurar($request->user(), $datos, $d['periodo'], $d['sha']);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('restauracion.create')->withErrors(['respaldo' => $e->getMessage()]);
        }
        Storage::disk('local')->delete($this->ruta($request, $d['sha']));
        $request->session()->forget('restauracion.sha');

        return redirect()->route('dictados.show', $dictado)
            ->with('estado', "Periodo {$dictado->periodo} restaurado: {$dictado->estudiantes()->count()} estudiantes y sus notas. Revise el registro antes de seguir.");
    }

    /** Bitácora tal como venía en el respaldo, para consulta. */
    public function bitacora(Dictado $dictado): View
    {
        Gate::authorize('gestionar', $dictado);

        return view('restauracion.bitacora', ['dictado' => $dictado->load('materia'), 'restauracion' => Restauracion::query()->where('dictado_id', $dictado->id)->latest()->firstOrFail()]);
    }

    /** Cada docente tiene su propia carpeta temporal: nadie puede confirmar el archivo de otro. */
    private function ruta(Request $request, string $sha): string
    {
        return "restauraciones/{$request->user()->id}/{$sha}.json";
    }
}
