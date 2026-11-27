<?php

namespace App\Evaluacion;

use App\Models\Artefacto;
use App\Models\ArtefactoPregunta;
use App\Models\Materia;
use App\Models\Pregunta;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Banco de preguntas administrable desde la interfaz (13.5): el examen se abre como banco «plataforma-banco»,
 * se edita ítem por ítem y se guarda con la misma validación de la importación (9.2.4, 9.2.5).
 *
 * Mientras el examen no tenga entregas, se edita en su lugar: cada pregunta conserva su identidad (rúbrica
 * asociada, anulación, comentarios frecuentes). Con entregas, cambiar una pregunta cambiaría lo que ya se
 * calificó: se edita una copia con otra sigla.
 */
final class EditorBanco
{
    /** Identificador interno del ítem en el editor; no es parte del formato. */
    public const ID = '_ap';

    public function __construct(private ImportadorBanco $importador) {}

    /** El examen completo como banco «plataforma-banco»; con $conIds, cada ítem lleva su identificador para editarlo. */
    public function exportar(Artefacto $a, bool $conIds = false): array
    {
        $config = $a->configuracion ?? [];

        return [
            'formato' => ImportadorBanco::FORMATO, 'version' => 1, 'sigla' => $a->sigla, 'titulo' => $a->titulo, 'tipo' => $a->tipo,
            'valor' => (string) ($config['valor'] ?? '0'),
            'configuracion' => array_diff_key($config, ['valor' => true]),
            'items' => $a->items()->with('pregunta')->get()
                ->map(fn (ArtefactoPregunta $ap) => ($conIds ? [self::ID => $ap->id] : []) + ImportadorBanco::aBanco($ap))->values()->all(),
        ];
    }

    /** Por qué no se puede editar en su lugar; null si se puede. */
    public function bloqueo(Artefacto $a): ?string
    {
        $n = $a->actividades()->withCount('entregas')->get()->sum('entregas_count');

        return $n > 0 ? "El examen ya tiene {$n} entrega(s): cambiar sus preguntas cambiaría lo que ya se calificó. Haga una copia con otra sigla y edite la copia." : null;
    }

    /** @return array<int, string> errores en lenguaje claro */
    public function errores(Artefacto $a, array $banco): array
    {
        $e = $this->importador->validar($this->sinIds($banco));
        if (filled($banco['sigla'] ?? null) && Artefacto::query()->where('sigla', $banco['sigla'])->whereKeyNot($a->getKey())->exists()) {
            $e[] = "Ya existe otra actividad con la sigla {$banco['sigla']}: use otra.";
        }

        return $e;
    }

    /** Guarda el banco editado sobre el mismo examen. Devuelve cuántos ítems quedaron, se agregaron y se quitaron. */
    public function guardar(Artefacto $a, array $banco): array
    {
        if ($b = $this->bloqueo($a)) {
            throw new InvalidArgumentException($b);
        }
        if ($e = $this->errores($a, $banco)) {
            throw new InvalidArgumentException(implode("\n", $e));
        }
        $temas = $this->temas($a->materia);

        return DB::transaction(function () use ($a, $banco, $temas) {
            $actuales = $a->items()->with('pregunta')->get()->keyBy('id');
            $conservados = [];
            $nuevos = 0;
            foreach (array_values($banco['items']) as $orden => $it) {
                $ap = $actuales->get((int) ($it[self::ID] ?? 0));
                $datos = $this->datosPregunta($it, $temas);
                if ($ap) {
                    // La rúbrica asociada solo tiene sentido si sigue siendo abierta
                    $ap->pregunta->update($datos + ($it['tipo'] !== 'abierta' ? ['rubrica_id' => null] : []));
                    $ap->update(['banco' => ImportadorBanco::versionDe($it), 'peso' => (string) $it['peso'], 'orden' => $orden]);
                    $conservados[] = $ap->id;
                } else {
                    $p = Pregunta::query()->create(['materia_id' => $a->materia_id] + $datos);
                    ArtefactoPregunta::query()->create(['artefacto_id' => $a->id, 'pregunta_id' => $p->id,
                        'banco' => ImportadorBanco::versionDe($it), 'peso' => (string) $it['peso'], 'orden' => $orden]);
                    $nuevos++;
                }
            }
            $quitados = $actuales->except($conservados);
            foreach ($quitados as $ap) {
                $ap->delete();
                if (! ArtefactoPregunta::query()->where('pregunta_id', $ap->pregunta_id)->exists()) {
                    $ap->pregunta->delete();
                }
            }
            $a->update(['sigla' => $banco['sigla'], 'titulo' => $banco['titulo'], 'tipo' => $banco['tipo'] ?? $a->tipo,
                'configuracion' => ['valor' => (string) BigDecimal::of((string) $banco['valor'])] + ($a->configuracion ?? [])]);

            return ['items' => count($banco['items']), 'nuevos' => $nuevos, 'quitados' => $quitados->count()];
        });
    }

