<?php

namespace App\Http\Controllers;

use App\IA\ConstructorPrompt;
use App\Models\Materia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Capas del prompt (sección 8): perfil y plantillas del docente, estilo por materia. */
class PlantillasIaController extends Controller
{
    public const CAMPOS_PERFIL = ['institucion' => 'Institución', 'programa' => 'Programa', 'ciudad' => 'Ciudad', 'idioma' => 'Idioma y trato',
        'perfil_estudiantes' => 'Perfil del grupo', 'reglas_escritura' => 'Reglas de estilo', 'restricciones' => 'Prohibido'];

    public const CAMPOS_ESTILO = ['unidades' => 'Unidades', 'alcance_grupo' => 'Alcance real por grupo (qué ha visto cada uno)',
        'herramientas' => 'Lenguaje o técnica', 'tono' => 'Tono', 'tipo_verificacion' => 'Cómo se verifica', 'glosario' => 'Vocabulario propio'];

    /** Plantillas editables: las capas 1 y 2 y la capa 3 de cada generador del catálogo (7.4). */
    public const CLAVES = ['contexto' => 'Capa 1 — Contexto general', 'materia' => 'Capa 2 — Estilo por materia',
        'banco' => 'Capa 3 — Tarea: generar cuestionario o parcial', 'rubrica' => 'Capa 3 — Tarea: generar rúbrica',
        'unidad' => 'Capa 3 — Tarea: planear una unidad didáctica',
        'ejemplos' => 'Capa 3 — Tarea: ejemplos prácticos resueltos',
        'debate' => 'Capa 3 — Tarea: preguntas de debate',
        'vocabulario' => 'Capa 3 — Tarea: lista de vocabulario',
        'temario' => 'Capa 3 — Tarea: extraer el temario del plan', 'sugerencias' => 'Capa 3 — Tarea: sugerir organización de la clase'];

    public function edit(Request $request, ConstructorPrompt $prompts): View
    {
        return view('ia.plantillas', [
            'perfil' => $prompts->perfil($request->user()),
            'plantillas' => collect(array_keys(self::CLAVES))->mapWithKeys(fn ($c) => [$c => $prompts->plantilla($request->user(), $c)]),
        ]);
    }

    public function perfil(Request $request): RedirectResponse
    {
        $d = $request->validate(collect(self::CAMPOS_PERFIL)->mapWithKeys(fn ($_, $k) => [$k => ['nullable', 'string', 'max:500']])->all());
        $request->user()->update(['perfil_ia' => $d]);

        return back()->with('estado', 'Perfil guardado: se usa en la capa 1 de todos los prompts.');
    }

    public function plantilla(Request $request, ConstructorPrompt $prompts): RedirectResponse
    {
        $d = $request->validate(['clave' => ['required', Rule::in(array_keys(self::CLAVES))], 'cuerpo' => ['required', 'string', 'max:20000']]);
        $p = $prompts->guardarPlantilla($request->user(), $d['clave'], $d['cuerpo']);

        return back()->with('estado', "Plantilla «{$d['clave']}» guardada como versión {$p->version}.");
    }

    public function restaurar(Request $request, ConstructorPrompt $prompts): RedirectResponse
    {
        $d = $request->validate(['clave' => ['required', Rule::in(array_keys(self::CLAVES))]]);
        $p = $prompts->guardarPlantilla($request->user(), $d['clave'], config("ia.plantillas.{$d['clave']}"));

        return back()->with('estado', "Plantilla «{$d['clave']}» restaurada a la de fábrica (versión {$p->version}).");
    }

    public function estilo(Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('ia.estilo', ['materia' => $materia]);
    }

    public function guardarEstilo(Request $request, Materia $materia): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(collect(self::CAMPOS_ESTILO)->mapWithKeys(fn ($_, $k) => [$k => ['nullable', 'string', 'max:2000']])->all()
            + ['modalidad_practica' => ['required', Rule::in(['integrada', 'anexo', 'ninguna'])]]);
        $materia->update(['estilo' => collect($d)->except('modalidad_practica')->all(), 'modalidad_practica' => $d['modalidad_practica']]);

        return back()->with('estado', 'Estilo de la materia guardado: se usa en la capa 2 de sus prompts.');
    }
}
