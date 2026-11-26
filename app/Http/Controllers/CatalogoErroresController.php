<?php

namespace App\Http\Controllers;

use App\Evaluacion\CatalogoErrores;
use App\Models\ErrorFrecuente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Catálogo de errores frecuentes del docente (4.4.4, anexo A). */
class CatalogoErroresController extends Controller
{
    public function __construct(private readonly CatalogoErrores $catalogo) {}

    public function index(Request $request): View
    {
        $docente = $request->user();
        $this->catalogo->asegurarBase($docente);
        $lenguaje = array_key_exists($request->query('lenguaje'), CatalogoErrores::LENGUAJES) ? $request->query('lenguaje') : 'javascript';
        $aula = $this->catalogo->enElAula($docente);

        $prueba = null;
        if (filled($request->query('mensaje'))) {
            $entradas = $this->catalogo->vigentes($docente, $docente->materias()->find($request->query('materia')), [$lenguaje]);
            $prueba = ['mensaje' => (string) $request->query('mensaje'), 'resultado' => $this->catalogo->explicar($entradas, [$lenguaje], (string) $request->query('mensaje'))];
        }

        return view('catalogo-errores.index', [
            'lenguaje' => $lenguaje,
            'entradas' => $docente->erroresFrecuentes()->with('materia')->where('lenguaje', $lenguaje)->orderByDesc('activo')->orderBy('patron')->get(),
            'cuentas' => $docente->erroresFrecuentes()->selectRaw('lenguaje, count(*) as n')->groupBy('lenguaje')->pluck('n', 'lenguaje'),
            'materias' => $docente->materias()->orderBy('nombre')->get(['id', 'nombre']),
            'usos' => $aula['usos'],
            'sinExplicar' => $aula['sin_explicar']->where('lenguaje', $lenguaje)->values(),
            'sinExplicarTotal' => $aula['sin_explicar']->countBy('lenguaje'),
            'prueba' => $prueba,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->erroresFrecuentes()->create($this->datos($request));

        return redirect()->route('catalogo-errores.index', ['lenguaje' => $request->input('lenguaje')])->with('estado', 'Entrada agregada al catálogo.');
    }

    public function update(Request $request, ErrorFrecuente $error): RedirectResponse
    {
        abort_unless($error->user_id === $request->user()->id, 403);
        $error->update($this->datos($request) + ['activo' => $request->boolean('activo')]);

        return redirect()->route('catalogo-errores.index', ['lenguaje' => $error->lenguaje])->with('estado', 'Entrada guardada.');
    }

    public function destroy(Request $request, ErrorFrecuente $error): RedirectResponse
    {
        abort_unless($error->user_id === $request->user()->id, 403);
        $error->delete();

        return redirect()->route('catalogo-errores.index', ['lenguaje' => $error->lenguaje])->with('estado', 'Entrada eliminada.');
    }

    public function restaurar(Request $request): RedirectResponse
    {
        $n = $this->catalogo->restaurarBase($request->user());

        return back()->with('estado', $n ? "Se agregaron {$n} entradas de la base." : 'El catálogo ya tiene todas las entradas de la base.');
    }

    private function datos(Request $request): array
    {
        $d = $request->validate([
            'lenguaje' => ['required', Rule::in(array_keys(CatalogoErrores::LENGUAJES))],
            'patron' => ['required', 'string', 'max:300'],
            'es_regex' => ['boolean'],
            'explicacion' => ['required', 'string', 'max:500'],
            'revision' => ['nullable', 'string', 'max:500'],
            'materia_id' => ['nullable', Rule::exists('materias', 'id')->where('user_id', $request->user()->id)],
        ], [], ['patron' => 'mensaje', 'explicacion' => 'explicación', 'revision' => 'qué revisar']);
        $d['es_regex'] = (bool) ($d['es_regex'] ?? false);
        if ($d['es_regex'] && ! CatalogoErrores::regexValida($d['patron'])) {
            throw ValidationException::withMessages(['patron' => 'No es una expresión regular válida.']);
        }

        return $d;
    }
}
