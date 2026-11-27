<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Política de seguridad de las páginas de la plataforma. El calificador ejecuta el código de los estudiantes en el
 * navegador del docente, con su sesión abierta: aunque ese código corre aislado, la política es la segunda barrera
 * para que nada, ni ese código ni un script inyectado, se conecte fuera de la plataforma.
 *
 * Solo restringe lo que nombra: conexiones, workers, marcos y formularios. El examen en vivo (/v/…) trae su propia
 * política, más estricta, y no pasa por aquí.
 */
class PoliticaSeguridad
{
    public const POLITICA = "connect-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self' https://accounts.google.com";

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        // Los archivos descargados (el examen, lo publicado) llevan su propia política adentro
        if (! $request->is('v', 'v/*') && ! $respuesta->headers->has('Content-Security-Policy')
            && ! str_starts_with((string) $respuesta->headers->get('Content-Disposition'), 'attachment')
            && str_contains((string) $respuesta->headers->get('Content-Type', 'text/html'), 'text/html')) {
            $respuesta->headers->set('Content-Security-Policy', self::POLITICA);
        }

        return $respuesta;
    }
}
