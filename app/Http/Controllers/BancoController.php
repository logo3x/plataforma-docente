<?php

namespace App\Http\Controllers;

use App\Evaluacion\EditorBanco;
use App\Evaluacion\UsoPreguntas;
use App\Historial\Versiones;
use App\Models\Artefacto;
use App\Models\Materia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Banco de preguntas administrable desde la interfaz (13.5). */
class BancoController extends Controller
{
    public function __construct(private EditorBanco $editor) {}

    public function create(Materia $materia): View
    {
        Gate::authorize('gestionar', $materia);
        $banco = ['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => '', 'titulo' => '', 'tipo' => 'quiz', 'valor' => '5', 'configuracion' => [],
            'items' => [['tipo' => 'unica', 'version' => 'A', 'peso' => '5', 'enunciado' => '', 'opciones' => ['a' => '', 'b' => ''], 'correcta' => 'a']]];

        return $this->editorView($materia, null, session('banco_previo', $banco));
    }

    public function store(Request $request, Materia $materia): RedirectResponse
    {
        Gate::authorize('gestionar', $materia);
        $banco = $this->leer($request);
        try {
            $a = $this->editor->crear($materia, $banco);
        } catch (InvalidArgumentException $e) {
            return back()->with('banco_previo', $banco)->with('errores_banco', explode("\n", $e->getMessage()));
        }

        return redirect()->route('bancos.edit', $a)->with('estado', "Examen {$a->sigla} creado con {$a->items()->count()} ítems. Aplíquelo en un corte desde Actividades evaluables.");
    }

    public function edit(Artefacto $artefacto): View
    {
        Gate::authorize('gestionar', $artefacto->materia);

        return $this->editorView($artefacto->materia, $artefacto, session('banco_previo', $this->editor->exportar($artefacto, true)));
    }

    public function update(Request $request, Artefacto $artefacto): RedirectResponse
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $banco = $this->leer($request);
        try {
            // 7.6: la foto de cómo estaba solo queda si el cambio se guarda
            $r = DB::transaction(function () use ($artefacto, $banco) {
                app(Versiones::class)->guardar($artefacto);

                return $this->editor->guardar($artefacto, $banco);
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('banco_previo', $banco)->with('errores_banco', explode("\n", $e->getMessage()));
        }

        return redirect()->route('bancos.edit', $artefacto)->with('estado', "Banco guardado: {$r['items']} ítems ({$r['nuevos']} nuevos, {$r['quitados']} quitados)."
            .($artefacto->actividades()->exists() ? ' Ya está aplicado: vuelva a descargar el examen para que los cambios lleguen al archivo.' : ''));
    }

    public function duplicar(Request $request, Artefacto $artefacto): RedirectResponse
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $d = $request->validate(['sigla' => ['required', 'string', 'max:40'], 'titulo' => ['required', 'string', 'max:255']]);
        try {
            $copia = $this->editor->duplicar($artefacto, $d['sigla'], $d['titulo']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['sigla' => $e->getMessage()])->withInput();
        }

        return redirect()->route('bancos.edit', $copia)->with('estado', "Copia {$copia->sigla} creada a partir de {$artefacto->sigla}. Esta sí se puede editar.");
    }

    /** El banco en JSON, para guardarlo o llevarlo a otra instalación. Lleva las respuestas: es solo para el docente. */
    public function descargar(Artefacto $artefacto): Response
    {
        Gate::authorize('gestionar', $artefacto->materia);
        $json = json_encode($this->editor->exportar($artefacto), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="banco-'.$artefacto->sigla.'.json"', 'Cache-Control' => 'no-store']);
    }

    private function leer(Request $request): array
    {
        $request->validate(['banco' => ['required', 'string', 'max:2000000']]);
        $banco = json_decode($request->string('banco'), true);

        return is_array($banco) ? $banco : ['formato' => null];
    }

    private function editorView(Materia $materia, ?Artefacto $a, array $banco): View
    {
        // 5.4.13: qué preguntas ya respondieron, en periodos anteriores, estudiantes del periodo actual
        $uso = app(UsoPreguntas::class);
        $destino = $uso->destino($materia);
        $vistas = $destino ? $uso->vistas($destino) : [];
        $marca = fn (array $it) => $vistas[UsoPreguntas::huella((string) ($it['tipo'] ?? ''), $it['enunciado'] ?? '')] ?? null;

        return view('bancos.editor', [
            'materia' => $materia, 'a' => $a, 'banco' => $banco,
            'bloqueo' => $a ? $this->editor->bloqueo($a) : null,
            'errores' => session('errores_banco', []),
            'temas' => $materia->temario()->where('tipo', 'tema')->orderBy('orden')->pluck('titulo'),
            'otras' => $this->editor->deLaMateria($materia, $a)->map(fn ($o) => $o + ['vista' => $marca($o['item'])]),
            'vistasItems' => (object) collect($banco['items'] ?? [])->filter(fn ($it) => is_array($it) && isset($it[EditorBanco::ID]) && $marca($it))
                ->mapWithKeys(fn ($it) => [$it[EditorBanco::ID] => $marca($it)])->all(),
            'destino' => $destino,
        ]);
    }
}
