<?php

namespace App\Http\Controllers;

use App\Estudiantes\FilaLista;
use App\Estudiantes\ImportadorListaClase;
use App\Models\Grupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Cargue masivo de estudiantes con la plantilla oficial (3.4): subir → vista previa → confirmar. */
class ImportacionController extends Controller
{
    public function create(Grupo $grupo): View
    {
        Gate::authorize('gestionar', $grupo);

        return view('importacion.subir', ['grupo' => $grupo->load('dictado.materia')]);
    }

    public function plantilla(ImportadorListaClase $importador): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        $importador->plantilla($ruta);

        return response()->download($ruta, 'plantilla_lista_clase.xlsx')->deleteFileAfterSend();
    }

    public function previsualizar(Request $request, Grupo $grupo, ImportadorListaClase $importador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        $request->validate(['archivo' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt']]);

        $archivo = $request->file('archivo');
        try {
            $crudas = $importador->leer($archivo->getRealPath(), $archivo->getClientOriginalExtension());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['archivo' => $e->getMessage()]);
        }

        $analisis = $importador->analizar($grupo, $crudas);

        // Nada se importa hasta que el docente confirme (3.4.2): la vista previa queda en sesión
        $token = (string) Str::uuid();
        $request->session()->put("importacion.{$token}", [
            'grupo_id' => $grupo->id,
            'filas' => array_map(fn (FilaLista $f) => $f->toArray(), $analisis['filas']),
        ]);

        return view('importacion.previa', [
            'grupo' => $grupo->load('dictado.materia'),
            'token' => $token,
            ...$analisis,
        ]);
    }

    public function confirmar(Request $request, Grupo $grupo, ImportadorListaClase $importador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        $datos = $request->session()->pull('importacion.'.$request->string('token'));

        if ($datos === null || $datos['grupo_id'] !== $grupo->id) {
            return redirect()->route('importacion.create', $grupo)
                ->withErrors(['archivo' => 'La vista previa expiró. Vuelva a subir el archivo.']);
        }

        $reporte = $importador->confirmar($grupo, array_map(fn ($f) => FilaLista::fromArray($f), $datos['filas']));

        return view('importacion.reporte', ['grupo' => $grupo->load('dictado.materia'), ...$reporte]);
    }
}
