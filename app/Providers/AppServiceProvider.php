<?php

namespace App\Providers;

use App\Models\Dictado;
use App\Models\Grupo;
use App\Models\Materia;
use App\Models\User;
use App\Notas\RegistroNotas;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una sola instancia por petición: así las operaciones anidadas (pasar al registro →
        // asignación grupal → asignar) comparten el mismo identificador y se deshacen juntas (3.1.9).
        $this->app->scoped(RegistroNotas::class);
    }

    public function boot(): void
    {
        // Sesión en vivo: en un aula todos los equipos suelen salir con la misma IP; el límite va por examen
        // (credencial o documento) y uno más amplio por IP frena a quien intente adivinar códigos.
        RateLimiter::for('vivo', function (Request $r) {
            $quien = $r->input('clave') ? 'c:'.hash('sha256', (string) $r->input('clave')) : 'd:'.$r->input('documento', '').'|'.$r->ip();

            return [Limit::perMinute(30)->by($quien), Limit::perMinute(900)->by('ip:'.$r->ip())];
        });

        // Un docente solo ve y modifica sus propias materias, dictados y grupos.
        Gate::define('gestionar', function (User $user, Materia|Dictado|Grupo $recurso): bool {
            $materia = match (true) {
                $recurso instanceof Materia => $recurso,
                $recurso instanceof Dictado => $recurso->materia,
                $recurso instanceof Grupo => $recurso->dictado->materia,
            };

            return $materia->user_id === $user->id;
        });
    }
}
