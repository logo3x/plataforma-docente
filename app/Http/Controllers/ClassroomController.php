<?php

namespace App\Http\Controllers;

use App\Classroom\ClienteClassroom;
use App\Classroom\ConexionGoogle;
use App\Classroom\SincronizadorClassroom;
use App\Models\Actividad;
use App\Models\CuentaGoogle;
use App\Models\EnvioClassroom;
use App\Models\Grupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

/** Integración con Google Classroom (decisión pendiente 4). */
class ClassroomController extends Controller
{
    public function __construct(private readonly ConexionGoogle $conexion, private readonly ClienteClassroom $api, private readonly SincronizadorClassroom $sync) {}

    public function index(Request $request): View
    {
        return view('classroom.index', [
            'configurada' => ConexionGoogle::configurada(),
            'cuenta' => CuentaGoogle::query()->where('user_id', $request->user()->id)->first(),
            'grupos' => Grupo::query()->whereHas('dictado.materia', fn ($q) => $q->where('user_id', $request->user()->id))->with('dictado.materia')->get(),
        ]);
    }

    public function conectar(Request $request): RedirectResponse
    {
        abort_unless(ConexionGoogle::configurada(), 404);
        $estado = Str::random(40);
        $request->session()->put('classroom.estado', $estado);

        return redirect()->away($this->conexion->urlAutorizacion($estado));
    }

    public function callback(Request $request): RedirectResponse
    {
        $esperado = $request->session()->pull('classroom.estado');
        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state'))) {
            return redirect()->route('classroom.index')->withErrors(['classroom' => 'La autorización no corresponde a esta sesión. Vuelva a intentarlo.']);
        }
        if ($request->filled('error')) {
            return redirect()->route('classroom.index')->withErrors(['classroom' => 'No se autorizó el acceso a Google.']);
        }
        try {
            $c = $this->conexion->conectar($request->user(), (string) $request->query('code'));
        } catch (RuntimeException $e) {
            return redirect()->route('classroom.index')->withErrors(['classroom' => $e->getMessage()]);
        }

        return redirect()->route('classroom.index')->with('estado', "Cuenta de Google conectada: {$c->email}.");
    }

    public function desconectar(Request $request): RedirectResponse
    {
        $this->conexion->desconectar($request->user());

        return back()->with('estado', 'Se revocó el acceso a Google y se borraron los permisos guardados.');
    }

    public function grupo(Request $request, Grupo $grupo): View
    {
        Gate::authorize('gestionar', $grupo);
        [$cursos, $error] = $this->intentar(fn () => $this->api->cursos($request->user()));

        return view('classroom.grupo', ['grupo' => $grupo->load('dictado.materia'), 'cursos' => $cursos ?? [], 'error' => $error,
            'cruzados' => $grupo->estudiantes()->wherePivot('activo', true)->whereNotNull('classroom_usuario_id')->count(),
            'total' => $grupo->estudiantes()->wherePivot('activo', true)->count()]);
    }

    public function vincularGrupo(Request $request, Grupo $grupo): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        $d = $request->validate(['curso' => ['required', 'string', 'max:40']]);
        [$r, $error] = $this->intentar(fn () => $this->sync->vincularGrupo($request->user(), $grupo, $d['curso']));

        return $error ? back()->withErrors(['classroom' => $error]) : back()->with('cruce', $r)->with('estado', "Grupo vinculado. {$r['cruzados']} estudiante(s) cruzados por correo.");
    }

    public function cruzar(Request $request, Grupo $grupo): RedirectResponse
    {
        Gate::authorize('gestionar', $grupo);
        [$r, $error] = $this->intentar(fn () => $this->sync->cruzar($request->user(), $grupo));

        return $error ? back()->withErrors(['classroom' => $error]) : back()->with('cruce', $r)->with('estado', "{$r['cruzados']} estudiante(s) cruzados por correo.");
    }

    public function actividad(Request $request, Actividad $actividad): View
    {
        $this->autorizar($actividad);
        $grupo = $this->sync->cursoDe($actividad);
        [$tareas, $error] = $grupo ? $this->intentar(fn () => $this->api->tareas($request->user(), $grupo->classroom_curso_id)) : [[], null];
        [$plan, $errorPlan] = $actividad->classroom_tarea ? $this->intentar(fn () => $this->sync->plan($request->user(), $actividad)) : [null, null];

        return view('classroom.actividad', ['actividad' => $actividad->load('componente.division.dictado.materia'), 'grupo' => $grupo,
            'tareas' => $tareas ?? [], 'error' => $error ?? $errorPlan, 'plan' => $plan, 'escala' => $actividad->componente->division->dictado->escala(),
            'envios' => EnvioClassroom::query()->where('actividad_id', $actividad->id)->latest()->limit(1)->first()]);
    }

    public function vincularTarea(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $d = $request->validate(['tarea' => ['required', 'string', 'max:40']]);
        [, $error] = $this->intentar(fn () => $d['tarea'] === 'nueva'
            ? $this->sync->crearTarea($request->user(), $actividad, $request->input('descripcion'), $request->boolean('publicar'))
            : $this->sync->vincularTarea($request->user(), $actividad, $d['tarea']));

        return $error ? back()->withErrors(['classroom' => $error])
            : back()->with('estado', $d['tarea'] === 'nueva' ? 'Tarea creada en Classroom y vinculada: a esta sí se le pueden enviar las notas.' : 'Tarea vinculada.');
    }

    public function importar(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        @set_time_limit(300);
        [$r, $error] = $this->intentar(fn () => $this->sync->importar($request->user(), $actividad));

        return $error ? back()->withErrors(['classroom' => $error]) : back()->with('resultados', $r);
    }

    public function enviar(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        [$r, $error] = $this->intentar(fn () => $this->sync->enviar($request->user(), $actividad, $request->boolean('asignar')));
        if ($error) {
            return back()->withErrors(['classroom' => $error]);
        }

        return back()->with('estado', "{$r['enviadas']} nota(s) enviada(s) a Classroom como ".($request->boolean('asignar') ? 'asignadas' : 'borrador')
            .($r['sin_cambio'] ? "; {$r['sin_cambio']} ya tenían esa nota" : '').($r['omitidas'] ? "; {$r['omitidas']} sin nota o sin entrega en Classroom" : '').'.')
            ->withErrors($r['errores'] ? ['classroom' => implode(' ', $r['errores'])] : []);
    }

    private function intentar(callable $f): array
    {
        try {
            return [$f(), null];
        } catch (InvalidArgumentException|RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
