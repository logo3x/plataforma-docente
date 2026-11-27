<?php

namespace App\Notas;

use App\Calificacion\CalificadorEntrega;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Devolucion;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\NotaCambio;
use App\Models\ParticipanteVivo;
use App\Models\Reclamo;
use App\Models\SegundaOportunidad;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Historial de un estudiante a lo largo del semestre (13.6), enlazado con el registro de notas: su situación
 * (con el mismo cálculo del registro y del cierre), cada actividad con su nota, entrega, alertas, reclamos y
 * devolución, y una línea de tiempo con todo lo que pasó. Es una vista del docente: no se proyecta.
 */
final class HistorialEstudiante
{
    public const ALERTAS = ['manipulado' => 'Identidad no coincide', 'grupo_no_cuadra' => 'Otro grupo', 'sin_cifrar' => 'Sin cifrar', 'texto_identico' => 'Texto idéntico',
        'salidas' => 'Salidas', 'pegados' => 'Pegados', 'duracion_anomala' => 'Duración corta', 'cierre_automatico' => 'Cierre por tiempo', 'reconexion' => 'Reconexión',
        'integrante_desconocido' => 'Integrante no está en la lista', 'sin_grupo_trabajo' => 'Sin grupo de trabajo', 'estudiante_en_dos_entregas' => 'En dos entregas', 'version_no_coincide' => 'Versión distinta',
        'cierre_docente' => 'Cerrado por el docente', 'autor_distinto' => 'La subió otra persona', 'entrega_tardia' => 'Entregada tarde'];

    private const ORIGENES = ['digitada' => 'digitada', 'archivo' => 'cargada de archivo', 'calificador' => 'desde el calificador', 'grupal' => 'nota grupal',
        'migracion' => 'migrada', 'reclamo' => 'por reclamo', 'traslado' => 'trasladada de otro grupo', 'deshacer' => 'deshecha', 'ceros_explicitos' => 'vacía convertida en cero',
        'eliminacion' => 'actividad eliminada', 'supletorio' => 'supletorio', 'recuperacion' => 'recuperación', 'habilitacion' => 'habilitación'];

    public function __construct(private readonly CalificadorEntrega $calificador) {}

