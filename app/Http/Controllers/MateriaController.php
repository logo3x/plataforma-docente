<?php

namespace App\Http\Controllers;

use App\Dictados\AltaMateria;
use App\Dictados\PlantillasPeriodo;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Alta de materia en un paso (2.4). */
class MateriaController extends Controller
{
    public function create(): View
    {
        return view('materias.crear', [
            'plantillas' => collect(['universidad', 'colegio', 'otro'])->mapWithKeys(fn ($n) => [$n => PlantillasPeriodo::para($n)]),
        ]);
    }

    public function store(Request $request, AltaMateria $alta): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'codigo' => ['nullable', 'string', 'max:30', Rule::unique('materias')->where('user_id', $request->user()->id)],
            'nivel' => ['required', Rule::in(['universidad', 'colegio', 'otro'])],
            'periodo' => ['required', 'string', 'max:20'],
            'grupos' => ['nullable', 'string', 'max:300'],
            'escala_min' => ['required', 'numeric'],
            'escala_max' => ['required', 'numeric', 'gt:escala_min'],
            'nota_aprobatoria' => ['required', 'numeric', 'gte:escala_min', 'lte:escala_max'],
            'decimales' => ['required', 'integer', 'between:0,2'],
            'divisiones' => ['required', 'array', 'min:1', 'max:12'],
            'divisiones.*.nombre' => ['required', 'string', 'max:60'],
            'divisiones.*.peso' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'divisiones.*.componentes' => ['required', 'array', 'min:1', 'max:8'],
            'divisiones.*.componentes.*.nombre' => ['required', 'string', 'max:60'],
            'divisiones.*.componentes.*.peso' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ]);

        // En el formulario los pesos se escriben en porcentaje
        $datos['divisiones'] = array_map(fn ($d) => [
            'nombre' => $d['nombre'],
            'peso' => $this->fraccion($d['peso']),
            'componentes' => array_map(fn ($c) => ['nombre' => $c['nombre'], 'peso' => $this->fraccion($c['peso'])], array_values($d['componentes'])),
        ], array_values($datos['divisiones']));
        $datos['grupos'] = explode(',', $datos['grupos'] ?? '');

        try {
            $dictado = $alta->crear($request->user(), $datos);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['divisiones' => $e->getMessage()]);
        }

        return redirect()->route('dictados.show', $dictado)
            ->with('estado', 'Materia creada. Cargue ahora la lista de estudiantes de cada grupo.');
    }

    private function fraccion(string $porcentaje): string
    {
        return (string) BigDecimal::of($porcentaje)->dividedBy(100, 6)->strippedOfTrailingZeros();
    }
}
