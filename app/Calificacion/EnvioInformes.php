<?php

namespace App\Calificacion;

use App\Jobs\EnviarEnlaceInforme;
use App\Mail\EnlaceInforme;
use App\Models\Actividad;
use App\Models\Devolucion;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envío del enlace personal al informe por correo institucional (6.9.3) y su registro por
 * estudiante (6.9.5): a qué dirección se envió y cuándo, o por qué falló. Un fallo con un
 * estudiante no detiene a los demás.
 */
final class EnvioInformes
{
    /** No se reenvía a la misma persona antes de esto, salvo que el docente insista uno por uno. */
    public const ESPERA_MINUTOS = 5;

    public function __construct(private readonly InformeEstudiante $informe) {}

    public function enlace(Devolucion $d): string
    {
        return URL::temporarySignedRoute('informe.publico', $d->enlace_vence_en, ['devolucion' => $d->id]);
    }

    /** Por qué no se le puede enviar a este estudiante, o null si sí se puede. */
    public function impedimento(Devolucion $d, bool $presento): ?string
    {
        $correo = trim((string) $d->estudiante->email);
        $dominio = config('plataforma.correo_dominio');

        return match (true) {
            $d->revocada_en !== null => 'La devolución fue retirada.',
            ! $presento => 'No presentó.',
            $correo === '' => 'No tiene correo en la lista.',
            ! filter_var($correo, FILTER_VALIDATE_EMAIL) => "El correo «{$correo}» no es válido.",
            filled($dominio) && ! Str::endsWith(Str::lower($correo), '@'.Str::lower(ltrim($dominio, '@'))) => "«{$correo}» no es del dominio institucional ({$dominio}).",
            default => null,
        };
    }

    /**
     * Envía a todos los que se puede. Con $soloPendientes, omite a quienes ya lo abrieron, ya lo
     * recibieron por otro canal o ya tienen un envío exitoso.
     *
     * @return array{enviados: int, en_cola: int, errores: int, sin_enviar: int, omitidos: int}
     */
    public function enviarATodos(Actividad $actividad, User $docente, ?string $mensaje, bool $soloPendientes = true): array
    {
        $r = ['enviados' => 0, 'en_cola' => 0, 'errores' => 0, 'sin_enviar' => 0, 'omitidos' => 0];
        $devoluciones = $actividad->devoluciones()->whereNull('revocada_en')->with('estudiante')->get();

        foreach ($devoluciones as $d) {
            if ($this->impedimento($d, $this->informe->presento($actividad, $d->estudiante))) {
                $r['sin_enviar']++;

                continue;
            }
            $yaEnviado = $d->correo_enviado_en !== null && $d->correo_enviado_en->gt(now()->subMinutes(self::ESPERA_MINUTOS));
            if ($yaEnviado || ($soloPendientes && ($d->recibida() || $d->correo_enviado_en !== null || $d->correo_en_cola_en !== null))) {
                $r['omitidos']++;

                continue;
            }
            $r[$this->enviar($d, $docente, $mensaje)]++;
        }

        return $r;
    }

    /** @return string 'enviados' | 'en_cola' | 'errores' */
    public function enviar(Devolucion $d, User $docente, ?string $mensaje): string
    {
        if (config('plataforma.correo_en_cola')) {
            $d->update(['correo_en_cola_en' => now(), 'correo_error' => null]);
            EnviarEnlaceInforme::dispatch($d->id, $docente->id, $mensaje);

            return 'en_cola';
        }

        return $this->enviarAhora($d, $docente, $mensaje) ? 'enviados' : 'errores';
    }

    public function enviarAhora(Devolucion $d, User $docente, ?string $mensaje): bool
    {
        $d->loadMissing('estudiante', 'actividad.componente.division.dictado.materia');
        $correo = trim((string) $d->estudiante->email);
        try {
            Mail::to($correo, $d->estudiante->nombreCompleto())->send(new EnlaceInforme($d, $docente, $this->enlace($d), filled($mensaje) ? trim($mensaje) : null));
        } catch (Throwable $e) {
            $d->update(['correo_destino' => $correo, 'correo_en_cola_en' => null, 'correo_error' => Str::limit($e->getMessage(), 280)]);

            return false;
        }
        $d->update(['correo_destino' => $correo, 'correo_en_cola_en' => null, 'correo_enviado_en' => now(), 'correo_error' => null, 'correos' => $d->correos + 1]);

        return true;
    }

    /** El correo de la plataforma aún no sale a ninguna parte (se queda en el registro). */
    public static function sinConfigurar(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true);
    }
}
