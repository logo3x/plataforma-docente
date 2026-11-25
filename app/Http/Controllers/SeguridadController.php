<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/** Contraseña y segundo factor del docente (revisión A3). */
class SeguridadController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('seguridad', ['usuario' => $request->user()]);
    }
}
