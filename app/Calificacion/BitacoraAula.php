<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\ArtefactoPregunta;
use App\Models\Ensayo;
use App\Models\EntradaBitacora;
use App\Models\Materia;
use App\Models\Reclamo;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Bitácora de retroalimentación de aula (anexo B): lo que aparece en cada aplicación, convertido si procede
 * en lección aprendida o en requerimiento. Las lecciones marcadas para la IA entran en los prompts de la
 * materia, para que lo que se genere después ya las tenga en cuenta.
 */
final class BitacoraAula
{
    public const DESTINOS = ['pendiente' => 'Pendiente', 'leccion' => 'Lección aprendida', 'requerimiento' => 'Requerimiento', 'descartada' => 'Descartada'];

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Umbral para sugerir una actividad por sus alertas: esta fracción de las entregas o más. */
    private const FRACCION_ALERTAS = 0.3;

    private const ALERTAS = ['duracion_anomala' => 'duración anómala', 'texto_identico' => 'texto idéntico', 'salidas' => 'salidas de la ventana',
        'cierre_automatico' => 'cierre por tiempo', 'manipulado' => 'identidad que no coincide', 'version_no_coincide' => 'versión distinta a la de su documento'];

    /**
     * Cosas que la plataforma ya registró y valen una entrada: ítems anulados, reclamos aceptados, ensayos en
     * los que el equipo no quedó listo y actividades con muchas entregas con la misma alerta. No vuelve a
     * sugerir lo ya anotado o descartado.
     *
     * @return Collection<int, array{origen: string, clave: string, fecha: CarbonInterface, materia_id: ?int, actividad_id: ?int, observacion: string}>
     */
    public function sugerencias(User $docente): Collection
    {
        $materias = $docente->materias()->pluck('id');
        $s = collect();

        ArtefactoPregunta::query()->whereNotNull('anulada_en')->whereHas('artefacto', fn ($q) => $q->whereIn('materia_id', $materias))
            ->with('artefacto.actividades', 'pregunta')->get()
            ->each(function (ArtefactoPregunta $ap) use ($s) {
                $s->push(['origen' => 'anulacion', 'clave' => (string) $ap->id, 'fecha' => $ap->anulada_en, 'materia_id' => $ap->artefacto->materia_id,
                    'actividad_id' => $ap->artefacto->actividades->first()?->id,
                    'observacion' => 'Ítem '.($ap->orden + 1)." anulado en {$ap->artefacto->sigla} («".Str::limit($ap->pregunta->enunciado['texto'] ?? '', 90).'»)'
                        .($ap->motivo_anulacion ? ": {$ap->motivo_anulacion}" : '.')]);
            });

        Reclamo::query()->where('estado', 'aceptado')->whereHas('actividad.componente.division.dictado', fn ($q) => $q->whereIn('materia_id', $materias))
            ->with('actividad.componente.division.dictado')->get()
            ->each(function (Reclamo $r) use ($s) {
                $s->push(['origen' => 'reclamo', 'clave' => (string) $r->id, 'fecha' => $r->atendido_en ?? $r->updated_at,
                    'materia_id' => $r->actividad->componente->division->dictado->materia_id, 'actividad_id' => $r->actividad_id,
                    'observacion' => "Reclamo aceptado en «{$r->actividad->nombre}»: ".Str::limit($r->descripcion, 120).($r->resolucion ? ' Resolución: '.Str::limit($r->resolucion, 160) : '')]);
            });

        Ensayo::query()->where('equipo_listo', false)->whereHas('artefacto', fn ($q) => $q->whereIn('materia_id', $materias))->with('artefacto')->get()
            ->each(function (Ensayo $e) use ($s) {
                $fallas = collect($e->verificaciones)->filter(fn ($v) => empty($v['ok']))->pluck('nombre')->take(4)->join('; ');
                $s->push(['origen' => 'ensayo', 'clave' => (string) $e->id, 'fecha' => $e->created_at, 'materia_id' => $e->artefacto->materia_id, 'actividad_id' => $e->actividad_id,
                    'observacion' => "Ensayo de {$e->artefacto->sigla} en {$e->equipo}: el equipo no quedó listo"
                        .($fallas ? " ({$fallas})" : ($e->cifrada ? '.' : ': la entrega salió sin cifrar.'))]);
            });

        Actividad::query()->whereHas('componente.division.dictado', fn ($q) => $q->whereIn('materia_id', $materias))
            ->whereHas('entregas', null, '>=', 4)->withCount('entregas')->with('componente.division.dictado')->get()
            ->each(function (Actividad $a) use ($s) {
                $porTipo = Alerta::query()->where('actividad_id', $a->id)->whereIn('tipo', array_keys(self::ALERTAS))
                    ->selectRaw('tipo, count(distinct entrega_id) as n, max(created_at) as ultima')->groupBy('tipo')->get();
                foreach ($porTipo as $t) {
                    if ($t->n / $a->entregas_count >= self::FRACCION_ALERTAS) {
                        $s->push(['origen' => 'alertas', 'clave' => "{$a->id}-{$t->tipo}", 'fecha' => Carbon::parse($t->ultima),
                            'materia_id' => $a->componente->division->dictado->materia_id, 'actividad_id' => $a->id,
                            'observacion' => "En «{$a->nombre}», {$t->n} de {$a->entregas_count} entregas con alerta de ".self::ALERTAS[$t->tipo].'.']);
                    }
                }
            });

        $hechas = EntradaBitacora::query()->where('user_id', $docente->id)->where('origen', '!=', 'manual')->get(['origen', 'origen_clave'])
            ->map(fn ($e) => "{$e->origen}|{$e->origen_clave}")->flip();

        return $s->reject(fn ($x) => isset($hechas["{$x['origen']}|{$x['clave']}"]))->sortByDesc('fecha')->values();
    }

