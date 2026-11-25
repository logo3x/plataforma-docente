<?php

namespace App\Http\Controllers;

use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Temario\ServicioTemario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Plan curricular y temario (7.1). */
class TemarioController extends Controller
{
    public function show(Request $request, Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);

        return view('temario.show', [
            'materia' => $materia,
            'planes' => $materia->contenidos()->orderByRaw("tipo = 'plan_curricular' desc")->latest()->get(['id', 'titulo', 'tipo']),
            'motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get(),
            'borradores' => GeneracionIa::query()->where('materia_id', $materia->id)->where('tarea', 'temario')->where('estado', 'borrador')->latest()->get(),
            'hayTemas' => $materia->temario()->where('tipo', 'tema')->exists(),
        ]);
    }

    public function extraer(Request $request, Materia $materia, ServicioTemario $servicio): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $d = $request->validate(['contenido_id' => ['required', 'integer'], 'motor_id' => ['nullable', 'integer']]);
        $plan = $materia->contenidos()->findOrFail($d['contenido_id']);

        try {
            $r = $servicio->extraer($request->user(), $materia, $plan, $this->motor($request, $d['motor_id'] ?? null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('temario.borrador', $r['generacion']);
    }

    public function borrador(GeneracionIa $generacion, ServicioTemario $servicio): View
    {
        $this->autorizar($generacion);
        $t = json_decode((string) $generacion->borrador, true);

        return view('temario.borrador', [
            'g' => $generacion->load('materia', 'motor'),
            'temario' => is_array($t) ? $t : null,
            'errores' => is_array($t) ? $servicio->validar($t) : ['El borrador no es JSON válido.'],
            'tieneTemario' => $generacion->materia->temario()->exists(),
        ]);
    }

    public function editar(Request $request, GeneracionIa $generacion, ServicioTemario $servicio): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $servicio->guardarBorrador($generacion, (string) $request->validate(['borrador' => ['required', 'string', 'max:200000']])['borrador']);

        return back()->with('estado', 'Borrador guardado.');
    }

    public function aplicar(Request $request, GeneracionIa $generacion, ServicioTemario $servicio): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $modo = $request->validate(['modo' => ['required', Rule::in(['agregar', 'reemplazar'])]])['modo'];
        try {
            $n = $servicio->aplicar($generacion, $modo);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return redirect()->route('temario.show', $generacion->materia)->with('estado', "Temario aplicado: {$n} temas. Revíselo y ajústelo aquí.");
    }

    public function descartar(GeneracionIa $generacion): RedirectResponse
    {
        $this->autorizar($generacion, borrador: true);
        $generacion->update(['estado' => 'descartado']);

        return redirect()->route('temario.show', $generacion->materia)->with('estado', 'Borrador descartado.');
    }

    public function sugerir(Request $request, Materia $materia, ServicioTemario $servicio): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        try {
            $n = $servicio->sugerir($request->user(), $materia, $this->motor($request, $request->integer('motor_id') ?: null));
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada|InvalidArgumentException $e) {
            return back()->with('error_ia', $e->getMessage());
        }

        return back()->with('estado', "La IA dejó {$n} sugerencia(s). Tómelas o descártelas tema por tema.");
    }

    private function motor(Request $request, ?int $id): ?ConfigMotorIa
    {
        return $id ? ConfigMotorIa::query()->where('user_id', $request->user()->id)->findOrFail($id) : null;
    }

    private function autorizar(GeneracionIa $g, bool $borrador = false): void
    {
        abort_if($g->materia === null || $g->tarea !== 'temario', 404);
        Gate::authorize('gestionar', $g->materia);
        abort_if($borrador && $g->estado !== 'borrador', 409, 'Este borrador ya fue aplicado o descartado.');
    }
}
