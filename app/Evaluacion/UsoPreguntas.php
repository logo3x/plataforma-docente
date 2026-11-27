<?php

namespace App\Evaluacion;

use App\Models\Artefacto;
use App\Models\ArtefactoPregunta;
use App\Models\Dictado;
use App\Models\Estudiante;
use App\Models\Materia;
use App\Models\Restauracion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Historial de uso de preguntas entre dictados (5.4.13). «Ya la vio» se mide por lo que el estudiante
 * respondió de verdad: sus respuestas en entregas de periodos anteriores de la misma materia, en la versión
 * que le tocó. Las copias de un examen crean preguntas nuevas, así que una pregunta se reconoce por su tipo
 * y su enunciado, no por su id: copiarla no la vuelve nueva para quien ya la contestó.
 */
final class UsoPreguntas
{
    /** @var array<string, array<string, array{estudiantes: int, periodos: array<int, string>}>> */
    private array $cache = [];

    public static function huella(string $tipo, ?string $texto): string
    {
        return sha1($tipo.'|'.Str::of((string) $texto)->lower()->squish());
    }

    /** El periodo para el que se arma el examen: el más reciente de la materia. */
    public function destino(Materia $materia): ?Dictado
    {
        return $materia->dictados()->orderByDesc('id')->first();
    }

    /**
     * Preguntas que ya respondieron, en periodos anteriores, estudiantes del periodo destino (por documento),
     * opcionalmente solo de unos grupos.
     *
     * @param  array<int, int>|null  $grupos
     * @return array<string, array{estudiantes: int, periodos: array<int, string>}> huella → cuántos y en qué periodos
     */
    public function vistas(Dictado $destino, ?array $grupos = null): array
    {
        $k = $destino->id.'|'.implode(',', $grupos ?? []);
        if (isset($this->cache[$k])) {
            return $this->cache[$k];
        }
        $documentos = Estudiante::query()->where('dictado_id', $destino->id)
            ->when($grupos, fn ($q) => $q->whereHas('grupos', fn ($g) => $g->whereIn('grupos.id', $grupos)))->pluck('documento');
        if ($documentos->isEmpty()) {
            return $this->cache[$k] = [];
        }

        $filas = DB::table('respuestas as r')
            ->join('entregas as e', 'e.id', '=', 'r.entrega_id')
            ->join('entrega_estudiante as ee', 'ee.entrega_id', '=', 'e.id')
            ->join('estudiantes as s', 's.id', '=', 'ee.estudiante_id')
            ->join('dictados as d', 'd.id', '=', 's.dictado_id')
            ->join('artefacto_pregunta as ap', 'ap.id', '=', 'r.artefacto_pregunta_id')
            ->join('preguntas as p', 'p.id', '=', 'ap.pregunta_id')
            ->where('d.materia_id', $destino->materia_id)->whereNotIn('d.id', $this->mismoPeriodo($destino))
            ->whereIn('s.documento', $documentos)
            ->get(['p.tipo', 'p.enunciado', 's.documento', 'd.periodo']);

        $r = [];
        foreach ($filas as $f) {
            $h = self::huella($f->tipo, json_decode($f->enunciado, true)['texto'] ?? '');
            $r[$h]['docs'][$f->documento] = true;
            $r[$h]['periodos'][$f->periodo] = true;
        }

        return $this->cache[$k] = array_map(fn ($x) => ['estudiantes' => count($x['docs']), 'periodos' => array_keys($x['periodos'])], $r);
    }

    /**
     * El periodo destino y, si es la restauración de un respaldo, el periodo del que salió: son los mismos
     * estudiantes en el mismo semestre, no quienes repiten la materia.
     *
     * @return array<int, int>
     */
    private function mismoPeriodo(Dictado $destino): array
    {
        $original = Restauracion::query()->where('dictado_id', $destino->id)->latest('id')->value('periodo_original');

        return Dictado::query()->where('materia_id', $destino->materia_id)
            ->where(fn ($q) => $q->whereKey($destino->id)->when($original, fn ($o) => $o->orWhere('periodo', $original)))->pluck('id')->all();
    }

    /** La marca de un ítem del banco, o null si nadie del periodo la ha visto. */
    public function de(ArtefactoPregunta $ap, Dictado $destino, ?array $grupos = null): ?array
    {
        return $this->vistas($destino, $grupos)[self::huella($ap->pregunta->tipo, $ap->pregunta->enunciado['texto'] ?? '')] ?? null;
    }

    /**
     * Cuántas preguntas del examen ya vieron estudiantes de los grupos donde se aplica, para avisar al aplicar.
     *
     * @return array{preguntas: int, estudiantes: int, periodos: array<int, string>}|null
     */
    public function resumen(Artefacto $a, Dictado $destino, array $grupos): ?array
    {
        $vistas = $this->vistas($destino, $grupos);
        $marcas = $a->items()->with('pregunta')->get()->map(fn ($ap) => $vistas[self::huella($ap->pregunta->tipo, $ap->pregunta->enunciado['texto'] ?? '')] ?? null)->filter();
        if ($marcas->isEmpty()) {
            return null;
        }

        return ['preguntas' => $marcas->count(), 'estudiantes' => $marcas->max('estudiantes'),
            'periodos' => $marcas->flatMap(fn ($m) => $m['periodos'])->unique()->sort()->values()->all()];
    }
}
