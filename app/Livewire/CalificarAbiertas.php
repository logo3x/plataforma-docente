<?php

namespace App\Livewire;

use App\Calificacion\ComentariosIA;
use App\Calificacion\SugerenciasIA;
use App\Evaluacion\Rubricas;
use App\IA\MotorNoDisponible;
use App\IA\MotorNoPermitido;
use App\IA\RespuestaRechazada;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\ComentarioFrecuente;
use App\Models\Respuesta;
use App\Models\Rubrica;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
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

    /** Resultado de la última propuesta de comentarios con IA. */
    public ?string $avisoIa = null;

    public bool $avisoIaError = false;

    /** Nuevo comentario frecuente (6.7.13). */
    public string $nuevoFrecuente = '';

    public string $alcanceFrecuente = 'pregunta';

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
            SugerenciasIA::registrarDecision($r, null);

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
        SugerenciasIA::registrarDecision($r->fresh(), $r->puntaje);
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
        SugerenciasIA::registrarDecision($r->fresh(), $puntaje);
    }

    /** Decisión 9: la IA sugiere puntajes para las respuestas sin calificar; nada se aplica hasta que el docente acepta. */
    public function sugerirPuntajes(SugerenciasIA $sugerencias): void
    {
        $item = $this->itemActual();
        $actividad = Actividad::query()->findOrFail($this->actividadId);
        $this->avisoIaError = false;
        try {
            $n = $sugerencias->proponer(auth()->user(), $actividad, $item);
            $resto = $sugerencias->candidatas($item, $actividad)->count();
            $this->avisoIa = "La IA sugirió {$n} puntaje(s). Lea cada respuesta y decida: ninguno cuenta hasta que usted lo acepte o ponga el suyo."
                .($resto ? " Quedan {$resto} sin sugerencia: pulse de nuevo." : '');
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada|InvalidArgumentException $e) {
            [$this->avisoIa, $this->avisoIaError] = [$e->getMessage(), true];
        }
    }

    /** El docente acepta la sugerencia: se aplica como si la hubiera puesto él (con la rúbrica, nivel por nivel). */
    public function aceptarSugerencia(int $respuestaId): void
    {
        $r = $this->respuesta($respuestaId);
        $s = $r->sugerencia;
        abort_if(! is_array($s) || ($s['decision'] ?? null) === 'descartada', 422);
        $rubrica = $r->item->pregunta->rubrica;
        if ($rubrica && ($s['rubrica_id'] ?? null) === $rubrica->id && is_array($s['seleccion'] ?? null)) {
            foreach ($s['seleccion'] as $ci => $ni) {
                $this->nivel($respuestaId, $ci, $ni);
            }

            return;
        }
        $this->puntuar($respuestaId, (string) BigDecimal::of($s['puntaje'])->strippedOfTrailingZeros());
    }

    public function descartarSugerencia(int $respuestaId): void
    {
        $r = $this->respuesta($respuestaId);
        if (is_array($r->sugerencia)) {
            $r->update(['sugerencia' => ['decision' => 'descartada'] + $r->sugerencia]);
        }
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

    /** 8.5: la IA propone borradores para las respuestas calificadas sin comentario de esta pregunta. */
    public function proponerComentarios(ComentariosIA $comentarios): void
    {
        $item = $this->itemActual();
        $this->avisoIaError = false;
        try {
            $n = $comentarios->proponer(auth()->user(), Actividad::query()->findOrFail($this->actividadId), $item);
            $resto = $comentarios->candidatas($item, Actividad::query()->findOrFail($this->actividadId))->count();
            $this->avisoIa = "La IA propuso {$n} borrador(es). Léalos y use los que sirvan: el estudiante no ve nada hasta que usted lo decida."
                .($resto ? " Quedan {$resto} por proponer: pulse de nuevo." : '');
        } catch (MotorNoDisponible|MotorNoPermitido|RespuestaRechazada|InvalidArgumentException $e) {
            [$this->avisoIa, $this->avisoIaError] = [$e->getMessage(), true];
        }
    }

    /** El docente usa el borrador, tal cual o con sus cambios. */
    public function usarBorrador(int $respuestaId, string $texto): void
    {
        $r = $this->respuesta($respuestaId);
        $r->update(['comentario' => trim($texto) ?: null, 'comentario_borrador' => null]);
    }

    public function descartarBorrador(int $respuestaId): void
    {
        $this->respuesta($respuestaId)->update(['comentario_borrador' => null]);
    }

    /** Inserta un comentario frecuente al final del comentario de la respuesta (6.7.13). */
    public function insertarFrecuente(int $respuestaId, int $frecuenteId): void
    {
        $r = $this->respuesta($respuestaId);
        $f = $this->frecuentes()->firstWhere('id', $frecuenteId);
        abort_if($f === null, 404);
        $r->update(['comentario' => trim(trim((string) $r->comentario).' '.$f->texto)]);
        $f->increment('usos');
    }

    public function guardarFrecuente(): void
    {
        $texto = Str::limit(trim($this->nuevoFrecuente), 300, '');
        if (mb_strlen($texto) < 3) {
            return;
        }
        $item = $this->itemActual();
        ComentarioFrecuente::query()->firstOrCreate([
            'user_id' => auth()->id(), 'materia_id' => $item->pregunta->materia_id,
            'pregunta_id' => $this->alcanceFrecuente === 'pregunta' ? $item->pregunta_id : null, 'texto' => $texto,
        ]);
        $this->nuevoFrecuente = '';
    }

    public function eliminarFrecuente(int $id): void
    {
        $this->frecuentes()->firstWhere('id', $id)?->delete();
    }

    public function render(): View
    {
        $item = $this->itemId ? $this->items()->firstWhere('id', $this->itemId) : null;
        // Solo las entregas de ESTA actividad: el mismo examen puede estar aplicado en otro periodo
        $respuestas = $item
            ? Respuesta::query()->where('artefacto_pregunta_id', $item->id)->whereHas('entrega', fn ($q) => $q->where('actividad_id', $this->actividadId))
                ->with('entrega.integrantes')->get()
                ->sortBy(fn ($r) => $r->entrega->integrantes->first()?->apellidos)->values()
            : collect();

        return view('livewire.calificar-abiertas', [
            'items' => $this->items(),
            'rubricas' => $item ? Rubrica::query()->where('materia_id', $item->pregunta->materia_id)->orderBy('titulo')->get(['id', 'titulo']) : collect(),
            'item' => $item,
            'respuestas' => $respuestas,
            'pendientes' => $respuestas->whereNull('puntaje')->count(),
            'frecuentes' => $item ? $this->frecuentes() : collect(),
            'sinComentario' => $item ? app(ComentariosIA::class)->candidatas($item, Actividad::query()->findOrFail($this->actividadId))->count() : 0,
            'sinSugerencia' => $item ? app(SugerenciasIA::class)->candidatas($item, Actividad::query()->findOrFail($this->actividadId))->count() : 0,
            'calibracion' => SugerenciasIA::calibracion($respuestas),
        ]);
    }

    private function itemActual(): ArtefactoPregunta
    {
        $item = $this->items()->firstWhere('id', $this->itemId);
        abort_if($item === null, 404);

        return $item;
    }

    /** Frecuentes de esta pregunta y de toda la materia, los más usados primero. */
    private function frecuentes(): Collection
    {
        $item = $this->itemActual();

        return ComentarioFrecuente::query()->where('user_id', auth()->id())->where('materia_id', $item->pregunta->materia_id)
            ->where(fn ($q) => $q->whereNull('pregunta_id')->orWhere('pregunta_id', $item->pregunta_id))
            ->orderByDesc('usos')->orderBy('texto')->get();
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
