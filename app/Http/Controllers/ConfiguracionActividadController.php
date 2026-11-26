<?php

namespace App\Http\Controllers;

use App\Evaluacion\ConfiguracionActividad;
use App\Models\Artefacto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Panel de configuración de la actividad (5.7) y respaldo imprimible del examen (5.4.12). */
class ConfiguracionActividadController extends Controller
{
    public function edit(Artefacto $artefacto): View
    {
        Gate::authorize('gestionar', $artefacto->materia);

        return view('configuracion.editar', [
            'a' => $artefacto, 'c' => ConfiguracionActividad::efectiva($artefacto),
            'repartido' => $artefacto->actividades()->exists(),
            'conRetos' => $artefacto->items()->whereHas('pregunta', fn ($q) => $q->where('tipo', 'reto'))->exists(),
        ]);
    }

    public function update(Request $request, Artefacto $artefacto): RedirectResponse
    {
        Gate::authorize('gestionar', $artefacto->materia);
        ConfiguracionActividad::guardar($artefacto, $request->validate(ConfiguracionActividad::reglas()));

        return redirect()->route('configuracion.edit', $artefacto)->with('estado', $artefacto->actividades()->exists()
            ? 'Configuración guardada. Si ya repartió el examen, vuelva a descargarlo para que los cambios lleguen al archivo; los umbrales de alerta se aplican al procesar las entregas.'
            : 'Configuración guardada.');
    }

    /** Versión en papel, sin respuestas, por si fallan los equipos (5.4.12). */
    public function imprimible(Request $request, Artefacto $artefacto): View
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $version = $request->query('version', $artefacto->versiones()[0] ?? 'A');
        abort_unless(in_array($version, $artefacto->versiones(), true), 404);

        return view('configuracion.imprimible', [
            'a' => $artefacto->load('materia'), 'version' => $version, 'c' => ConfiguracionActividad::efectiva($artefacto),
            'items' => $artefacto->items()->with('pregunta')->where('banco', $version)->orderBy('orden')->get(),
        ]);
    }
}
