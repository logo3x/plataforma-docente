<?php

namespace App\Http\Controllers;

use App\Material\ExportadorPptx;
use App\Material\GeneradorMaterial;
use App\Material\GeneradorPresentacion;
use App\Models\Material;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Presentaciones de clase (7.2): diapositivas, notas del orador, proyección y PowerPoint editable. */
class PresentacionController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorPresentacion::class);
    }

    protected function ruta(): string
    {
        return 'presentaciones';
    }

    protected function nombre(): string
    {
        return 'Presentaciones';
    }

    /** El archivo .pptx: se abre y se sigue editando en PowerPoint, LibreOffice o Google Presentaciones (7.2.3). */
    public function pptx(Material $material, ExportadorPptx $exportador): Response
    {
        $this->autorizarMaterial($material);
        abort_unless($material->tipo === 'presentacion', 404);

        return response($exportador->generar($material->contenido, $material->materia->docente->name, $material->materia->nombre), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug(Str::limit($material->titulo, 60, '')) ?: 'presentacion').'.pptx"',
        ]);
    }
}
