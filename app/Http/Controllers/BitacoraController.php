<?php

namespace App\Http\Controllers;

use App\Calificacion\BitacoraAula;
use App\Models\Actividad;
use App\Models\EntradaBitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Bitácora de retroalimentación de aula (anexo B). */
class BitacoraController extends Controller
{
    public function __construct(private readonly BitacoraAula $bitacora) {}

    public function index(Request $request): View
    {
        $docente = $request->user();
        $actividad = $request->filled('actividad') ? Actividad::query()->with('componente.division.dictado')->find($request->integer('actividad')) : null;
        if ($actividad) {
            Gate::authorize('gestionar', $actividad->componente->division->dictado);
        }

        return view('bitacora.index', [
            'entradas' => $this->filtradas($request)->get(),
            'materias' => $docente->materias()->orderBy('nombre')->get(['id', 'nombre']),
            'sugerencias' => $this->bitacora->sugerencias($docente),
            'actividad' => $actividad,
            'filtros' => $request->only('materia', 'destino'),
            'cuentas' => EntradaBitacora::query()->where('user_id', $docente->id)->selectRaw('destino, count(*) as n')->groupBy('destino')->pluck('n', 'destino'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->datos($request);
        EntradaBitacora::query()->create($d + ['user_id' => $request->user()->id, 'origen' => 'manual']);

        return redirect()->route('bitacora.index')->with('estado', 'Observación anotada en la bitácora.');
    }

    public function update(Request $request, EntradaBitacora $entrada): RedirectResponse
    {
        abort_unless($entrada->user_id === $request->user()->id, 403);
        $entrada->update($this->datos($request));

        return redirect()->route('bitacora.index', $request->only('materia', 'destino'))->with('estado', 'Entrada guardada.');
    }

    public function destroy(Request $request, EntradaBitacora $entrada): RedirectResponse
    {
        abort_unless($entrada->user_id === $request->user()->id, 403);
        $entrada->delete();

        return back()->with('estado', 'Entrada eliminada.');
    }

    public function sugerencia(Request $request): RedirectResponse
    {
        $d = $request->validate(['origen' => ['required', 'string', 'max:20'], 'clave' => ['required', 'string', 'max:60'], 'descartar' => ['boolean']]);
        $e = $this->bitacora->registrarSugerencia($request->user(), $d['origen'], $d['clave'], (bool) ($d['descartar'] ?? false));
        abort_if($e === null, 404);

        return back()->with('estado', $e->destino === 'descartada' ? 'Sugerencia descartada.' : 'Anotada como pendiente: decida su destino cuando quiera.');
    }

    /** La bitácora como la tabla del anexo B (Markdown), con los filtros aplicados. */
    public function anexo(Request $request): Response
    {
        return response($this->bitacora->comoAnexoB($this->filtradas($request)->where('destino', '!=', 'descartada')->get()->sortBy('fecha')), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bitacora-anexo-b.md"',
        ]);
    }

    private function filtradas(Request $request)
    {
        return EntradaBitacora::query()->where('user_id', $request->user()->id)->with('materia', 'actividad')
            ->when($request->filled('materia'), fn ($q) => $q->where('materia_id', $request->integer('materia')))
            ->when(array_key_exists((string) $request->query('destino'), BitacoraAula::DESTINOS), fn ($q) => $q->where('destino', $request->query('destino')),
                fn ($q) => $q->where('destino', '!=', 'descartada'))
            ->orderByDesc('fecha')->orderByDesc('id');
    }

    private function datos(Request $request): array
    {
        $uid = $request->user()->id;
        $d = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'materia_id' => ['nullable', Rule::exists('materias', 'id')->where('user_id', $uid)],
            'actividad_id' => ['nullable', 'integer'],
            'observacion' => ['required', 'string', 'max:2000'],
            'destino' => ['required', Rule::in(array_keys(BitacoraAula::DESTINOS))],
            'referencia' => ['nullable', 'string', 'max:200'],
            'leccion' => ['nullable', 'string', 'max:1000', 'required_if:para_ia,1'],
            'para_ia' => ['boolean'],
        ], ['leccion.required_if' => 'Para que la IA la tenga en cuenta, escriba la lección.'], ['observacion' => 'observación', 'leccion' => 'lección']);

        // La actividad debe ser de una materia del docente, y la materia sale de ella
        if (! empty($d['actividad_id'])) {
            $a = Actividad::query()->with('componente.division.dictado.materia')->find($d['actividad_id']);
            abort_unless($a && $a->componente->division->dictado->materia->user_id === $uid, 403);
            $d['materia_id'] = $a->componente->division->dictado->materia_id;
        }
        $d['para_ia'] = (bool) ($d['para_ia'] ?? false) && $d['destino'] === 'leccion' && filled($d['materia_id'] ?? null);

        return $d;
    }
}