    /** Anota (pendiente) o descarta una sugerencia. */
    public function registrarSugerencia(User $docente, string $origen, string $clave, bool $descartar): ?EntradaBitacora
    {
        $x = $this->sugerencias($docente)->first(fn ($x) => $x['origen'] === $origen && $x['clave'] === $clave);
        if ($x === null) {
            return null;
        }

        return EntradaBitacora::query()->create([
            'user_id' => $docente->id, 'materia_id' => $x['materia_id'], 'actividad_id' => $x['actividad_id'], 'fecha' => $x['fecha']->toDateString(),
            'observacion' => $x['observacion'], 'destino' => $descartar ? 'descartada' : 'pendiente', 'origen' => $origen, 'origen_clave' => $clave,
        ]);
    }

    /** Lecciones para la IA de una materia, como viñetas para el prompt (capa 2). */
    public function leccionesParaIa(Materia $materia): string
    {
        return EntradaBitacora::query()->where('user_id', $materia->user_id)->where('materia_id', $materia->id)
            ->where('destino', 'leccion')->where('para_ia', true)->whereNotNull('leccion')
            ->latest('fecha')->limit(15)->pluck('leccion')
            ->map(fn ($l) => '- '.Str::limit(trim(preg_replace('/\s+/u', ' ', $l)), 300))->join("\n");
    }

    /** La bitácora como la tabla del anexo B, para llevarla al documento de requerimientos. */
    public function comoAnexoB(Collection $entradas): string
    {
        $celda = fn (?string $t) => str_replace(['|', "\n", "\r"], ['\|', ' ', ''], trim((string) $t));
        $filas = $entradas->map(fn (EntradaBitacora $e) => '| '.implode(' | ', [
            $e->fecha->day.' de '.self::MESES[$e->fecha->month - 1].' de '.$e->fecha->year,
            $celda(trim(($e->materia?->nombre ?? 'General').($e->actividad ? ', '.$e->actividad->nombre : ''))),
            $celda($e->observacion.($e->leccion ? ' Lección: '.$e->leccion : '')),
            $celda($e->referencia ?: self::DESTINOS[$e->destino]),
        ]).' |');

        return "| Fecha | Materia y actividad | Observación | Destino |\n|---|---|---|---|\n".$filas->join("\n")."\n";
    }
}
