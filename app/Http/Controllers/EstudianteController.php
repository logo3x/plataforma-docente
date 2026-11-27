<?php

namespace App\Http\Controllers;

use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\NotaCambio;
use App\Notas\HistorialEstudiante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Corrección de datos de un estudiante y retiro del grupo, sin borrar sus notas. */
class EstudianteController extends Controller
{
    public function edit(Grupo $grupo, Estudiante $estudiante): View
    {
        $this->autorizar($grupo, $estudiante);

        return view('estudiantes.editar', [
            'grupo' => $grupo->load('dictado.materia'),
            'estudiante' => $estudiante->makeVisible(['email', 'telefono', 'observacion']),
            'activo' => (bool) $grupo->estudiantes()->whereKey($estudiante->id)->first()?->pivot->activo,
        ]);
    }

    public function update(Request $request, Grupo $grupo, Estudiante $estudiante): RedirectResponse
    {
        $this->autorizar($grupo, $estudiante);
        $datos = $request->validate([
            'apellidos' => ['required', 'string', 'max:120'],
            'nombres' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'activo' => ['required', 'boolean'],
        ]);

        $estudiante->update([
            ...collect($datos)->except('activo')->map(fn ($v) => is_string($v) ? trim($v) : $v)->all(),
            'revisar_nombre' => false,
        ]);
        $grupo->estudiantes()->updateExistingPivot($estudiante->id, ['activo' => $datos['activo']]);

        return redirect()->route('registro', $grupo)->with('estado',
            $datos['activo'] ? "Datos de {$estudiante->nombreCompleto()} actualizados."
                : "{$estudiante->nombreCompleto()} quedó retirado del grupo. Sus notas se conservan; puede reactivarlo cuando quiera.");
    }

    /** Historial del estudiante a lo largo del semestre (13.6). */
    public function historial(Grupo $grupo, Estudiante $estudiante, HistorialEstudiante $historial): View
    {
        $this->autorizar($grupo, $estudiante);

        return view('estudiantes.historial', [
            'grupo' => $grupo->load('dictado.materia'),
            'estudiante' => $estudiante,
            'activo' => (bool) $grupo->estudiantes()->whereKey($estudiante->id)->first()?->pivot->activo,
            'otrosGrupos' => $estudiante->grupos()->whereKeyNot($grupo->id)->get(),
        ] + $historial->datos($grupo, $estudiante));
    }

    /**
     * 3.5.4: la bitácora de las notas de un estudiante (o de una sola, con ?actividad=), como soporte ante un reclamo.
     * Es la misma traza que no se puede editar ni borrar: fecha, valor anterior y nuevo, origen, motivo y quién.
     */
    public function bitacora(Request $request, Grupo $grupo, Estudiante $estudiante): StreamedResponse
    {
        $this->autorizar($grupo, $estudiante);
        $escala = $grupo->dictado->escala();
        $cambios = NotaCambio::query()->where('estudiante_id', $estudiante->id)
            ->whereHas('actividad.componente.division', fn ($q) => $q->where('dictado_id', $grupo->dictado_id))
            ->when($request->integer('actividad'), fn ($q, $a) => $q->where('actividad_id', $a))
            ->with('actividad.componente.division', 'usuario')->orderBy('created_at')->orderBy('id')->get();
        $v = fn ($x) => $x === null ? 'vacía' : $escala->mostrar($x);

        return response()->streamDownload(function () use ($cambios, $estudiante, $grupo, $v) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\u{FEFF}");
            fputcsv($f, ['Estudiante', $estudiante->nombreCompleto(), 'Documento', $estudiante->documento, 'Periodo', $grupo->dictado->periodo, 'Materia', $grupo->dictado->materia->nombre], ';');
            fputcsv($f, ['Fecha', 'División', 'Componente', 'Actividad', 'Antes', 'Después', 'Origen', 'Motivo', 'Quién', 'Operación'], ';');
            foreach ($cambios as $c) {
                fputcsv($f, [$c->created_at->format('Y-m-d H:i:s'), $c->actividad->componente->division->nombre, $c->actividad->componente->nombre, $c->actividad->nombre,
                    $v($c->valor_anterior), $v($c->valor_nuevo), $c->origen, (string) $c->motivo, $c->usuario?->name ?? '', (string) $c->operacion_id], ';');
            }
            fclose($f);
        }, 'bitacora-'.$estudiante->documento.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function autorizar(Grupo $grupo, Estudiante $estudiante): void
    {
        Gate::authorize('gestionar', $grupo);
        abort_unless($grupo->estudiantes()->whereKey($estudiante->id)->exists(), 404);
    }
}
