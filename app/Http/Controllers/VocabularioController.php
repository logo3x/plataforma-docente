<?php

namespace App\Http\Controllers;

use App\Material\GeneradorMaterial;
use App\Material\GeneradorVocabulario;
use App\Material\ListaVocabulario;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Lista de vocabulario (7.4): términos con definición, tarjetas de repaso y paso al vocabulario de la materia. */
class VocabularioController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorVocabulario::class);
    }

    protected function ruta(): string
    {
        return 'vocabularios';
    }

    protected function nombre(): string
    {
        return 'Vocabulario';
    }

    /** Los términos pasan al «Vocabulario propio» de la materia: la IA los usará en todo lo que genere (capa 2). */
    public function glosario(Material $material, ListaVocabulario $lista): RedirectResponse
    {
        abort_unless($material->tipo === ListaVocabulario::TIPO, 404);
        Gate::authorize('gestionar', $material->materia);
        $r = $lista->pasarAlGlosario($material->materia, $material->contenido);

        $mensaje = $r['agregados'] ? "{$r['agregados']} término(s) agregados al vocabulario de la materia; la IA los usará en sus prompts." : 'No se agregó ningún término.';
        $mensaje .= $r['ya_estaban'] ? " {$r['ya_estaban']} ya estaban." : '';
        $mensaje .= $r['sin_espacio'] ? " {$r['sin_espacio']} no cupieron (el vocabulario propio admite ".ListaVocabulario::MAX_GLOSARIO.' caracteres): edítelo en «Estilo para la IA».' : '';

        return back()->with('estado', $mensaje);
    }
}
