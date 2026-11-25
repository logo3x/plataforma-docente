<?php

namespace App\Livewire;

use App\Evaluacion\Rubricas;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\Respuesta;
use App\Models\Rubrica;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Calificación de respuestas abiertas por pregunta (6.7): el enunciado, la referencia y la
 * rúbrica una sola vez arriba, y debajo las respuestas de todas las entregas, legibles.
 */
class CalificarAbiertas extends Component
{
    #[Locked]
    public int $actividadId;

    #[Url(as: 'pregunta')]
    public ?int $itemId = null;

    /** Resaltado temporal de términos (6.7.9). */
    public string $buscar = '';

    /** @var array<int, string> */
    public array $errores = [];

    public function mount(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
        $this->actividadId = $actividad->id;
        $this->itemId ??= $this->items()->first()?->id;
    }

    public function puntuar(int $respuestaId, string $valor): void
    {
        $r = $this->respuesta($respuestaId);
        unset($this->errores[$respuestaId]);
        $valor = trim(str_replace(',', '.', $valor));

        if ($valor === '') {
            $r->update(['puntaje' => null, 'calificado_por' => null]);

            return;
        }
        try {
            $v = BigDecimal::of($valor);
        } catch (MathException) {
            $this->errores[$respuestaId] = 'No es un número.';

            return;
        }
        // 6.7.11: ningún puntaje fuera de 0..máximo del ítem
        if ($v->isNegative() || $v->isGreaterThan($r->puntaje_max)) {
            $this->errores[$respuestaId] = 'Debe estar entre 0 y '.BigDecimal::of($r->puntaje_max)->strippedOfTrailingZeros().'.';

            return;
        }
        // El número escrito a mano reemplaza lo que se había elegido en la rúbrica
        $r->update(['puntaje' => (string) $v->toScale(4, RoundingMode::HalfUp), 'calificado_por' => 'docente', 'desglose' => null]);
    }

    /**
     * Elige (o quita, con null) el nivel de un criterio de la rúbrica. Con todos los criterios
     * evaluados, el puntaje se calcula solo; mientras falte alguno, la respuesta sigue pendiente.
     */
    public function nivel(int $respuestaId, int $criterio, ?int $nivel): void
    {
        $r = $this->respuesta($respuestaId);
        $rubrica = $r->item->pregunta->rubrica;
        abort_if($rubrica === null || ! isset($rubrica->criterios[$criterio]), 422);
        abort_if($nivel !== null && ! isset($rubrica->criterios[$criterio]['niveles'][$nivel]), 422);
        unset($this->errores[$respuestaId]);

        $previa = $r->desglose['rubrica'] ?? null;
        $seleccion = ($r->desglose['rubrica_id'] ?? null) === $rubrica->id && is_array($previa['seleccion'] ?? null)
            ? $previa['seleccion'] : array_fill(0, count($rubrica->criterios), null);
        $seleccion[$criterio] = $nivel;

        $servicio = app(Rubricas::class);
        $puntaje = $servicio->puntaje($rubrica->criterios, $seleccion, (string) $r->puntaje_max);
        $r->update([
            'puntaje' => $puntaje,
            'calificado_por' => $puntaje === null ? null : 'docente',
            'desglose' => ['rubrica_id' => $rubrica->id] + $servicio->desglose($rubrica->only('titulo', 'criterios'), $seleccion, (string) $r->puntaje_max),
        ]);
    }

    /** Asocia a la pregunta una rúbrica de la materia, o la quita. */
    public function asociarRubrica(?int $rubricaId): void
    {
        $item = $this->items()->firstWhere('id', $this->itemId);
        abort_if($item === null, 404);
        $rubricaId = $rubricaId ? Rubrica::query()->where('materia_id', $item->pregunta->materia_id)->findOrFail($rubricaId)->id : null;
        $item->pregunta->update(['rubrica_id' => $rubricaId]);
    }

    public function comentar(int $respuestaId, string $texto): void
    {
        $this->respuesta($respuestaId)->update(['comentario' => trim($texto) ?: null]);
    }

    public function render(): View
    {
        $item = $this->itemId ? $this->items()->firstWhere('id', $this->itemId) : null;
        $respuestas = $item
            ? Respuesta::query()->where('artefacto_pregunta_id', $item->id)->with('entrega.integrantes')->get()
                ->sortBy(fn ($r) => $r->entrega->integrantes->first()?->apellidos)->values()
            : collect();

        return view('livewire.calificar-abiertas', [
            'items' => $this->items(),
            'rubricas' => $item ? Rubrica::query()->where('materia_id', $item->pregunta->materia_id)->orderBy('titulo')->get(['id', 'titulo']) : collect(),
            'item' => $item,
            'respuestas' => $respuestas,
            'pendientes' => $respuestas->whereNull('puntaje')->count(),
        ]);
    }

    /** @return Collection<int, ArtefactoPregunta> */
    private function items()
    {
        $actividad = Actividad::query()->findOrFail($this->actividadId);

        return ArtefactoPregunta::query()->where('artefacto_id', $actividad->artefacto_id)
            ->whereHas('pregunta', fn ($q) => $q->where('tipo', 'abierta'))
            ->with('pregunta.rubrica')->orderBy('orden')->get();
    }

    private function respuesta(int $id): Respuesta
    {
        $r = Respuesta::query()->with('entrega', 'item.pregunta.rubrica')->findOrFail($id);
        abort_unless($r->entrega->actividad_id === $this->actividadId && $r->item->pregunta->tipo === 'abierta', 403);

        return $r;
    }
}
