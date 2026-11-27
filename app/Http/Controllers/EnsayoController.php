<?php

namespace App\Http\Controllers;

use App\Evaluacion\EnsayoExamen;
use App\Evaluacion\GeneradorExamen;
use App\Evaluacion\ImportadorBanco;
use App\Evaluacion\PruebasMinimas;
use App\Models\Actividad;
use App\Models\Ensayo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

/** Modo de ensayo de una actividad (5.6): pruebas mínimas, examen de ensayo y cierre del ciclo con el calificador. */
class EnsayoController extends Controller
{
    public function show(Actividad $actividad, PruebasMinimas $pruebas): View
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404, 'Esta actividad no tiene examen digital.');
        $resultados = $pruebas->ejecutar($actividad);

        return view('calificador.ensayo', [
            'actividad' => $actividad->load('artefacto', 'componente.division.dictado.materia'),
            'pruebas' => $resultados,
            'aprobadas' => PruebasMinimas::aprobadas($resultados),
            'ensayos' => Ensayo::query()->where('actividad_id', $actividad->id)->latest()->get(),
        ]);
    }

    /** 9.2.8: los retos que se ejecutan, con su solución modelo y su código inicial, para probarlos en el navegador. */
    public function modelos(Actividad $actividad): JsonResponse
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404);
        $retos = $actividad->artefacto->items()->with('pregunta')->whereNull('anulada_en')->orderBy('banco')->orderBy('orden')->get()
            ->filter(fn ($it) => $it->pregunta->tipo === 'reto' && ($it->pregunta->clave['lenguaje'] ?? null) !== 'php' && ! empty($it->pregunta->clave['solucion']))
            ->map(fn ($it) => [
                'item' => 'Ítem '.($it->orden + 1).($it->banco !== 'A' ? " ({$it->banco})" : ''),
                'lenguaje' => $it->pregunta->enunciado['lenguaje'] ?? 'javascript',
                'esquema' => $it->pregunta->enunciado['esquema'] ?? null,
                'solucion' => $it->pregunta->clave['solucion'],
                'inicial' => $it->pregunta->enunciado['plantilla_codigo'] ?? '',
                'verificaciones' => collect($it->pregunta->clave['verificaciones'])->map(fn ($v) => ImportadorBanco::criterio($v) + ['id' => $v['id'], 'fraccion' => (string) $v['fraccion']])->values(),
            ])->values();

        return response()->json(['tiempo_ms' => (int) ($actividad->artefacto->configuracion['tiempo_ejecucion_ms'] ?? 2000), 'retos' => $retos]);
    }

    public function examen(Request $request, Actividad $actividad, GeneradorExamen $generador): Response
    {
        $this->autorizar($actividad);
        abort_if($actividad->artefacto_id === null, 404);
        $banco = $request->integer('banco', 1);
        abort_unless(in_array($banco, $actividad->artefacto->bancos(), true), 404);

        return response($generador->html($actividad, ensayo: true, banco: $banco), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ENSAYO_'.$actividad->artefacto->sigla.($banco === 2 ? '_banco2' : '').'.html"',
        ]);
    }

    /** Sube la entrega del examen de ensayo: se descifra y se califica sin crear notas. */
    public function subir(Request $request, Actividad $actividad, EnsayoExamen $ensayo): View|RedirectResponse
    {
        $this->autorizar($actividad);
        $request->validate(['entrega' => ['required', 'file', 'max:2048']]);
        try {
            $r = $ensayo->procesar($actividad, $request->user(), (string) file_get_contents($request->file('entrega')->getRealPath()));
        } catch (RuntimeException $e) {
            return back()->withErrors(['entrega' => $e->getMessage()]);
        }

        return view('calificador.ensayo-resultado', $r + ['actividad' => $actividad]);
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
