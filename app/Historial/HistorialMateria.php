<?php

namespace App\Historial;

use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Material;
use App\Models\Rubrica;
use App\Models\TemarioItem;
use App\Models\UnidadDidactica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Historial de las actividades creadas en la materia (7.3): evaluaciones, rúbricas, unidades
 * didácticas y material de clase, con su origen (a mano o IA, motor y material que las originó),
 * el periodo en que se crearon y los periodos en que se aplicaron. Lo creado pertenece a la materia,
 * así que cualquier periodo lo hereda: se vuelve a aplicar o se copia para ajustarlo (7.3.3, 7.3.4).
 */
final class HistorialMateria
{
    /** Tipos del historial: etiqueta y si se puede copiar para ajustar. */
    public const TIPOS = [
        'evaluacion' => 'Evaluación', 'rubrica' => 'Rúbrica', 'unidad' => 'Unidad didáctica', 'apuntes' => 'Texto o apunte',
        'ejemplos' => 'Ejemplos prácticos', 'debate' => 'Preguntas de debate', 'vocabulario' => 'Vocabulario', 'proyectos' => 'Ideas de proyectos', 'presentacion' => 'Presentaciones',
    ];

    /** Prefijo de rutas de cada tipo de material. */
    public const RUTAS_MATERIAL = ['apuntes' => 'apuntes', 'ejemplos' => 'ejemplos', 'debate' => 'debates', 'vocabulario' => 'vocabularios', 'proyectos' => 'proyectos', 'presentacion' => 'presentaciones'];

