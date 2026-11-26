<?php

namespace App\Http\Controllers;

use App\Calificacion\CalificadorEntrega;
use App\Calificacion\EntregasArchivo;
use App\Models\Actividad;
use App\Models\Entrega;
use App\Models\Rubrica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trabajos y talleres que no son el examen digital (5.7 formato de entrega): se cargan los
 * archivos, se califican con la rúbrica o a mano, y la nota pasa al registro como la del examen.
 */
class EntregaArchivoController extends Controller
{
    public function __construct(private readonly EntregasArchivo $archivos, private readonly CalificadorEntrega $calificador) {}

    public function index(Actividad $actividad): View
    {
        $this->autorizar($actividad);
        $actividad->load('componente.division.dictado.materia', 'rubrica', 'grupos');
        $entregas = $actividad->entregas()->where('tipo', 'archivo')->with('integrantes', 'alertas')->get()
            ->sortBy(fn ($e) => [$e->integrantes->isEmpty() ? 0 : 1, $e->integrantes->first()?->apellidos])->values();
        $dictado = $actividad->componente->division->dictado;

        return view('archivos.index', [
            'actividad' => $actividad,
            'entregas' => $entregas,
            'escala' => $dictado->escala(),
            'rubricas' => Rubrica::query()->where('materia_id', $dictado->materia_id)->orderBy('titulo')->get(['id', 'titulo']),
            'sinEntrega' => $dictado->estudiantes()->whereNotIn('id', $entregas->flatMap->integrantes->pluck('id'))->orderBy('apellidos')->get(),
            'calificador' => $this->calificador,
        ]);
    }

    public function configurar(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $materiaId = $actividad->componente->division->dictado->materia_id;
        $d = $request->validate([
            'formato_entrega' => ['required', Rule::in(array_keys(EntregasArchivo::FORMATOS))],
            'rubrica_id' => ['nullable', Rule::exists('rubricas', 'id')->where('materia_id', $materiaId)],
            'es_grupal' => ['boolean'],
            'tamano_grupo' => ['nullable', 'integer', 'min:2', 'max:10'],
            'tope_exceso' => ['nullable', 'numeric', 'min:0', 'max:'.$actividad->valor_max],
        ]);
        $d['es_grupal'] = (bool) ($d['es_grupal'] ?? false);
        if (! $d['es_grupal']) {
            [$d['tamano_grupo'], $d['tope_exceso']] = [null, null];
        }
        $actividad->update($d);

        return back()->with('estado', 'Configuración guardada.');
    }

    public function subir(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $request->validate(['archivos' => ['required', 'array', 'max:80'], 'archivos.*' => ['file', 'max:'.EntregasArchivo::MAX_KB]],
            ['archivos.*.max' => 'Cada archivo puede pesar hasta 20 MB.']);

        $resultados = [];
        foreach ($request->file('archivos') as $archivo) {
            $r = $this->archivos->recibir($actividad, $archivo);
            $resultados[] = ['estado' => $r['estado'], 'mensaje' => $r['mensaje']];
        }

        return back()->with('resultados', $resultados);
    }

    public function calificar(Entrega $entrega): View
    {
        $actividad = $this->deArchivo($entrega);
        $actividad->load('componente.division.dictado.materia', 'rubrica');
        $entrega->load('integrantes');
        $orden = $actividad->entregas()->where('tipo', 'archivo')->with('integrantes')->get()
            ->sortBy(fn ($e) => [$e->integrantes->isEmpty() ? 0 : 1, $e->integrantes->first()?->apellidos])->pluck('id')->values();
        $pos = $orden->search($entrega->id);
        $siguientePendiente = $actividad->entregas()->where('tipo', 'archivo')->whereNull('puntaje')->where('id', '!=', $entrega->id)
            ->get()->sortBy(fn ($e) => $orden->search($e->id))->first();

        return view('archivos.calificar', [
            'actividad' => $actividad,
            'entrega' => $entrega,
            'escala' => $actividad->componente->division->dictado->escala(),
            'nota' => $this->calificador->nota($entrega),
            'estudiantes' => $actividad->componente->division->dictado->estudiantes()->orderBy('apellidos')->orderBy('nombres')->get(),
            'seleccion' => ($entrega->desglose['rubrica_id'] ?? null) === $actividad->rubrica_id ? ($entrega->desglose['rubrica']['seleccion'] ?? []) : [],
            'anterior' => $pos > 0 ? $orden[$pos - 1] : null,
            'siguiente' => $pos !== false && $pos < $orden->count() - 1 ? $orden[$pos + 1] : null,
            'siguientePendiente' => $siguientePendiente?->id,
        ]);
    }

    public function guardar(Request $request, Entrega $entrega): RedirectResponse
    {
        $actividad = $this->deArchivo($entrega);
        $d = $request->validate([
            'integrantes' => ['nullable', 'array', 'max:10'], 'integrantes.*' => ['integer'],
            'nivel' => ['nullable', 'array'], 'nivel.*' => ['nullable', 'integer', 'min:0'],
            'puntaje' => ['nullable', 'string', 'max:20'],
            'comentario' => ['nullable', 'string', 'max:3000'],
            'despues' => ['nullable', Rule::in(['quedarse', 'siguiente'])],
        ]);

        $this->archivos->integrantes($entrega, $d['integrantes'] ?? []);
        try {
            $this->archivos->calificar($entrega->fresh(), $actividad->rubrica ? ($d['nivel'] ?? []) : null, $d['puntaje'] ?? null, $d['comentario'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['puntaje' => $e->getMessage()])->withInput();
        }

        $siguiente = $request->input('despues') === 'siguiente'
            ? $actividad->entregas()->where('tipo', 'archivo')->whereNull('puntaje')->where('id', '!=', $entrega->id)->value('id')
            : null;

        return $siguiente
            ? redirect()->route('calificador.archivos.calificar', $siguiente)->with('estado', 'Calificación guardada. Sigue la próxima sin calificar.')
            : redirect()->route('calificador.archivos.calificar', $entrega)->with('estado', 'Calificación guardada.');
    }

    /** El archivo, solo para el docente de la materia; en línea si es PDF. */
    public function archivo(Entrega $entrega): StreamedResponse
    {
        $this->deArchivo($entrega);
        abort_unless(Storage::disk('local')->exists($entrega->ruta), 404);
        $pdf = $entrega->archivo_mime === 'application/pdf';

        // Un archivo libre nunca se interpreta en el navegador: se descarga
        return Storage::disk('local')->response($entrega->ruta, $entrega->archivo_nombre, [
            'Content-Type' => $pdf ? 'application/pdf' : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], $pdf ? 'inline' : 'attachment');
    }

    public function eliminar(Entrega $entrega): RedirectResponse
    {
        $actividad = $this->deArchivo($entrega);
        abort_if($actividad->notas()->where('entrega_id', $entrega->id)->exists(), 422, 'La entrega ya pasó al registro.');
        Storage::disk('local')->delete($entrega->ruta);
        $entrega->delete();

        return redirect()->route('calificador.archivos', $actividad)->with('estado', 'Entrega eliminada.');
    }

    private function deArchivo(Entrega $entrega): Actividad
    {
        abort_unless($entrega->tipo === 'archivo', 404);
        $this->autorizar($entrega->actividad);

        return $entrega->actividad;
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
