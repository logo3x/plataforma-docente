<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RegistroController extends Controller
{
    public function __invoke(Grupo $grupo): View
    {
        Gate::authorize('gestionar', $grupo);

        return view('registro', ['grupo' => $grupo->load('dictado.materia')]);
    }
}
