<?php

namespace App\Providers;

use App\Models\Dictado;
use App\Models\Grupo;
use App\Models\Materia;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