    /** @return array<string, mixed> */
    public function datos(Grupo $grupo, Estudiante $e): array
    {
        $dictado = $grupo->dictado;
        $tablero = new TableroNotas($grupo);
        $escala = $tablero->escala();
        $consolidado = collect($tablero->filasConsolidado())->first(fn ($f) => $f['estudiante']->id === $e->id);
        $simulacion = collect((new CierrePeriodo($grupo))->simulacion())->first(fn ($f) => $f['estudiante']->id === $e->id);

        $actividades = Actividad::query()->whereHas('componente.division', fn ($q) => $q->where('dictado_id', $dictado->id))
            ->where(fn ($q) => $q->whereDoesntHave('grupos')->orWhereHas('grupos', fn ($g) => $g->whereKey($grupo->id)))
            ->with('componente.division', 'artefacto')->get();
        $ids = $actividades->pluck('id');

        $entregas = Entrega::query()->whereIn('actividad_id', $ids)->whereHas('integrantes', fn ($q) => $q->whereKey($e->id))
            ->with('integrantes', 'alertas')->get()->groupBy('actividad_id');
        $alertas = Alerta::query()->whereIn('actividad_id', $ids)
            ->where(fn ($q) => $q->where('estudiante_id', $e->id)->orWhereIn('entrega_id', $entregas->flatten()->pluck('id')))->get();
        $reclamos = Reclamo::query()->whereIn('actividad_id', $ids)->where('estudiante_id', $e->id)->with('item')->get();
        $segundas = SegundaOportunidad::query()->where('estudiante_id', $e->id)->with('ambito', 'docente')->get();
        $devoluciones = Devolucion::query()->whereIn('actividad_id', $ids)->where('estudiante_id', $e->id)->get()->keyBy('actividad_id');
        $vivo = ParticipanteVivo::query()->where('documento', $e->documento)->whereHas('sesion', fn ($q) => $q->whereIn('actividad_id', $ids))
            ->with('sesion', 'solicitante')->get();
        $cambios = NotaCambio::query()->where('estudiante_id', $e->id)->whereIn('actividad_id', $ids)->orderBy('created_at')->orderBy('id')->get();
        $usuarios = User::query()->whereIn('id', $cambios->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        // Por división (pestaña del registro), cada actividad con lo suyo
        $porDivision = $tablero->pestanas()->map(function ($d) use ($actividades, $tablero, $e, $entregas, $alertas, $reclamos, $devoluciones, $consolidado) {
            $deEsta = $actividades->filter(fn ($a) => $a->componente->division_id === $d->id || $a->componente->division->parent_id === $d->id)
                ->sortBy(fn ($a) => [$a->componente->orden ?? 0, $a->orden, $a->id]);

            return [
                'division' => $d,
                'resultado' => $consolidado['divisiones'][$d->id] ?? null,
                'actividades' => $deEsta->map(fn (Actividad $a) => [
                    'actividad' => $a,
                    'nota' => $tablero->nota($a->id, $e->id),
                    'efectiva' => $tablero->notaEfectiva($a->id, $e->id),
                    'segunda' => $tablero->segunda($a->getMorphClass(), $a->id, $e->id),
                    'entregas' => $entregas->get($a->id, collect()),
                    'alertas' => $alertas->where('actividad_id', $a->id)->unique(fn ($x) => $x->tipo.'|'.$x->entrega_id)->values(),
                    'reclamos' => $reclamos->where('actividad_id', $a->id)->values(),
                    'devolucion' => $devoluciones->get($a->id),
                ])->values(),
            ];
        });

        $todasEntregas = $entregas->flatten();

        return [
            'escala' => $escala,
            'consolidado' => $consolidado,
            'simulacion' => $simulacion,
            'porDivision' => $porDivision,
            'resumen' => [
                'con_nota' => $actividades->filter(fn ($a) => $tablero->nota($a->id, $e->id) !== null)->count(),
                'actividades' => $actividades->count(),
                'ceros' => $actividades->filter(fn ($a) => ($n = $tablero->nota($a->id, $e->id)) !== null && (float) $n === 0.0)->count(),
                'entregas' => $todasEntregas->count(),
                'salidas' => $todasEntregas->sum(fn ($x) => count($x->sesion['salidas'] ?? [])),
                'alertas' => $alertas->unique(fn ($x) => $x->tipo.'|'.$x->entrega_id)->count(),
                'reclamos' => $reclamos->count(),
                'segundas' => $segundas->whereNull('anulada_en')->count(),
            ],
            'linea' => $this->linea($e, $actividades->keyBy('id'), $cambios, $usuarios, $todasEntregas, $alertas, $reclamos, $segundas, $devoluciones, $vivo, $escala),
        ];
    }

    /** Todo lo que pasó, del más reciente al más antiguo. */
    private function linea(Estudiante $e, Collection $act, Collection $cambios, Collection $usuarios, Collection $entregas, Collection $alertas,
        Collection $reclamos, Collection $segundas, Collection $devoluciones, Collection $vivo, Escala $escala): Collection
    {
        $nombre = fn ($id) => $act->get($id)?->nombre ?? 'actividad eliminada';
        $nota = fn (?string $v) => $v === null ? 'vacía' : $escala->mostrar($v);
        $l = collect();

        foreach ($cambios as $c) {
            // Los supletorios, recuperaciones y habilitaciones se muestran como segunda oportunidad, no dos veces
            if (isset(SegundaOportunidad::TIPOS[$c->origen]) || str_starts_with($c->origen, 'anula_')) {
                continue;
            }
            $l->push(['cuando' => $c->created_at, 'tipo' => 'nota', 'actividad_id' => $c->actividad_id,
                'texto' => "{$nombre($c->actividad_id)}: {$nota($c->valor_anterior)} → {$nota($c->valor_nuevo)}",
                'detalle' => trim((self::ORIGENES[$c->origen] ?? $c->origen).($c->user_id ? ' · '.($usuarios[$c->user_id] ?? '') : '').($c->motivo ? ' · '.$c->motivo : ''))]);
        }
        foreach ($entregas as $x) {
            $l->push(['cuando' => $x->created_at, 'tipo' => 'entrega', 'actividad_id' => $x->actividad_id,
                'enlace' => $x->tipo === 'archivo' ? route('calificador.archivos.calificar', $x) : route('calificador.detalle', $x),
                'texto' => "Entrega de {$nombre($x->actividad_id)}".($x->tipo === 'archivo' ? " ({$x->archivo_nombre})" : ''),
                'detalle' => collect([$x->duracion_seg !== null ? 'duró '.intdiv($x->duracion_seg, 60).' min' : null, $x->version ? "versión {$x->version}" : null,
                    ['automatico' => 'cerrada por tiempo', 'docente' => 'cerrada por el docente'][$x->cierre] ?? null,
                    $x->integrantes->count() > 1 ? 'con '.$x->integrantes->reject(fn ($i) => $i->id === $e->id)->map->nombreCompleto()->join(', ') : null])->filter()->join(' · ')]);
        }
        foreach ($alertas->unique(fn ($x) => $x->tipo.'|'.$x->entrega_id) as $a) {
            $l->push(['cuando' => $a->created_at, 'tipo' => 'alerta', 'actividad_id' => $a->actividad_id,
                'texto' => (self::ALERTAS[$a->tipo] ?? $a->tipo)." en {$nombre($a->actividad_id)}",
                'detalle' => match ($a->tipo) {
                    'salidas' => ($a->datos['cantidad'] ?? '?').' salidas, '.($a->datos['segundos'] ?? '?').' s fuera',
                    'duracion_anomala' => 'duró '.round(($a->datos['segundos'] ?? 0) / 60).' min',
                    'cierre_docente' => trim(($a->datos['por'] ?? '').(! empty($a->datos['sin_respuesta']) ? ' · su equipo no respondió: se registró lo último que envió' : '')),
                    default => '',
                }]);
        }
        foreach ($reclamos as $r) {
            $l->push(['cuando' => $r->created_at, 'tipo' => 'reclamo', 'actividad_id' => $r->actividad_id, 'texto' => "Reclamo en {$nombre($r->actividad_id)}",
                'detalle' => Str::limit($r->descripcion, 160)]);
            if ($r->atendido_en) {
                $l->push(['cuando' => $r->atendido_en, 'tipo' => 'reclamo', 'actividad_id' => $r->actividad_id,
                    'texto' => 'Reclamo '.($r->estado === 'aceptado' ? 'aceptado' : 'rechazado')." en {$nombre($r->actividad_id)}", 'detalle' => Str::limit((string) $r->resolucion, 160)]);
            }
        }
        foreach ($segundas as $s) {
            $ambito = $s->ambito instanceof Actividad ? $s->ambito->nombre : ($s->ambito?->nombre ?? 'la materia');
            $l->push(['cuando' => $s->created_at, 'tipo' => 'segunda', 'actividad_id' => $s->ambito instanceof Actividad ? $s->ambito->id : null,
                'texto' => SegundaOportunidad::TIPOS[$s->tipo]." de {$ambito}: {$escala->mostrar((string) $s->valor)}",
                'detalle' => SegundaOportunidad::REGLAS[$s->regla].($s->docente ? ' · '.$s->docente->name : '')]);
            if ($s->anulada_en) {
                $l->push(['cuando' => $s->anulada_en, 'tipo' => 'segunda', 'texto' => SegundaOportunidad::TIPOS[$s->tipo]." de {$ambito} anulada", 'detalle' => (string) $s->motivo_anulacion]);
            }
        }
        foreach ($devoluciones as $d) {
            $l->push(['cuando' => $d->created_at, 'tipo' => 'devolucion', 'actividad_id' => $d->actividad_id, 'texto' => "Informe de {$nombre($d->actividad_id)} liberado", 'detalle' => '']);
            if ($d->abierta_en) {
                $l->push(['cuando' => $d->abierta_en, 'tipo' => 'devolucion', 'actividad_id' => $d->actividad_id, 'texto' => "Abrió su informe de {$nombre($d->actividad_id)}",
                    'detalle' => $d->aperturas > 1 ? "lo abrió {$d->aperturas} veces" : '']);
            }
        }
        foreach ($vivo as $p) {
            $l->push(['cuando' => $p->created_at, 'tipo' => 'vivo', 'actividad_id' => $p->sesion->actividad_id, 'texto' => "Entró a la sesión en vivo de {$nombre($p->sesion->actividad_id)}",
                'detalle' => $p->ingresos > 1 ? "{$p->ingresos} ingresos" : '']);
        }

        return $l->sortByDesc(fn ($x) => [$x['cuando']?->getTimestamp() ?? 0])->values();
    }
}
