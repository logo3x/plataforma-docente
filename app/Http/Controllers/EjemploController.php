<?php

namespace App\Http\Controllers;

use App\Material\EjemplosPracticos;
use App\Material\GeneradorEjemplos;
use App\Material\GeneradorMaterial;
use App\Models\Materia;
use Illuminate\Http\Request;

/** Ejemplos prácticos resueltos paso a paso (7.4), con el código verificado en el navegador. */
class EjemploController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorEjemplos::class);
    }

    protected function ruta(): string
    {
        return 'ejemplos';
    }

    protected function nombre(): string
    {
        return 'Ejemplos prácticos';
    }

    protected function datosVista(string $vista, Materia $materia, array $contenido): array
    {
        return $vista === 'show' ? ['verificables' => app(EjemplosPracticos::class)->verificables($contenido)] : [];
    }

    /** Las comprobaciones que fallaron en el navegador vuelven a la IA con su error. */
    protected function problemasCorreccion(Request $request): array
    {
        $fallidos = json_decode((string) ($request->validate(['fallidos' => ['nullable', 'json', 'max:20000']])['fallidos'] ?? '[]'), true) ?: [];

        return collect($fallidos)->filter(fn ($f) => is_array($f) && is_numeric($f['numero'] ?? null))->take(EjemplosPracticos::MAX_EJEMPLOS)
            ->map(fn ($f) => 'La comprobación del ejemplo '.(int) $f['numero'].' no dio true al ejecutar su código'
                .(is_string($f['error'] ?? null) && $f['error'] !== '' ? ' (error: '.mb_substr($f['error'], 0, 300).')' : '')
                .'. Corrija el código o la comprobación.')->values()->all();
    }
}
