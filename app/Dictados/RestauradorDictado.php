<?php

namespace App\Dictados;

use App\Models\Actividad;
use App\Models\Artefacto;
use App\Models\Dictado;
use App\Models\Materia;
use App\Models\Restauracion;
use App\Models\SegundaOportunidad;
use App\Models\User;
use App\Notas\RegistroNotas;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Restaura un respaldo de dictado (RespaldoDictado) como un periodo NUEVO: nunca sobrescribe
 * nada. Las notas entran por el registro y cada una queda en la bitácora como restaurada; la
 * bitácora original no se reescribe con fechas pasadas, se guarda tal cual en la restauración.
 */
final class RestauradorDictado
{
    public function __construct(private readonly RegistroNotas $registro) {}

    /**
     * Revisa el archivo sin tocar nada.
     *
     * @return array{errores: array<int, string>, avisos: array<int, string>, resumen: array<string, mixed>}
     */
    public function revisar(User $docente, array $r): array
    {
        $e = [];
        $avisos = [];
        if (! RespaldoDictado::esFormato($r['formato'] ?? null)) {
            return ['errores' => ['El archivo no es un respaldo de periodo de esta plataforma.'], 'avisos' => [], 'resumen' => []];
        }
        $version = (int) ($r['version'] ?? 0);
        if ($version < 1 || $version > RespaldoDictado::VERSION) {
            return ['errores' => ["El respaldo es de una versión que esta instalación no conoce ({$version}). Actualice la plataforma."], 'avisos' => [], 'resumen' => []];
        }
        foreach (['materia', 'dictado', 'grupos', 'estudiantes', 'divisiones'] as $clave) {
            if (! is_array($r[$clave] ?? null)) {
                $e[] = "Falta la sección «{$clave}» del respaldo.";
            }
        }
        if ($e) {
            return ['errores' => $e, 'avisos' => [], 'resumen' => []];
        }

        $documentos = collect($r['estudiantes'])->pluck('documento')->map(fn ($d) => (string) $d);
        if ($documentos->duplicates()->isNotEmpty()) {
            $e[] = 'Hay documentos repetidos en la lista de estudiantes: '.$documentos->duplicates()->unique()->join(', ').'.';
        }
        $escalaMin = $this->numero($r['dictado']['escala_min'] ?? null);
        $escalaMax = $this->numero($r['dictado']['escala_max'] ?? null);
        if ($escalaMin === null || $escalaMax === null || ! $escalaMin->isLessThan($escalaMax)) {
            $e[] = 'La escala del periodo no es válida.';
        }

        $notas = 0;
        $actividades = 0;
        foreach ($r['divisiones'] as $d) {
            foreach ($d['componentes'] ?? [] as $c) {
                foreach ($c['actividades'] ?? [] as $a) {
                    $actividades++;
                    foreach ($a['notas'] ?? [] as $n) {
                        if (($n['valor'] ?? null) === null) {
                            continue;
                        }
                        $v = $this->numero($n['valor']);
                        if (! $documentos->contains((string) ($n['documento'] ?? ''))) {
                            $e[] = "Una nota de «{$a['nombre']}» es de un documento que no está en la lista ({$n['documento']}).";
                        } elseif ($v === null || ($escalaMin && $v->isLessThan($escalaMin)) || ($escalaMax && $v->isGreaterThan($escalaMax))) {
                            $e[] = "La nota {$n['valor']} de {$n['documento']} en «{$a['nombre']}» está fuera de la escala.";
                        } else {
                            $notas++;
                        }
                    }
                }
            }
        }

        $materia = $this->materiaExistente($docente, $r['materia']);
        if ($version === 1 && ! empty($r['bitacora'])) {
            $avisos[] = 'El respaldo es de la versión 1: su bitácora se guarda tal cual para consulta, pero sin enlazarla a cada actividad.';
        }
        if ($materia === null) {
            $avisos[] = "La materia «{$r['materia']['nombre']}» no existe en su cuenta: se creará.";
        }

        return ['errores' => array_slice($e, 0, 30), 'avisos' => $avisos, 'resumen' => [
            'materia' => $r['materia']['nombre'] ?? '',
            'materia_existe' => $materia !== null,
            'periodo' => (string) ($r['dictado']['periodo'] ?? ''),
            'periodo_ocupado' => $materia !== null && $materia->dictados()->where('periodo', $r['dictado']['periodo'] ?? '')->exists(),
            'generado_en' => $r['generado_en'] ?? null,
            'version' => $version,
            'grupos' => count($r['grupos']),
            'estudiantes' => count($r['estudiantes']),
            'cortes' => count($r['divisiones']),
            'actividades' => $actividades,
            'notas' => $notas,
            'bitacora' => count($r['bitacora'] ?? []),
            'segundas' => count($r['segundas_oportunidades'] ?? []),
        ]];
    }

