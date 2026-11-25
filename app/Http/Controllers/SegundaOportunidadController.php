<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Dictado;
use App\Models\Division;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\SegundaOportunidad;
use App\Notas\EstadoCorte;
use App\Notas\SegundasOportunidades;
use App\Notas\TableroNotas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Supletorios, habilitaciones y recuperaciones (3.6), con simulación previa (3.7.4). */
class SegundaOportunidadController extends Controller
{
    public function create(Request $request, Grupo $grupo, Estudiante $estudiante): View
    {
        $this->autorizar($grupo, $estudiante);

        return $this->formulario($grupo, $estudiante, $request->only(['ambito', 'tipo', 'valor', 'regla', 'tope', 'fecha', 'motivo']));
    }

    /** Muestra a qué quedaría la definitiva sin registrar nada (3.7.4). */
    public function simular(Request $request, Grupo $grupo, Estudiante $estudiante, SegundasOportunidades $servicio): View|RedirectResponse
    {
        $this->autorizar($grupo, $estudiante);
        $d = $this->validar($request);
        try {
            $hipotetica = $servicio->construir($estudiante, $d);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['valor' => $e->getMessage()]);
        }

        return $this->formulario($grupo, $estudiante, $d, $this->comparar($grupo, $estudiante, $hipotetica));
    }

    public function store(Request $request, Grupo $grupo, Estudiante $estudiante, SegundasOportunidades $servicio): RedirectResponse
    {
        $this->autorizar($grupo, $estudiante);
        $d = $this->validar($request);
        try {
            $s = $servicio->registrar($estudiante, $d);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['valor' => $e->getMessage()]);
        }

        return redirect()->route('segundas.create', [$grupo, $estudiante])->with('estado',
            SegundaOportunidad::TIPOS[$s->tipo]." registrado para {$estudiante->nombreCompleto()}. La nota original se conserva.");
    }

    public function anular(Request $request, Grupo $grupo, SegundaOportunidad $segunda, SegundasOportunidades $servicio): RedirectResponse
    {
        $this->autorizar($grupo, $segunda->estudiante);
        abort_if($segunda->anulada_en !== null, 409, 'Ya estaba anulada.');
        $motivo = $request->validate(['motivo_anulacion' => ['required', 'string', 'min:5', 'max:300']])['motivo_anulacion'];

        $servicio->anular($segunda, $motivo);

        return back()->with('estado', SegundaOportunidad::TIPOS[$segunda->tipo].' anulado. Las notas vuelven a calcularse sin él.');
    }

    /** Todas las segundas oportunidades del periodo, para el reporte institucional (3.6.4). */
    public function index(Dictado $dictado): View
    {
        Gate::authorize('gestionar', $dictado);

        return view('segundas.index', [
            'dictado' => $dictado->load('materia'),
            'eventos' => SegundaOportunidad::query()->whereIn('estudiante_id', $dictado->estudiantes()->pluck('id'))
                ->with('estudiante.grupos', 'ambito', 'docente')->orderByDesc('fecha')->orderByDesc('id')->get(),
            'escala' => $dictado->escala(),
        ]);
    }

    private function formulario(Grupo $grupo, Estudiante $estudiante, array $valores, ?array $simulacion = null): View
    {
        $tablero = new TableroNotas($grupo);

        return view('segundas.crear', [
            'grupo' => $grupo->load('dictado.materia'),
            'estudiante' => $estudiante,
            'divisiones' => $tablero->pestanas(),
            'tablero' => $tablero,
            'escala' => $tablero->escala(),
            'valores' => $valores,
            'simulacion' => $simulacion,
            'eventos' => SegundaOportunidad::query()->where('estudiante_id', $estudiante->id)->with('ambito', 'docente')->orderByDesc('fecha')->orderByDesc('id')->get(),
        ]);
    }

    /** @return array<int, array{que: string, antes: ?string, despues: ?string, estado_antes: string, estado_despues: string}> */
    private function comparar(Grupo $grupo, Estudiante $e, SegundaOportunidad $hip): array
    {
        $antes = new TableroNotas($grupo);
        $despues = new TableroNotas($grupo, $hip);
        $filas = [];

        $division = match (true) {
            $hip->ambito instanceof Actividad => $hip->ambito->componente->division,
            $hip->ambito instanceof Division => $hip->ambito,
            default => null,
        };
        if ($division !== null) {
            $division = $antes->division($division->id);
            $ra = $antes->resultado($division, $e);
            $rd = $despues->resultado($despues->division($division->id), $e);
            $filas[] = ['que' => "Definitiva de {$division->nombre}", 'antes' => $ra->definitiva, 'despues' => $rd->definitiva,
                'estado_antes' => $ra->estado->etiqueta(), 'estado_despues' => $rd->estado->etiqueta()];
        }

        $ca = collect($antes->filasConsolidado())->firstWhere('estudiante.id', $e->id);
        $cd = collect($despues->filasConsolidado())->firstWhere('estudiante.id', $e->id);
        $etiqueta = fn ($f) => $f['estado'] === EstadoCorte::Incompleto ? '… En curso' : $f['estado']->etiqueta();
        $filas[] = ['que' => 'Nota del periodo', 'antes' => $ca['acumulado'], 'despues' => $cd['acumulado'],
            'estado_antes' => $etiqueta($ca), 'estado_despues' => $etiqueta($cd)];

        return $filas;
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'ambito' => ['required', 'string', 'max:40'],
            'tipo' => ['required', Rule::in(array_keys(SegundaOportunidad::TIPOS))],
            'valor' => ['required', 'numeric'],
            'regla' => ['required', Rule::in(array_keys(SegundaOportunidad::REGLAS))],
            'tope' => ['nullable', 'numeric'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]) + ['tope' => null, 'motivo' => null];
    }

    private function autorizar(Grupo $grupo, Estudiante $estudiante): void
    {
        Gate::authorize('gestionar', $grupo);
        abort_unless($estudiante->dictado_id === $grupo->dictado_id && $grupo->estudiantes()->whereKey($estudiante->id)->exists(), 404);
    }
}
