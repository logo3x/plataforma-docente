<?php

namespace App\Http\Controllers;

use App\Material\GeneradorMaterial;
use App\Material\GeneradorTexto;
use App\Material\TextoInformativo;
use App\Models\Materia;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Textos informativos y apuntes (7.4): lectura por secciones, versión para estudiantes y paso al repositorio. */
class ApunteController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorTexto::class);
    }

    protected function ruta(): string
    {
        return 'apuntes';
    }

    protected function nombre(): string
    {
        return 'Textos y apuntes';
    }

    protected function datosVista(string $vista, Materia $materia, array $contenido): array
    {
        return $vista === 'show' ? ['minutos' => app(TextoInformativo::class)->minutos($contenido)] : [];
    }

    /** El apunte pasa al repositorio de la materia (módulo 7) y queda disponible como material de referencia. */
    public function repositorio(Material $material, TextoInformativo $tipo): RedirectResponse
    {
        abort_unless($material->tipo === TextoInformativo::TIPO, 404);
        Gate::authorize('gestionar', $material->materia);
        $r = $tipo->guardarEnRepositorio($material);

        return back()->with('estado', $r['nuevo']
            ? "«{$material->titulo}» quedó en el repositorio de contenido como lectura: ya puede elegirlo como material de referencia."
            : 'Este texto ya estaba en el repositorio de contenido.');
    }
}
