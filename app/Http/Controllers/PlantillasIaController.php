<?php

namespace App\Http\Controllers;

use App\IA\ConstructorPrompt;
use App\Models\GeneracionIa;
use App\Models\Generador;
use App\Models\Materia;
use App\Models\PlantillaPrompt;
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
        'banco' => 'Capa 3 — Tarea: generar cuestionario o parcial',
        'variante' => 'Capa 3 — Tarea: versión B o segundo banco de un examen', 'rubrica' => 'Capa 3 — Tarea: generar rúbrica',
        'unidad' => 'Capa 3 — Tarea: planear una unidad didáctica',
        'ejemplos' => 'Capa 3 — Tarea: ejemplos prácticos resueltos',
        'debate' => 'Capa 3 — Tarea: preguntas de debate',
        'presentacion' => 'Capa 3 — Tarea: presentación de clase',
        'vocabulario' => 'Capa 3 — Tarea: lista de vocabulario',
        'proyectos' => 'Capa 3 — Tarea: ideas de proyectos',
        'apuntes' => 'Capa 3 — Tarea: texto informativo o apunte',
        'documento' => 'Capa 3 — Tarea: documento de un generador configurable (guía, manual, boletín y los suyos)',
        'comentarios' => 'Capa 3 — Tarea: comentarios de respuestas abiertas (voz del docente)',
        'puntajes' => 'Capa 3 — Tarea: puntaje sugerido para respuestas abiertas',
        'temario' => 'Capa 3 — Tarea: extraer el temario del plan', 'sugerencias' => 'Capa 3 — Tarea: sugerir organización de la clase'];

    public function edit(Request $request, ConstructorPrompt $prompts): View
    {
        return view('ia.plantillas', [
            'perfil' => $prompts->perfil($request->user()),
            'plantillas' => collect(array_keys(self::CLAVES))->mapWithKeys(fn ($c) => [$c => $prompts->plantilla($request->user(), $c)]),
            'versiones' => PlantillaPrompt::query()->where('user_id', $request->user()->id)->whereNull('materia_id')
                ->orderByDesc('version')->get(['id', 'clave', 'version', 'cuerpo', 'created_at', 'funciono', 'nota_funciono'])->groupBy('clave'),
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
        // 8.4.1: un campo que la plataforma no llena haría fallar el prompt al generar; se avisa al guardar
        $disponibles = self::campos(config("ia.plantillas.{$d['clave']}"));
        $usados = self::campos($d['cuerpo']);
        if ($desconocidos = array_diff($usados, $disponibles)) {
            return back()->withInput()->withErrors(['cuerpo' => 'La plantilla usa campos que no existen: {{'.implode('}}, {{', $desconocidos).'}}. '
                .'Los disponibles son {{'.implode('}}, {{', $disponibles).'}}.'])->with('clave_error', $d['clave']);
        }
        $p = $prompts->guardarPlantilla($request->user(), $d['clave'], $d['cuerpo']);
        $faltan = array_diff($disponibles, $usados);

        return back()->with('estado', "Plantilla «{$d['clave']}» guardada como versión {$p->version}."
            .($faltan ? ' Ya no usa {{'.implode('}}, {{', $faltan).'}}: la IA no recibirá ese dato.' : ''));
    }

    /** 8.4.4: vuelve a una versión anterior; queda como versión nueva, sin borrar la historia. */
    public function volver(Request $request, ConstructorPrompt $prompts): RedirectResponse
    {
        $d = $request->validate(['version_id' => ['required', 'integer']]);
        $anterior = PlantillaPrompt::query()->where('user_id', $request->user()->id)->whereNull('materia_id')->findOrFail($d['version_id']);
        $p = $prompts->guardarPlantilla($request->user(), $anterior->clave, $anterior->cuerpo);

        return back()->with('estado', "Plantilla «{$anterior->clave}»: se volvió a la versión {$anterior->version} (queda como versión {$p->version}).");
    }

    /** @return array<int, string> campos {{…}} de una plantilla, sin repetir */
    public static function campos(?string $cuerpo): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', (string) $cuerpo, $m);

        return array_values(array_unique($m[1]));
    }

    public function restaurar(Request $request, ConstructorPrompt $prompts): RedirectResponse
    {
        $d = $request->validate(['clave' => ['required', Rule::in(array_keys(self::CLAVES))]]);
        $p = $prompts->guardarPlantilla($request->user(), $d['clave'], config("ia.plantillas.{$d['clave']}"));

        return back()->with('estado', "Plantilla «{$d['clave']}» restaurada a la de fábrica (versión {$p->version}).");
    }

    /** 8.4.5: lo que funcionó, para volver a usarlo en cualquier materia. */
    public function biblioteca(Request $request): View
    {
        return view('ia.biblioteca', [
            'plantillas' => PlantillaPrompt::query()->where('user_id', $request->user()->id)->whereNull('materia_id')->where('funciono', true)->orderBy('clave')->orderByDesc('version')->get(),
            'vigentes' => collect(array_keys(self::CLAVES))->mapWithKeys(fn ($c) => [$c => app(ConstructorPrompt::class)->plantilla($request->user(), $c)['id']]),
            'generadores' => Generador::query()->where('user_id', $request->user()->id)->where('funciono', true)->orderBy('nombre')->get(),
        ]);
    }

    /** Marca o desmarca una versión de plantilla como «funcionó» (8.4.5). */
    public function funciono(Request $request, PlantillaPrompt $plantilla): RedirectResponse
    {
        abort_unless($plantilla->user_id === $request->user()->id, 403);
        $d = $request->validate(['funciono' => ['required', 'boolean'], 'nota_funciono' => ['nullable', 'string', 'max:300']]);
        $plantilla->update(['funciono' => (bool) $d['funciono'], 'nota_funciono' => $d['funciono'] ? ($d['nota_funciono'] ?? null) : null]);

        return back()->with('estado', $d['funciono'] ? "La versión {$plantilla->version} de «{$plantilla->clave}» quedó en la biblioteca." : "La versión {$plantilla->version} de «{$plantilla->clave}» salió de la biblioteca.");
    }

    /**
     * Desde un material aprobado: la plantilla de tarea con la que se generó funcionó. Si era la de fábrica,
     * se guarda como versión propia para poder marcarla.
     */
    public function generacionFunciono(Request $request, GeneracionIa $generacion, ConstructorPrompt $prompts): RedirectResponse
    {
        abort_if($generacion->materia === null, 404);
        Gate::authorize('gestionar', $generacion->materia);
        $clave = $generacion->tarea;
        $version = (int) ($generacion->plantillas[$clave] ?? 0);
        abort_unless(array_key_exists($clave, self::CLAVES), 422, 'Esa tarea no tiene plantilla propia.');
        $p = $version > 0
            ? PlantillaPrompt::query()->where('user_id', $request->user()->id)->whereNull('materia_id')->where('clave', $clave)->where('version', $version)->first()
            : null;
        $p ??= $prompts->guardarPlantilla($request->user(), $clave, config("ia.plantillas.{$clave}"));
        $p->update(['funciono' => true, 'nota_funciono' => $request->validate(['nota_funciono' => ['nullable', 'string', 'max:300']])['nota_funciono'] ?? "Funcionó con «{$generacion->resultado?->titulo}»"]);

        return back()->with('estado', "La plantilla «{$clave}» (versión {$p->version}) quedó en la biblioteca como una que funcionó.");
    }

    public function estilo(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('ia.estilo', ['materia' => $materia,
            'otras' => Materia::query()->where('user_id', $materia->user_id)->whereKeyNot($materia->id)->whereNotNull('estilo')->orderBy('nombre')->get(['id', 'nombre'])]);
    }

    /** 8.4.5: el estilo (capa 2) que funcionó en otra materia, como punto de partida. */
    public function copiarEstilo(Request $request, Materia $materia): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $origen = Materia::query()->findOrFail($request->validate(['origen' => ['required', 'integer']])['origen']);
        Gate::authorize('gestionar', $origen);
        $materia->update(['estilo' => $origen->estilo]);

        return back()->with('estado', "Se copió el estilo de {$origen->nombre}. Ajuste lo propio de esta materia (unidades, alcance, herramientas).");
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
