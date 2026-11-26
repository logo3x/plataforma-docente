<?php

namespace App\Evaluacion;

use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\ParticipanteVivo;
use App\Models\SesionVivo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sesión en vivo (sección 13, puntos 1 a 4). Con internet en la sala, el mismo examen se abre desde la
 * plataforma: se une a la sesión, reporta su avance con un latido y entrega solo al finalizar, con el mismo
 * sobre cifrado del archivo. El docente ve el grupo en vivo y puede finalizar a todos o a algunos; el que
 * no responda se registra con la última instantánea cifrada que envió, y queda constancia de quién lo decidió.
 */
final class SesionEnVivo
{
    /** Sin letras ni números que se confundan al dictarlos o proyectarlos (O/0, I/1). */
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Segundos que se espera a que un examen responda a la finalización antes de usar su instantánea. */
    public const ESPERA_CIERRE = 40;

    /** Sin latido en este tiempo, el panel lo muestra sin señal. */
    public const SIN_SENAL = 30;

    public function __construct(private readonly ProcesadorEntregas $procesador) {}

    public function abrir(Actividad $actividad, User $docente, int $banco): SesionVivo
    {
        if ($actividad->artefacto === null) {
            throw new InvalidArgumentException('La sesión en vivo es para actividades con examen digital.');
        }
        if (! in_array($banco, $actividad->artefacto->bancos(), true)) {
            throw new InvalidArgumentException("El examen no tiene banco {$banco}.");
        }

        return DB::transaction(function () use ($actividad, $docente, $banco) {
            // Una sola sesión abierta por actividad
            SesionVivo::query()->where('actividad_id', $actividad->id)->whereNull('cerrada_en')->update(['cerrada_en' => now()]);
            do {
                $codigo = collect(range(1, 8))->map(fn () => self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)])->join('');
            } while (SesionVivo::query()->where('codigo', $codigo)->exists());

            return SesionVivo::query()->create(['actividad_id' => $actividad->id, 'user_id' => $docente->id, 'banco' => $banco, 'codigo' => $codigo]);
        });
    }

    /**
     * El examen se une al abrirse (o al reabrirse: la credencial anterior deja de servir).
     *
     * @return array{participante: ParticipanteVivo, clave: string}
     */
    public function unirse(SesionVivo $sesion, string $documento, string $nombre, ?string $version, int $total): array
    {
        $documento = preg_replace('/\D/', '', $documento);
        if (strlen($documento) < 5 || mb_strlen(trim($nombre)) < 3) {
            throw new InvalidArgumentException('Escriba su documento y su nombre completo.');
        }
        $p = $sesion->participantes()->where('documento', $documento)->first();
        if ($p?->finalizado()) {
            throw new InvalidArgumentException('Este documento ya entregó el examen en esta sesión.');
        }
        if ($p === null && ! $sesion->abierta()) {
            throw new InvalidArgumentException('La sesión ya está cerrada: el docente no admite más ingresos.');
        }

        $clave = Str::random(40);
        $estudiante = Estudiante::query()->where('dictado_id', $sesion->actividad->componente->division->dictado_id)->where('documento', $documento)->first();
        $datos = ['clave_hash' => hash('sha256', $clave), 'version' => $version ? mb_substr($version, 0, 5) : null, 'total' => min($total, 500), 'ultimo_latido' => now()];
        if ($p) {
            $p->update($datos + ['ingresos' => $p->ingresos + 1]);
        } else {
            $p = $sesion->participantes()->create($datos + ['documento' => $documento, 'nombre' => mb_substr(trim($nombre), 0, 200), 'estudiante_id' => $estudiante?->id]);
        }

        return ['participante' => $p, 'clave' => $clave];
    }

    public function participante(SesionVivo $sesion, ?string $clave): ParticipanteVivo
    {
        $p = $clave ? $sesion->participantes()->where('clave_hash', hash('sha256', $clave))->first() : null;
        if ($p === null) {
            throw new InvalidArgumentException('Esta ventana ya no atiende el examen (se abrió en otro equipo o la sesión cambió).');
        }

        return $p;
    }

    /** @return array{cerrar: bool} */
    public function latido(ParticipanteVivo $p, array $d): array
    {
        $datos = ['ultimo_latido' => now(), 'respondidas' => min((int) ($d['respondidas'] ?? 0), 500), 'total' => min((int) ($d['total'] ?? $p->total), 500),
            'salidas' => min((int) ($d['salidas'] ?? 0), 10000), 'segundos_fuera' => min((int) ($d['segundos_fuera'] ?? 0), 10_000_000)];
        if (is_string($d['instantanea'] ?? null) && strlen($d['instantanea']) <= 2_000_000) {
            $datos += ['instantanea' => $d['instantanea'], 'instantanea_en' => now()];
        }
        if (! $p->finalizado()) {
            $p->update($datos);
        }

        return ['cerrar' => $p->cierre_solicitado_en !== null && ! $p->finalizado()];
    }

    /**
     * Recibe la entrega del examen (o la instantánea, si el docente lo finalizó y no respondió) y la procesa
     * como cualquier archivo: identidad, alertas y la calificación del servidor al instante.
     *
     * @return array{estado: string, mensaje: string, entrega: ?Entrega}
     */
    public function entregar(ParticipanteVivo $p, string $sobre, string $como = 'estudiante'): array
    {
        if ($p->finalizado()) {
            throw new InvalidArgumentException('Este examen ya se había entregado.');
        }
        $s = json_decode($sobre, true);
        if (! is_array($s) || preg_replace('/\D/', '', (string) ($s['documento'] ?? '')) !== $p->documento) {
            throw new InvalidArgumentException('La entrega no corresponde a este estudiante.');
        }

        $actividad = $p->sesion->actividad;
        $r = $this->procesador->recibir($actividad, $sobre, "en vivo {$p->documento}");
        if ($r['estado'] === 'duplicada') {
            $r['entrega'] = $actividad->entregas()->where('sha256', hash('sha256', $sobre))->first();
            $r['estado'] = 'procesada';
        }
        if ($r['estado'] !== 'procesada') {
            return $r;
        }

        DB::transaction(function () use ($p, $r, $como) {
            if ($como === 'estudiante' && $p->cierre_solicitado_en) {
                $como = 'docente';           // respondió a la finalización masiva
            }
            if ($como === 'estudiante' && $r['entrega']->cierre === 'automatico') {
                $como = 'tiempo';
            }
            $p->update(['finalizado_en' => now(), 'finalizado_como' => $como, 'entrega_id' => $r['entrega']->id, 'instantanea' => null]);
            if (in_array($como, ['docente', 'docente_sin_respuesta'], true)) {
                // 13.1: constancia de que se cerró así, a qué hora y por decisión de quién
                $r['entrega']->update(['cierre' => 'docente']);
                Alerta::query()->create(['actividad_id' => $r['entrega']->actividad_id, 'entrega_id' => $r['entrega']->id, 'estudiante_id' => $r['entrega']->subida_por_id,
                    'tipo' => 'cierre_docente', 'datos' => ['por' => $p->solicitante?->name, 'solicitado' => $p->cierre_solicitado_en?->toIso8601String(),
                        'sin_respuesta' => $como === 'docente_sin_respuesta', 'sesion' => $p->sesion->codigo]]);
            }
        });

        return $r;
    }

    /** Finalización masiva (13.1): a todos los que siguen abiertos, o a los elegidos. */
    public function finalizar(SesionVivo $sesion, User $docente, ?array $ids = null): int
    {
        return $sesion->participantes()->whereNull('finalizado_en')->whereNull('cierre_solicitado_en')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->update(['cierre_solicitado_en' => now(), 'cierre_solicitado_por' => $docente->id]);
    }

    /**
     * Los que no respondieron a la finalización dentro de la espera se registran con su última instantánea;
     * sin instantánea quedan como «sin datos». Lo llama el panel en cada actualización.
     */
    public function barrer(SesionVivo $sesion): int
    {
        $n = 0;
        $pendientes = $sesion->participantes()->whereNull('finalizado_en')->whereNotNull('cierre_solicitado_en')
            ->where('cierre_solicitado_en', '<=', now()->subSeconds(self::ESPERA_CIERRE))->get();
        foreach ($pendientes as $p) {
            $r = $p->instantanea ? $this->entregar($p, $p->instantanea, 'docente_sin_respuesta') : null;
            if ($r === null || $r['estado'] !== 'procesada') {
                $p->update(['finalizado_en' => now(), 'finalizado_como' => 'sin_datos']);
            }
            $n++;
        }

        return $n;
    }

    public function cerrar(SesionVivo $sesion): void
    {
        $sesion->update(['cerrada_en' => $sesion->cerrada_en ?? now()]);
    }

    public static function url(SesionVivo $sesion): string
    {
        return route('vivo.examen', $sesion->codigo);
    }
}
