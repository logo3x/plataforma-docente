<?php

namespace App\Http\Controllers;

use App\Historial\HistorialMateria;
use App\Historial\Versiones;
use App\Models\Artefacto;
use App\Models\Material;
use App\Models\Rubrica;
use App\Models\UnidadDidactica;
use App\Models\Version;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** 7.6: volver a una versión anterior de lo generado. */
class VersionController extends Controller
{
    public function restaurar(Version $version, Versiones $versiones): RedirectResponse
    {
        $m = $version->versionable;
        abort_if($m === null, 404);
        Gate::authorize('gestionar', $m->materia);
        try {
            $versiones->restaurar($version);
        } catch (InvalidArgumentException $e) {
            return back()->with('error_version', $e->getMessage());
        }

        return redirect(match (true) {
            $m instanceof Material => route(HistorialMateria::RUTAS_MATERIAL[$m->tipo].'.show', $m),
            $m instanceof Rubrica => route('rubricas.edit', $m),
            $m instanceof UnidadDidactica => route('unidades.show', $m),
            $m instanceof Artefacto => route('bancos.edit', $m),
        })->with('estado', "Se volvió a la versión {$version->numero}. Lo que había quedó guardado como otra versión.");
    }
}