    /** Banco nuevo hecho en el editor, sin archivo. */
    public function crear(Materia $materia, array $banco): Artefacto
    {
        return $this->importador->importar($materia, $this->sinIds($banco));
    }

    /** Copia del examen con otra sigla: misma configuración, mismas preguntas y las rúbricas de sus abiertas. */
    public function duplicar(Artefacto $a, string $sigla, string $titulo): Artefacto
    {
        $banco = ['sigla' => $sigla, 'titulo' => $titulo] + $this->exportar($a);
        $banco['configuracion'] = $a->configuracion ?? [];
        $copia = $this->importador->importar($a->materia, $banco);
        $copia->update(['origen_artefacto_id' => $a->id]);            // 2.8.3
        $rubricas = $a->items()->with('pregunta:id,rubrica_id')->get()->pluck('pregunta.rubrica_id')->values();
        foreach ($copia->items()->with('pregunta')->get()->values() as $i => $ap) {
            if ($rubricas[$i] ?? null) {
                $ap->pregunta->update(['rubrica_id' => $rubricas[$i]]);
            }
        }

        return $copia;
    }

    /**
     * Preguntas de los otros exámenes de la materia, para traerlas al que se edita.
     *
     * @return Collection<int, array{origen: string, version: string, item: array}>
     */
    public function deLaMateria(Materia $materia, ?Artefacto $excepto = null): Collection
    {
        return ArtefactoPregunta::query()->with('pregunta', 'artefacto:id,sigla,titulo')
            ->whereHas('artefacto', fn ($q) => $q->where('materia_id', $materia->id)->when($excepto, fn ($q) => $q->whereKeyNot($excepto->getKey())))
            ->orderByDesc('artefacto_id')->orderBy('orden')->get()
            ->map(fn (ArtefactoPregunta $ap) => ['origen' => $ap->artefacto->sigla, 'version' => $ap->banco,
                'item' => array_diff_key(ImportadorBanco::aBanco($ap), ['version' => true])])
            ->values();
    }

    private function sinIds(array $banco): array
    {
        $banco['items'] = array_map(fn ($it) => is_array($it) ? array_diff_key($it, [self::ID => true]) : $it, array_values((array) ($banco['items'] ?? [])));

        return $banco;
    }

    private function temas(Materia $materia): Collection
    {
        return $materia->temario()->where('tipo', 'tema')->get()->keyBy(fn ($t) => ImportadorBanco::normalizarTema($t->titulo));
    }

    private function datosPregunta(array $it, Collection $temas): array
    {
        [$enunciado, $clave] = $this->importador->separar($it);

        return [
            'temario_item_id' => filled($it['tema'] ?? null) ? $temas->get(ImportadorBanco::normalizarTema($it['tema']))?->id : null,
            'tipo' => $it['tipo'],
            'dificultad' => in_array($it['dificultad'] ?? null, ['baja', 'media', 'alta'], true) ? $it['dificultad'] : 'media',
            'enunciado' => $enunciado, 'clave' => $clave, 'justificacion' => filled($it['justificacion'] ?? null) ? $it['justificacion'] : null,
        ];
    }
}
