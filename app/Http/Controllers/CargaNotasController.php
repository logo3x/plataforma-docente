<?php

namespace App\Http\Controllers;

use App\Models\Componente;
use App\Models\Grupo;
use App\Notas\ImportadorNotas;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Carga de notas desde archivo (3.1.5): subir → vista previa (columnas y escala corregibles) → confirmar.
 * El archivo leído queda en sesión; la vista previa se recalcula en el servidor al confirmar.
 */
class CargaNotasController extends Controller
{
    public function create(Request $request, Grupo $grupo): View
    {
        Gate::authorize('gestionar', $grupo);

        return view('carga-notas.subir', [
            'grupo' => $grupo->load('dictado.materia', 'dictado.divisiones.componentes'),
            'division' => $request->integer('division') ?: null,
        ]);
    }

    public function subir(Request $request, Grupo $grupo, ImportadorNotas $importador): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt'],
            'componente_id' => ['required', 'integer'],
            'nombre' => ['nullable', 'string', 'max:120'],
        ]);
        $componente = $this->componente($grupo, $datos['componente_id']);

        $archivo = $request->file('archivo');
        try {
            $tabla = $importador->leer($archivo->getRealPath(), $archivo->getClientOriginalExtension());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['archivo' => $e->getMessage()]);
        }

        $token = (string) Str::uuid();
        $request->session()->put("carga-notas.{$token}", [
            'grupo_id' => $grupo->id,
            'archivo' => $archivo->getClientOriginalName(),
            'componente_id' => $componente->id,
            'nombre' => ($datos['nombre'] ?? null) ?: pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME),
            ...$tabla,
        ]);

        return redirect()->route('carga-notas.previa', [$grupo, 'token' => $token]);
    }

    public function previa(Request $request, Grupo $grupo, ImportadorNotas $importador): View|RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        $carga = $this->carga($request, $grupo);
        if ($carga === null) {
            return $this->expirada($grupo);
        }

        [$columnas, $escalaOrigen] = $this->parametros($request, $carga, $grupo, $importador);
        $componente = $this->componente($grupo, $carga['componente_id']);

        try {
            $analisis = $importador->analizar($grupo, $carga['filas'], $columnas, $escalaOrigen);
        } catch (InvalidArgumentException $e) {
            $analisis = null;
            $request->session()->now('error_columnas', $e->getMessage());
        }

        return view('carga-notas.previa', [
            'grupo' => $grupo->load('dictado.materia'),
            'componente' => $componente->load('division'),
            'token' => (string) $request->string('token'),
            'carga' => $carga,
            'columnas' => $columnas,
            'escalaOrigen' => $escalaOrigen,
            'escalas' => array_unique([...ImportadorNotas::ESCALAS_ORIGEN, (string) BigDecimal::of($grupo->dictado->escala_max)->strippedOfTrailingZeros()]),
            'analisis' => $analisis,
        ]);
    }

    public function confirmar(Request $request, Grupo $grupo, ImportadorNotas $importador): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        $request->validate(['nombre' => ['required', 'string', 'max:120']]);
        $carga = $this->carga($request, $grupo);
        if ($carga === null) {
            return $this->expirada($grupo);
        }

        [$columnas, $escalaOrigen] = $this->parametros($request, $carga, $grupo, $importador);
        $componente = $this->componente($grupo, $carga['componente_id']);

        // Se recalcula aquí: no se confía en lo que muestra el navegador
        $analisis = $importador->analizar($grupo, $carga['filas'], $columnas, $escalaOrigen);
        $r = $importador->confirmar($grupo, $componente, (string) $request->string('nombre'), $analisis['filas'], $carga['archivo']);
        $request->session()->forget('carga-notas.'.$request->string('token'));

        // Queda en la pila de «deshacer» del registro de notas del grupo
        $pila = $request->session()->get("deshacer.grupo.{$grupo->id}", []);
        $pila[] = ['op' => $r['operacion'], 'descripcion' => "Carga de «{$r['actividad']->nombre}» desde archivo ({$r['importadas']} notas)"];
        $request->session()->put("deshacer.grupo.{$grupo->id}", array_slice($pila, -50));

        return redirect()->route('registro', [$grupo, 'vista' => $componente->division_id])
            ->with('estado', "Columna «{$r['actividad']->nombre}» creada con {$r['importadas']} notas desde «{$carga['archivo']}». "
                .'Puede deshacerlo con el botón «Deshacer».');
    }

    /** @return array{0: array<string, ?int>, 1: string} */
    private function parametros(Request $request, array $carga, Grupo $grupo, ImportadorNotas $importador): array
    {
        $detectadas = $importador->detectarColumnas($carga['encabezados']);
        $total = count($carga['encabezados']);

        $columnas = [];
        foreach ($detectadas as $campo => $sugerida) {
            if ($request->has("col_{$campo}")) {
                $v = $request->input("col_{$campo}");
                $columnas[$campo] = is_numeric($v) && (int) $v >= 0 && (int) $v < $total ? (int) $v : null;
            } else {
                $columnas[$campo] = $sugerida;
            }
        }

        $escala = $request->input('escala');
        if (! is_string($escala) || ! is_numeric($escala) || (float) $escala <= 0) {
            $escala = $importador->detectarEscala($carga['filas'], $columnas['nota'], $grupo->dictado->escala());
        }

        return [$columnas, $escala];
    }

    private function carga(Request $request, Grupo $grupo): ?array
    {
        $carga = $request->session()->get('carga-notas.'.$request->string('token'));

        return ($carga !== null && $carga['grupo_id'] === $grupo->id) ? $carga : null;
    }

    private function componente(Grupo $grupo, int $id): Componente
    {
        $c = Componente::query()->with('division')->findOrFail($id);
        abort_unless($c->division->dictado_id === $grupo->dictado_id, 403);

        return $c;
    }

    private function expirada(Grupo $grupo): RedirectResponse
    {
        return redirect()->route('carga-notas.create', $grupo)
            ->withErrors(['archivo' => 'La vista previa expiró o ya se usó. Vuelva a subir el archivo.']);
    }
}
