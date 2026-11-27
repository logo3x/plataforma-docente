<?php

namespace App\Http\Controllers;

use App\Material\Generadores;
use App\Models\Generador;
use App\Models\Materia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Generadores propios del docente (7.4.3) y su marca de «funcionó» para la biblioteca (8.4.5). */
class GeneradoresController extends Controller
{
    public function __construct(private readonly Generadores $generadores) {}

    public function index(Request $request): View
    {
        return view('generadores.index', [
            'generadores' => $this->generadores->disponibles($request->user()),
            'materias' => Materia::query()->where('user_id', $request->user()->id)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /** Nuevo, o copia de uno de fábrica o propio para ajustarlo («?desde=f:guia»). */
    public function create(Request $request): View
    {
        $base = $request->filled('desde') ? $this->generadores->resolver($request->user(), (string) $request->query('desde')) : null;

        return view('generadores.form', ['g' => null, 'datos' => $base ? [
            'nombre' => $base['nombre'].' (mío)', 'descripcion' => $base['descripcion'], 'instrucciones' => $base['instrucciones'],
            'campos' => Generadores::camposComoTexto($base['campos']), 'codigo' => $base['codigo'], 'origen' => $base['fabrica'] ? substr($base['clave'], 2) : null,
        ] : ['nombre' => '', 'descripcion' => '', 'instrucciones' => '', 'campos' => '', 'codigo' => 'no', 'origen' => null]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validar($request);
        $g = Generador::query()->create($d + ['user_id' => $request->user()->id]);

        return redirect()->route('generadores.index')->with('estado', "Generador «{$g->nombre}» creado: ya aparece en «Guías, manuales y otros documentos» de todas sus materias.");
    }

    public function edit(Request $request, Generador $generador): View
    {
        $this->autorizar($request, $generador);

        return view('generadores.form', ['g' => $generador, 'datos' => ['nombre' => $generador->nombre, 'descripcion' => $generador->descripcion,
            'instrucciones' => $generador->instrucciones, 'campos' => Generadores::camposComoTexto($generador->campos), 'codigo' => $generador->codigo, 'origen' => $generador->origen]]);
    }

    public function update(Request $request, Generador $generador): RedirectResponse
    {
        $this->autorizar($request, $generador);
        $generador->update($this->validar($request));

        return redirect()->route('generadores.index')->with('estado', "Generador «{$generador->nombre}» guardado.");
    }

    public function destroy(Request $request, Generador $generador): RedirectResponse
    {
        $this->autorizar($request, $generador);
        $generador->delete();

        return redirect()->route('generadores.index')->with('estado', "Generador «{$generador->nombre}» eliminado. Los documentos que creó se conservan.");
    }

    /** 8.4.5: el generador que funcionó queda primero y marcado en la biblioteca, con lo que el docente quiera recordar. */
    public function funciono(Request $request, Generador $generador): RedirectResponse
    {
        $this->autorizar($request, $generador);
        $d = $request->validate(['funciono' => ['required', 'boolean'], 'nota_funciono' => ['nullable', 'string', 'max:300']]);
        $generador->update(['funciono' => (bool) $d['funciono'], 'nota_funciono' => $d['funciono'] ? ($d['nota_funciono'] ?? null) : null]);

        return back()->with('estado', $d['funciono'] ? "«{$generador->nombre}» quedó en la biblioteca como generador que funcionó." : "«{$generador->nombre}» salió de la biblioteca.");
    }

    private function validar(Request $request): array
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'instrucciones' => ['required', 'string', 'max:5000'],
            'campos' => ['nullable', 'string', 'max:3000'],
            'codigo' => ['required', Rule::in(array_keys(Generadores::CODIGO))],
            'origen' => ['nullable', Rule::in(array_keys(Generadores::FABRICA))],
        ]);
        [$campos, $errores] = $this->generadores->leerCampos((string) ($d['campos'] ?? ''), $d['instrucciones']);
        if ($errores) {
            throw ValidationException::withMessages(['campos' => $errores]);
        }

        return ['campos' => $campos] + $d;
    }

    private function autorizar(Request $request, Generador $g): void
    {
        abort_unless($g->user_id === $request->user()->id, 403);
    }
}
