<?php

namespace App\Http\Controllers;

use App\Material\GeneradorMaterial;
use App\Material\GeneradorProyectos;
use App\Models\Dictado;
use App\Models\Materia;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Ideas de proyectos según el temario cubierto (7.4): guía del docente y enunciado para el estudiante. */
class ProyectoController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorProyectos::class);
    }

    protected function ruta(): string
    {
        return 'proyectos';
    }

    protected function nombre(): string
    {
        return 'Ideas de proyectos';
    }

    /** «Usar los temas que el grupo ya vio»: los temas salen de su lista de chequeo (7.1.4). */
    protected function ajustarGeneracion(array $d, Materia $materia, Dictado $dictado): array
    {
        if (empty($d['vistos'])) {
            return $d;
        }
        $grupo = filled($d['grupo'] ?? null) ? $dictado->grupos()->where('nombre', $d['grupo'])->first() : null;
        if ($grupo === null) {
            throw new InvalidArgumentException('Elija el grupo para usar los temas que ya vio.');
        }
        $vistos = DB::table('avances_temario')->where('grupo_id', $grupo->id)
            ->whereIn('temario_item_id', $materia->temario()->where('tipo', 'tema')->select('id'))->pluck('temario_item_id')->all();
        if ($vistos === []) {
            throw new InvalidArgumentException("El grupo {$grupo->nombre} aún no tiene temas marcados como vistos en la lista de chequeo.");
        }

        return ['temas' => array_values(array_unique([...$d['temas'], ...$vistos]))] + $d;
    }
}
