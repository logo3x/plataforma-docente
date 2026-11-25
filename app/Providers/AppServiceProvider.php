<?php

namespace App\Providers;

use App\Models\Dictado;
use App\Models\Grupo;
use App\Models\Materia;
use App\Models\User;
use App\Notas\RegistroNotas;
use Illuminate\Support\Facades\Gate;
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
