<?php

namespace App\Http\Controllers;

use App\Material\Publicador;
use App\Models\Material;
use App\Models\Publicacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Publicación institucional del material (7.8, decisión pendiente 2). */
class PublicacionController extends Controller
{
    public function __construct(private readonly Publicador $publicador) {}

    public function edit(Material $material): View
    {
        Gate::authorize('gestionar', $material->materia);

        return view('publicacion.editar', [
            'm' => $material->load('materia'), 'ficha' => $this->publicador->fichaInicial($material), 'campos' => config('publicacion.campos'),
            'publicaciones' => Publicacion::query()->where('material_id', $material->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request, Material $material): Response|RedirectResponse
    {
        Gate::authorize('gestionar', $material->materia);
        $d = $request->validate(['formato' => ['required', Rule::in(array_keys(Publicador::FORMATOS))], 'ficha' => ['required', 'array'], 'ficha.*' => ['nullable', 'string', 'max:2000']]);
        $ficha = collect(config('publicacion.campos'))->mapWithKeys(fn ($c, $k) => [$k => trim((string) ($d['ficha'][$k] ?? ''))])->all();
        if ($errores = $this->publicador->validarFicha($ficha)) {
            return back()->withInput()->withErrors($errores);
        }
        try {
            $a = $this->publicador->publicar($material, $d['formato'], $ficha);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['formato' => $e->getMessage()]);
        }
        Publicacion::query()->create(['material_id' => $material->id, 'user_id' => $request->user()->id, 'formato' => $d['formato'],
            'version' => Publicacion::query()->where('material_id', $material->id)->count() + 1, 'ficha' => $ficha, 'sha256' => hash('sha256', $a['contenido'])]);

        return response($a['contenido'], 200, ['Content-Type' => $a['mime'], 'Content-Disposition' => 'attachment; filename="'.$a['nombre'].'"']);
    }
}
