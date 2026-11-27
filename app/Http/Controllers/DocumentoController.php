<?php

namespace App\Http\Controllers;

use App\Material\GeneradorDocumento;
use App\Material\Generadores;
use App\Material\GeneradorMaterial;
use App\Models\Dictado;
use App\Models\Materia;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documentos de los generadores configurables (7.4.3): guías de laboratorio, manuales interactivos y boletines
 * de fábrica (7.5, 7.10) y los generadores que crea el docente. Mismo flujo que el resto del catálogo.
 */
class DocumentoController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorDocumento::class);
    }

    protected function ruta(): string
    {
        return 'documentos';
    }

    protected function nombre(): string
    {
        return 'Guías, manuales y otros documentos';
    }

    /** El generador elegido entra al prompt con sus campos llenos. */
    protected function ajustarGeneracion(array $d, Materia $materia, Dictado $dictado): array
    {
        return $d + ['definicion' => app(Generadores::class)->resolver($materia->docente, $d['generador'])];
    }

    public function index(Request $request, Materia $materia): View
    {
        return parent::index($request, $materia)->with('generadores', app(Generadores::class)->disponibles($request->user()));
    }
}
