<?php

namespace App\Http\Controllers;

use App\Dictados\CalendarioClases;
use App\Dictados\FestivosColombia;
use App\Models\DiaSinClase;
use App\Models\Grupo;
use App\Models\Horario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Horario semanal del grupo y fechas de clase calculadas sin festivos (2.6.2). */
class CalendarioController extends Controller
{
    public function __construct(private readonly CalendarioClases $calendario) {}

    public function show(Grupo $grupo): View
    {
        Gate::authorize('gestionar', $grupo->dictado);
        $grupo->load('dictado.materia', 'horarios');
        $c = $this->calendario->de($grupo);
        $anios = $c['rango'] ? range($c['rango'][0]->year, $c['rango'][1]->year) : [now()->year];

        return view('calendario.grupo', $c + [
            'grupo' => $grupo,
            'sinClase' => $grupo->dictado->diasSinClase,
            'festivos' => collect($anios)->flatMap(fn ($a) => FestivosColombia::del($a))
                ->filter(fn ($n, $f) => ! $c['rango'] || ($f >= $c['rango'][0]->format('Y-m-d') && $f <= $c['rango'][1]->format('Y-m-d'))),
        ]);
    }

    /** Se registra una semana: un bloque por día marcado. */
    public function horario(Request $request, Grupo $grupo): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo->dictado);
        $d = $request->validate([
            'dias' => ['nullable', 'array'],
            'dias.*.activo' => ['nullable', 'boolean'],
            'dias.*.hora_inicio' => ['nullable', 'required_if:dias.*.activo,1', 'date_format:H:i'],
            'dias.*.hora_fin' => ['nullable', 'required_if:dias.*.activo,1', 'date_format:H:i'],
            'dias.*.aula' => ['nullable', 'string', 'max:60'],
        ]);
        $bloques = collect($d['dias'] ?? [])->filter(fn ($x, $dia) => ! empty($x['activo']) && isset(Horario::DIAS[(int) $dia]));
        foreach ($bloques as $dia => $x) {
            if ($x['hora_fin'] <= $x['hora_inicio']) {
                return back()->withInput()->withErrors(['dias' => 'El '.Str::lower(Horario::DIAS[(int) $dia]).' la clase termina antes de empezar.']);
            }
        }
        DB::transaction(function () use ($grupo, $bloques) {
            $grupo->horarios()->delete();
            foreach ($bloques as $dia => $x) {
                $grupo->horarios()->create(['dia' => (int) $dia, 'hora_inicio' => $x['hora_inicio'], 'hora_fin' => $x['hora_fin'], 'aula' => ($x['aula'] ?? null) ?: null]);
            }
        });
        $n = $this->calendario->de($grupo->fresh())['clases']->count();

        return redirect()->route('calendario.grupo', $grupo)->with('estado', $bloques->isEmpty() ? 'Horario borrado.' : "Horario guardado: {$n} clases en el periodo, sin festivos.");
    }

    public function sinClase(Request $request, Grupo $grupo): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo->dictado);
        $d = $request->validate(['desde' => ['required', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde'], 'motivo' => ['required', 'string', 'max:120']]);
        $grupo->dictado->diasSinClase()->create(['desde' => $d['desde'], 'hasta' => $d['hasta'] ?? $d['desde'], 'motivo' => $d['motivo']]);

        return redirect()->route('calendario.grupo', $grupo)->with('estado', "«{$d['motivo']}» marcado sin clase para todos los grupos del periodo.");
    }

    public function quitarSinClase(Grupo $grupo, DiaSinClase $dia): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo->dictado);
        abort_unless($dia->dictado_id === $grupo->dictado_id, 404);
        $dia->delete();

        return redirect()->route('calendario.grupo', $grupo)->with('estado', "Ya no se descuenta «{$dia->motivo}».");
    }

    public function ics(Grupo $grupo): Response
    {
        Gate::authorize('gestionar', $grupo->dictado);
        $nombre = Str::slug($grupo->dictado->materia->nombre.' '.$grupo->dictado->periodo.' '.$grupo->nombre);

        return response($this->calendario->ics($grupo->load('dictado.materia', 'horarios')), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="clases-'.$nombre.'.ics"']);
    }
}
