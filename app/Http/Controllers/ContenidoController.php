<?php

namespace App\Http\Controllers;

use App\Archivos\ExtractorTexto;
use App\Models\Contenido;
use App\Models\Materia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Repositorio de contenido de la materia: el contexto de la IA (módulo 4). */
class ContenidoController extends Controller
{
    public function index(Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('contenido.index', ['materia' => $materia, 'contenidos' => $materia->contenidos()->latest()->get()]);
    }

    public function store(Request $request, Materia $materia, ExtractorTexto $extractor): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $request->validate([
            'archivos' => ['required', 'array', 'max:20'],
            'archivos.*' => ['file', 'max:20480', function ($a, $f, $falla) {
                if (! in_array(strtolower($f->getClientOriginalExtension()), ExtractorTexto::EXTENSIONES, true)) {
                    $falla('Formato no admitido: '.$f->getClientOriginalName().'. Use PDF, Word, PowerPoint o texto.');
                }
            }],
            'tipo' => ['required', Rule::in(['plan_curricular', 'syllabus', 'presentacion', 'lectura', 'codigo', 'guia', 'otro'])],
        ]);

        $cargados = [];
        $avisos = [];
        foreach ($request->file('archivos') as $f) {
            $ext = strtolower($f->getClientOriginalExtension());
            try {
                $texto = $extractor->extraer($f->getRealPath(), $ext);
            } catch (Throwable $e) {
                $avisos[] = "{$f->getClientOriginalName()}: no se pudo leer el texto ({$e->getMessage()}).";

                continue;
            }
            if (mb_strlen($texto) < 50) {
                $avisos[] = "{$f->getClientOriginalName()}: casi no tiene texto (¿es un PDF escaneado?). No sirve como contexto.";
            }
            $sha = hash_file('sha256', $f->getRealPath());
            $ruta = $f->storeAs("contenido/{$materia->id}", $sha.'.'.$ext, 'local');
            $materia->contenidos()->create([
                'titulo' => pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME),
                'tipo' => $request->input('tipo'),
                'ruta' => $ruta,
                'mime' => $f->getClientMimeType(),
                'sha256' => $sha,
                'texto_extraido' => $texto,
            ]);
            $cargados[] = $f->getClientOriginalName();
        }

        return back()->with('estado', count($cargados).' archivo(s) cargado(s).')->with('avisos', $avisos);
    }

    public function show(Contenido $contenido): View
    {
        Gate::authorize('gestionar', $contenido->materia);

        return view('contenido.ver', ['contenido' => $contenido]);
    }

    public function destroy(Contenido $contenido): RedirectResponse
    {
        Gate::authorize('gestionar', $contenido->materia);
        Storage::disk('local')->delete($contenido->ruta);
        $contenido->delete();

        return back()->with('estado', "«{$contenido->titulo}» eliminado del repositorio.");
    }
}
