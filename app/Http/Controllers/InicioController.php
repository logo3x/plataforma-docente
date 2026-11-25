<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class InicioController extends Controller
{
    public function __invoke(Request $request): View
    {
        $materias = $request->user()->materias()
            ->with(['dictados' => fn ($q) => $q->latest()->withCount('estudiantes')->with('grupos')])
            ->orderBy('nombre')
            ->get();

        return view('inicio', compact('materias'));
    }
}
