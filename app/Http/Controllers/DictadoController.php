<?php

namespace App\Http\Controllers;

use App\Dictados\AltaMateria;
use App\Dictados\EditorEstructura;
use App\Models\CambioEstructura;
use App\Models\Dictado;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class DictadoController extends Controller
{
    public function show(Dictado $dictado): View
    {
        Gate::authorize('gestionar', $dictado);

        $dictado->load(['materia', 'divisiones.componentes', 'grupos' => fn ($q) => $q->withCount([
            'estudiantes' => fn ($e) => $e->where('grupo_estudiante.activo', true),
        ])]);

        return view('dictados.ver', compact('dictado'));
    }

    /** Dictado nuevo que hereda la estructura del actual (2.6.3). */
    public function store(Request $request, Dictado $dictado, AltaMateria $alta): RedirectResponse
    {
        Gate::authorize('gestionar', $dictado);

        $datos = $request->validate([
            'periodo' => ['required', 'string', 'max:20',
                Rule::unique('dictados')->where('materia_id', $dictado->materia_id)],
        ]);

        $nuevo = $alta->nuevoDictado($dictado, $datos['periodo']);

        return redirect()->route('dictados.show', $nuevo)
            ->with('estado', "Dictado {$nuevo->periodo} creado con la estructura de {$dictado->periodo}. Las notas y la lista no se copian.");
    }

    /** 2.7, 3.1.2: la estructura se edita después del alta, aunque ya haya notas. */
    public function estructura(Dictado $dictado, EditorEstructura $editor): View
    {
        Gate::authorize('gestionar', $dictado);
        $dictado->load('materia');

        return view('dictados.estructura', [
            'dictado' => $dictado,
            'divisiones' => old('divisiones') ? $this->desdeFormulario(old('divisiones')) : $editor->datos($dictado),
            'notas' => $editor->notasCargadas($dictado),
            'historial' => CambioEstructura::query()->where('dictado_id', $dictado->id)->with('usuario')->latest('id')->limit(20)->get(),
        ]);
    }

    public function guardarEstructura(Request $request, Dictado $dictado, EditorEstructura $editor): RedirectResponse
    {
        Gate::authorize('gestionar', $dictado);
        $request->validate([
            'divisiones' => ['required', 'array', 'min:1', 'max:12'],
            'divisiones.*.id' => ['nullable', 'integer'],
            'divisiones.*.nombre' => ['required', 'string', 'max:60'],
            'divisiones.*.peso' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'divisiones.*.fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'divisiones.*.fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            'divisiones.*.componentes' => ['required', 'array', 'min:1', 'max:8'],
            'divisiones.*.componentes.*.id' => ['nullable', 'integer'],
            'divisiones.*.componentes.*.nombre' => ['required', 'string', 'max:60'],
            'divisiones.*.componentes.*.peso' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ]);

        try {
            $cambios = $editor->guardar($dictado, $this->desdeFormulario($request->input('divisiones')), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['divisiones' => $e->getMessage()]);
        }

        return redirect()->route('dictados.estructura', $dictado)->with('estado', $cambios
            ? count($cambios).' '.(count($cambios) === 1 ? 'cambio guardado' : 'cambios guardados').'. Las definitivas ya se calculan con la estructura nueva.'
            : 'No había cambios que guardar.');
    }

    /** En el formulario los pesos van en porcentaje; el editor los quiere en fracción. */
    private function desdeFormulario(array $divisiones): array
    {
        $peso = fn ($p) => (string) BigDecimal::of(is_numeric($p) ? $p : 0)->dividedBy(100, 6)->strippedOfTrailingZeros();

        return array_map(fn ($d) => [
            'id' => ($d['id'] ?? null) ? (int) $d['id'] : null,
            'nombre' => (string) ($d['nombre'] ?? ''),
            'peso' => $peso($d['peso'] ?? 0),
            'fecha_inicio' => ($d['fecha_inicio'] ?? null) ?: null,
            'fecha_fin' => ($d['fecha_fin'] ?? null) ?: null,
            'componentes' => array_map(fn ($c) => [
                'id' => ($c['id'] ?? null) ? (int) $c['id'] : null, 'nombre' => (string) ($c['nombre'] ?? ''), 'peso' => $peso($c['peso'] ?? 0),
                'actividades' => (int) ($c['actividades'] ?? 0),
            ], array_values($d['componentes'] ?? [])),
        ], array_values($divisiones));
    }
}