    /** Crea el periodo restaurado. $periodo permite restaurarlo con otro nombre si ese ya existe. */
    public function restaurar(User $docente, array $r, string $periodo, string $sha256): Dictado
    {
        $revision = $this->revisar($docente, $r);
        if ($revision['errores']) {
            throw new InvalidArgumentException($revision['errores'][0]);
        }
        $periodo = trim($periodo);
        if ($periodo === '' || mb_strlen($periodo) > 20) {
            throw new InvalidArgumentException('Escriba el nombre del periodo (máximo 20 caracteres).');
        }

        return DB::transaction(function () use ($docente, $r, $periodo, $sha256, $revision) {
            $materia = $this->materiaExistente($docente, $r['materia']) ?? Materia::query()->create([
                'user_id' => $docente->id,
                'nombre' => Str::limit((string) $r['materia']['nombre'], 250, ''),
                'codigo' => filled($r['materia']['codigo'] ?? null) ? Str::limit((string) $r['materia']['codigo'], 30, '') : null,
                'nivel' => $r['materia']['nivel'] ?? 'universidad',
            ]);
            if ($materia->dictados()->where('periodo', $periodo)->exists()) {
                throw new InvalidArgumentException("La materia ya tiene el periodo {$periodo}. Restáurelo con otro nombre.");
            }

            $dc = $r['dictado'];
            $dictado = $materia->dictados()->create([
                'periodo' => $periodo,
                'escala_min' => $dc['escala_min'], 'escala_max' => $dc['escala_max'], 'nota_aprobatoria' => $dc['nota_aprobatoria'],
                'decimales' => (int) ($dc['decimales'] ?? 1), 'redondeo' => $dc['redondeo'] ?? 'cercano', 'estado' => 'abierto',
            ]);

            $estudiantes = [];
            foreach ($r['estudiantes'] as $e) {
                $estudiantes[(string) $e['documento']] = $dictado->estudiantes()->create(collect($e)->only([
                    'documento', 'apellidos', 'nombres', 'numero_matriculas', 'numero_convocatorias', 'observacion', 'email', 'telefono',
                ])->all());
            }
            $grupos = [];
            foreach ($r['grupos'] as $g) {
                $grupo = $dictado->grupos()->create(['nombre' => $g['nombre'], 'jornada' => $g['jornada'] ?? null]);
                $grupos[$g['nombre']] = $grupo;
                foreach ($g['estudiantes'] ?? [] as $m) {
                    if (isset($estudiantes[(string) $m['documento']])) {
                        $grupo->estudiantes()->attach($estudiantes[(string) $m['documento']]->id, ['activo' => (bool) ($m['activo'] ?? true)]);
                    }
                }
            }

            $motivo = 'Restaurada del respaldo'.($r['generado_en'] ?? null ? ' generado el '.Carbon::parse($r['generado_en'])->format('Y-m-d H:i') : '');
            $porClave = [];
            $porNombre = ['actividad' => [], 'corte' => []];
            foreach (array_values($r['divisiones']) as $i => $d) {
                $division = $dictado->divisiones()->create(['nombre' => $d['nombre'], 'peso' => $d['peso'], 'orden' => $i,
                    'fecha_inicio' => $d['fecha_inicio'] ?? null, 'fecha_fin' => $d['fecha_fin'] ?? null]);
                $porNombre['corte'][$d['nombre']] = $division;
                foreach (array_values($d['componentes'] ?? []) as $j => $c) {
                    $componente = $division->componentes()->create(['nombre' => $c['nombre'], 'peso' => $c['peso'], 'orden' => $j]);
                    foreach (array_values($c['actividades'] ?? []) as $k => $a) {
                        $actividad = $componente->actividades()->create([
                            'nombre' => $a['nombre'], 'fecha' => $a['fecha'] ?? null, 'orden' => $k,
                            'valor_max' => $a['valor_max'] ?? $dc['escala_max'],
                            'es_grupal' => (bool) ($a['es_grupal'] ?? false), 'tamano_grupo' => $a['tamano_grupo'] ?? null, 'tope_exceso' => $a['tope_exceso'] ?? null,
                            'grupos_por' => in_array($a['grupos_por'] ?? null, ['docente', 'estudiante'], true) ? $a['grupos_por'] : null,
                            // El examen se vuelve a enlazar solo si existe en la materia con la misma sigla
                            'artefacto_id' => filled($a['examen'] ?? null) ? Artefacto::query()->where('materia_id', $materia->id)->where('sigla', $a['examen'])->value('id') : null,
                        ]);
                        $actividad->grupos()->attach(collect($a['grupos'] ?? [])->map(fn ($n) => $grupos[$n]->id ?? null)->filter()->all());
                        foreach ((array) ($a['grupos_trabajo'] ?? []) as $doc => $numero) {
                            if (isset($estudiantes[(string) $doc]) && (int) $numero > 0) {
                                DB::table('integrantes_trabajo')->insert(['actividad_id' => $actividad->id, 'estudiante_id' => $estudiantes[(string) $doc]->id, 'numero' => (int) $numero]);
                            }
                        }
                        if (isset($a['clave'])) {
                            $porClave[$a['clave']] = $actividad->id;
                        }
                        $porNombre['actividad'][$a['nombre']] = $actividad;

                        foreach ($a['notas'] ?? [] as $n) {
                            if (($n['valor'] ?? null) === null && blank($n['comentario'] ?? null)) {
                                continue;
                            }
                            $nota = $this->registro->asignar($actividad, $estudiantes[(string) $n['documento']], $n['valor'] === null ? null : (string) $n['valor'],
                                'restauracion', $motivo, comentario: $n['comentario'] ?? null);
                            // La nota conserva su origen (digitada, archivo, calificador…); la bitácora dice que se restauró
                            $nota->update(['origen' => in_array($n['origen'] ?? null, ['digitada', 'archivo', 'calificador', 'grupal', 'migracion', 'traslado'], true) ? $n['origen'] : 'migracion']);
                        }
                    }
                }
            }

            foreach ($r['segundas_oportunidades'] ?? [] as $s) {
                $ambito = match (true) {
                    str_starts_with((string) $s['sobre'], 'actividad: ') => $porNombre['actividad'][substr($s['sobre'], 11)] ?? null,
                    str_starts_with((string) $s['sobre'], 'corte: ') => $porNombre['corte'][substr($s['sobre'], 7)] ?? null,
                    default => $dictado,
                };
                if ($ambito === null || ! isset($estudiantes[(string) $s['documento']])) {
                    continue;
                }
                SegundaOportunidad::query()->create([
                    'estudiante_id' => $estudiantes[(string) $s['documento']]->id, 'user_id' => $docente->id,
                    'ambito_type' => $ambito->getMorphClass(), 'ambito_id' => $ambito->id,
                    'tipo' => $s['tipo'], 'valor' => $s['valor'], 'regla' => $s['regla'], 'tope' => $s['tope'] ?? null,
                    'fecha' => $s['fecha'] ?? now()->toDateString(), 'motivo' => $s['motivo'] ?? null,
                    'anulada_en' => $s['anulada_en'] ?? null, 'motivo_anulacion' => $s['motivo_anulacion'] ?? null,
                ]);
            }

            // La bitácora original se guarda tal cual, con su actividad ya enlazada cuando el respaldo lo permite
            $bitacora = collect($r['bitacora'] ?? [])->map(fn ($b) => $b + ['actividad_restaurada' => $porClave[$b['actividad'] ?? null] ?? null])->all();
            Restauracion::query()->create([
                'dictado_id' => $dictado->id, 'user_id' => $docente->id, 'sha256' => $sha256, 'version' => (int) $r['version'],
                'periodo_original' => (string) $dc['periodo'], 'generado_en' => filled($r['generado_en'] ?? null) ? Carbon::parse($r['generado_en']) : null,
                'resumen' => $revision['resumen'], 'bitacora_original' => $bitacora,
            ]);

            // Un periodo cerrado se restaura cerrado, ya con sus notas
            if (($dc['estado'] ?? 'abierto') === 'cerrado') {
                $dictado->update(['estado' => 'cerrado']);
            }

            return $dictado;
        });
    }

    private function materiaExistente(User $docente, array $m): ?Materia
    {
        $q = Materia::query()->where('user_id', $docente->id);

        return filled($m['codigo'] ?? null)
            ? (clone $q)->where('codigo', $m['codigo'])->first()
            : (clone $q)->where('nombre', $m['nombre'] ?? '')->first();
    }

    private function numero(mixed $v): ?BigDecimal
    {
        try {
            return is_scalar($v) && trim((string) $v) !== '' ? BigDecimal::of((string) $v) : null;
        } catch (MathException) {
            return null;
        }
    }
}
