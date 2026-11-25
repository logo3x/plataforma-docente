<?php

namespace App\Http\Controllers;

use App\IA\ResolutorMotor;
use App\Models\ConfigMotorIa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Motores de IA del docente: local u online (1.5). */
class MotorIaController extends Controller
{
    public function index(Request $request): View
    {
        return view('ia.motores', ['motores' => ConfigMotorIa::query()->where('user_id', $request->user()->id)->orderByDesc('predeterminado')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'driver' => ['required', Rule::in(['ollama', 'openai_compatible', 'anthropic'])],
            'base_url' => ['nullable', 'required_unless:driver,anthropic', 'url', 'max:200'],
            'modelo' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'required_if:driver,anthropic', 'string', 'max:300'],
        ]);
        $hay = ConfigMotorIa::query()->where('user_id', $request->user()->id)->exists();

        ConfigMotorIa::query()->create($d + [
            'user_id' => $request->user()->id,
            'es_local' => $d['driver'] !== 'anthropic',     // la nube nunca se marca como local
            'predeterminado' => ! $hay,
        ]);

        return back()->with('estado', "Motor «{$d['nombre']}» agregado. Pruebe la conexión.");
    }

    public function probar(Request $request, ConfigMotorIa $motor, ResolutorMotor $resolutor): RedirectResponse
    {
        abort_unless($motor->user_id === $request->user()->id, 403);
        $ok = $resolutor->para($motor)->disponible();

        return back()->with($ok ? 'estado' : 'error_motor', $ok
            ? "✓ «{$motor->nombre}» responde."
            : "✗ «{$motor->nombre}» no responde en {$motor->base_url}. Verifique que el servicio esté encendido y la dirección.");
    }

    public function predeterminado(Request $request, ConfigMotorIa $motor): RedirectResponse
    {
        abort_unless($motor->user_id === $request->user()->id, 403);
        ConfigMotorIa::query()->where('user_id', $motor->user_id)->update(['predeterminado' => false]);
        $motor->update(['predeterminado' => true]);

        return back()->with('estado', "«{$motor->nombre}» es ahora el motor predeterminado.");
    }

    public function destroy(Request $request, ConfigMotorIa $motor): RedirectResponse
    {
        abort_unless($motor->user_id === $request->user()->id, 403);
        if ($motor->generaciones()->exists()) {
            return back()->with('error_motor', 'Ese motor tiene generaciones registradas (trazabilidad): no se elimina.');
        }
        $motor->delete();

        return back()->with('estado', 'Motor eliminado.');
    }
}