    /**
     * @param  array{tipo?: ?string, dictado?: ?int, origen?: ?string, q?: ?string}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    public function entradas(Materia $materia, array $filtros = []): Collection
    {
        $dictados = $materia->dictados()->orderBy('created_at')->orderBy('id')->get();
        $generaciones = GeneracionIa::query()->where('materia_id', $materia->id)->where('estado', 'aprobado')->whereNotNull('resultado_id')
            ->with('motor:id,nombre')->get()->keyBy(fn ($g) => $g->resultado_type.'#'.$g->resultado_id);
        $ia = fn ($modelo) => $generaciones->get($modelo->getMorphClass().'#'.$modelo->getKey());

        $entradas = collect();

        foreach ($materia->artefactos()->withCount('items')->with('actividades.componente.division.dictado', 'actividades.grupos')->get() as $a) {
            $entradas->push($this->entrada('evaluacion', $a, $a->titulo, $ia($a), $dictados, [
                'detalle' => "{$a->sigla} · ".ucfirst($a->tipo)." · {$a->items_count} ítems".$this->copiaDe($a, 'origen_artefacto_id'),
                'aplicaciones' => $a->actividades->map(fn ($act) => [
                    'dictado_id' => $act->componente->division->dictado->id,
                    'texto' => $act->componente->division->dictado->periodo.' › '.$act->componente->division->nombre
                        .' ('.($act->grupos->pluck('nombre')->join(', ') ?: 'todos los grupos').')',
                    'url' => route('calificador.show', $act),
                ])->values()->all(),
                'abrir' => route('artefactos.index', $materia).'#artefacto-'.$a->id,
                'editar' => null,
            ]));
        }
        foreach ($materia->rubricas()->withCount('preguntas')->get() as $r) {
            $entradas->push($this->entrada('rubrica', $r, $r->titulo, $ia($r), $dictados, [
                'detalle' => count($r->criterios).' criterios'.($r->preguntas_count ? " · en {$r->preguntas_count} pregunta(s)" : ' · sin asociar').$this->copiaDe($r),
                'abrir' => route('rubricas.edit', $r), 'editar' => route('rubricas.edit', $r),
            ]));
        }
        foreach ($materia->unidadesDidacticas()->with('unidad')->get() as $u) {
            $entradas->push($this->entrada('unidad', $u, $u->titulo, $ia($u), $dictados, [
                'detalle' => count($u->contenido['sesiones'] ?? []).' sesión(es)'.($u->unidad ? " · {$u->unidad->titulo}" : '').$this->copiaDe($u),
                'abrir' => route('unidades.show', $u), 'editar' => route('unidades.edit', $u),
            ]));
        }
        foreach ($materia->materiales()->get() as $m) {
            if (! isset(self::RUTAS_MATERIAL[$m->tipo])) {
                continue;
            }
            $ruta = self::RUTAS_MATERIAL[$m->tipo];
            $coleccion = ['apuntes' => 'secciones', 'ejemplos' => 'ejemplos', 'debate' => 'preguntas', 'vocabulario' => 'terminos', 'proyectos' => 'proyectos', 'presentacion' => 'diapositivas'][$m->tipo];
            $entradas->push($this->entrada($m->tipo, $m, $m->titulo, $ia($m), $dictados, [
                'detalle' => count($m->contenido[$coleccion] ?? []).' '.['secciones' => 'sección(es)', 'ejemplos' => 'ejemplo(s)', 'preguntas' => 'pregunta(s)', 'terminos' => 'término(s)', 'proyectos' => 'proyecto(s)', 'diapositivas' => 'diapositiva(s)'][$coleccion].$this->copiaDe($m),
                'abrir' => route("{$ruta}.show", $m), 'editar' => route("{$ruta}.edit", $m),
            ]));
        }

        return $entradas
            ->when($filtros['tipo'] ?? null, fn ($c, $t) => $c->where('tipo', $t))
            ->when($filtros['origen'] ?? null, fn ($c, $o) => $c->where('origen', $o))
            ->when($filtros['dictado'] ?? null, fn ($c, $d) => $c->filter(fn ($e) => $e['creado_en_id'] === (int) $d || in_array((int) $d, array_column($e['aplicaciones'], 'dictado_id'), true)))
            ->when(filled($filtros['q'] ?? null), fn ($c) => $c->filter(fn ($e) => Str::contains(Str::lower(Str::ascii($e['titulo'].' '.$e['detalle'])), Str::lower(Str::ascii($filtros['q'])))))
            ->sortByDesc(fn ($e) => $e['creado']->timestamp * 10 + array_search($e['tipo'], array_keys(self::TIPOS), true))
            ->values();
    }

    /** Cuántas actividades hay de cada tipo, para el resumen. */
    public function conteos(Materia $materia): array
    {
        return [
            'evaluacion' => $materia->artefactos()->count(),
            'rubrica' => $materia->rubricas()->count(),
            'unidad' => $materia->unidadesDidacticas()->count(),
        ] + $materia->materiales()->select('tipo', DB::raw('count(*) as n'))->groupBy('tipo')->pluck('n', 'tipo')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Copia una actividad para ajustarla sin tocar la original (por ejemplo, para el periodo
     * siguiente). La copia queda como hecha a mano, con «(copia)» en el título. Con $destino, la copia va a
     * otra materia del mismo docente (7.9): lo que apunta al temario se busca por título en la otra materia
     * y, si no está, queda sin asignar.
     */
    public function copiar(Materia $materia, string $tipo, int $id, ?Materia $destino = null): Rubrica|UnidadDidactica|Material
    {
        $original = match (true) {
            $tipo === 'rubrica' => $materia->rubricas()->findOrFail($id),
            $tipo === 'unidad' => $materia->unidadesDidacticas()->findOrFail($id),
            isset(self::RUTAS_MATERIAL[$tipo]) => $materia->materiales()->where('tipo', $tipo)->findOrFail($id),
            default => throw new InvalidArgumentException('Ese tipo de actividad no se copia desde el historial.'),
        };
        $copia = $original->replicate(['generacion_ia_id', 'created_at', 'updated_at']);
        $copia->titulo = Str::limit($original->titulo, 240, '').' (copia)';
        $copia->origen_id = $original->getKey();                     // 2.8.3: la copia sabe de dónde salió
        if ($destino && $destino->isNot($materia)) {
            $copia->materia_id = $destino->id;
            $copia->titulo = Str::limit($original->titulo, 240, '');
            if (array_key_exists('temario_item_id', $copia->getAttributes()) && $copia->temario_item_id) {
                $copia->temario_item_id = self::mismoTema($copia->temario_item_id, $destino);
            }
        }
        if (! $original instanceof Rubrica) {
            $copia->contenido = ['titulo' => $copia->titulo] + $original->contenido;
        }
        $copia->save();

        return $copia;
    }

    /** El tema o la unidad del mismo título en otra materia, o null (7.9). */
    public static function mismoTema(?int $temarioItemId, Materia $destino): ?int
    {
        $titulo = $temarioItemId ? TemarioItem::query()->whereKey($temarioItemId)->value('titulo') : null;

        return $titulo ? $destino->temario()->where('titulo', $titulo)->value('id') : null;
    }

    /** 2.8.3: « · copia de «Original»» si salió de otra; el original puede haberse borrado. */
    private function copiaDe($modelo, string $columna = 'origen_id'): string
    {
        $id = $modelo->{$columna} ?? null;
        $titulo = $id ? $modelo::query()->whereKey($id)->value('titulo') : null;

        return $id ? ' · copia de «'.($titulo ?? 'un original ya borrado').'»' : '';
    }

    /** A dónde ir para editar la copia. */
    public function urlEdicion(Rubrica|UnidadDidactica|Material $m): string
    {
        return match (true) {
            $m instanceof Rubrica => route('rubricas.edit', $m),
            $m instanceof UnidadDidactica => route('unidades.edit', $m),
            default => route(self::RUTAS_MATERIAL[$m->tipo].'.edit', $m),
        };
    }

    /** @param  Collection<int, Dictado>  $dictados */
    private function entrada(string $tipo, $modelo, string $titulo, ?GeneracionIa $g, Collection $dictados, array $extra): array
    {
        $creado = Carbon::parse($modelo->created_at);
        // Periodo en que se creó: el último dictado abierto hasta esa fecha (o el primero, si es anterior a todos)
        $periodo = $dictados->filter(fn ($d) => $d->created_at <= $creado)->last() ?? $dictados->first();

        // Lo propio de cada tipo (detalle, aplicaciones, enlaces) va primero: en «+» manda la izquierda
        return $extra + [
            'tipo' => $tipo,
            'etiqueta' => self::TIPOS[$tipo],
            'id' => $modelo->getKey(),
            'titulo' => $titulo,
            'creado' => $creado,
            'creado_en_id' => $periodo?->id,
            'creado_en' => $periodo?->periodo,
            'origen' => $g ? 'ia' : 'manual',
            'motor' => $g?->motor?->nombre,
            'fuentes' => collect($g?->contenidos_contexto ?? [])->pluck('titulo')->filter()->values()->all(),
            'generacion_id' => $g?->id,
            'aplicaciones' => [],
        ];
    }
}
