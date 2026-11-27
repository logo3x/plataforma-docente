<?php

namespace App\Evaluacion;

use App\Calificacion\CalificadorDeterminista;
use App\Calificacion\RevisionEstatica;
use App\Models\Actividad;
use App\Models\Ensayo;
use App\Models\User;
use Brick\Math\BigDecimal;
use RuntimeException;

/**
 * Cierra el ciclo del ensayo (5.6, 9.2): recibe la entrega del examen de ensayo, la descifra con la
 * clave del periodo, la califica sin guardar ninguna nota y registra el ensayo con su fecha y equipo.
 */
final class EnsayoExamen
{
    public function __construct(
        private readonly ClavesDictado $claves,
        private readonly CalificadorDeterminista $determinista,
    ) {}

    /** @return array{ensayo: Ensayo, items: array<int, array<string, mixed>>, sesion: array<string, mixed>} */
    public function procesar(Actividad $actividad, User $docente, string $contenido): array
    {
        $sobre = json_decode($contenido, true);
        if (! is_array($sobre) || ! ProcesadorEntregas::esFormatoEntrega($sobre['formato'] ?? null)) {
            throw new RuntimeException('El archivo no es una entrega de examen.');
        }
        if (empty($sobre['ensayo'])) {
            throw new RuntimeException('Esta es una entrega real de un estudiante, no de ensayo. Súbala en el calificador.');
        }
        $artefacto = $actividad->artefacto()->with('items.pregunta')->firstOrFail();
        if (($sobre['artefacto'] ?? null) !== $artefacto->uuid) {
            throw new RuntimeException("La entrega es del examen {$sobre['sigla']}, no de esta actividad.");
        }

        $cifrada = is_array($sobre['cifrado'] ?? null);
        $carga = json_decode($cifrada ? $this->claves->descifrar($actividad->componente->division->dictado, $sobre['cifrado']) : json_encode($sobre['datos_planos'] ?? null), true);
        if (! is_array($carga)) {
            throw new RuntimeException('La entrega no trae respuestas legibles.');
        }

        $sesion = $carga['sesion'] ?? [];
        $verificaciones = $sesion['ensayo']['verificaciones'] ?? [];
        $items = [];
        $puntaje = BigDecimal::zero();
        // Solo los ítems de la versión que respondió (5.4.2)
        $version = in_array($carga['version'] ?? null, $artefacto->versiones(), true) ? $carga['version'] : null;
        foreach ($artefacto->items->when($version, fn ($c) => $c->where('banco', $version)) as $it) {
            $tipo = $it->pregunta->tipo;
            $respuesta = $carga['respuestas'][(string) $it->id] ?? null;
            $fila = ['numero' => $it->orden + 1, 'tipo' => $tipo, 'enunciado' => $it->pregunta->enunciado['texto'] ?? '', 'peso' => (string) $it->peso, 'respuesta' => $respuesta];
            if (CalificadorDeterminista::loCalificaElServidor($tipo, $it->pregunta->clave)) {
                $r = $this->determinista->calificar($tipo, $it->pregunta->clave, $respuesta, (string) $it->peso);
                $fila['puntaje'] = $r->puntaje;
                if ($tipo === 'reto') {
                    $fila['codigo'] = RevisionEstatica::texto($respuesta);
                    $fila['estatica'] = $r->desglose['verificaciones'];
                }
                $puntaje = $puntaje->plus($r->puntaje);
            } elseif ($tipo === 'reto') {
                // Se ejecutan en el navegador del docente, igual que en el calificador (calificación híbrida)
                $fila['codigo'] = is_string($respuesta) ? $respuesta : '';
                $fila['lenguaje'] = $it->pregunta->enunciado['lenguaje'] ?? 'javascript';
                $fila['esquema'] = $it->pregunta->enunciado['esquema'] ?? null;
                $fila['verificaciones'] = array_map(fn ($v) => ImportadorBanco::criterio($v) + ['reservada' => (bool) ($v['reservada'] ?? false)],
                    $it->pregunta->clave['verificaciones'] ?? []);
            }
            $items[] = $fila;
        }

        $salidas = $sesion['salidas'] ?? [];
        $ensayo = Ensayo::query()->create([
            'actividad_id' => $actividad->id, 'artefacto_id' => $artefacto->id, 'user_id' => $docente->id,
            'equipo' => self::equipo((string) ($sesion['ensayo']['navegador'] ?? $sesion['navegador'] ?? ''), (string) ($sesion['ensayo']['pantalla'] ?? '')),
            'cifrada' => $cifrada,
            'equipo_listo' => $cifrada && $verificaciones !== [] && collect($verificaciones)->every(fn ($v) => ! empty($v['ok'])),
            'verificaciones' => $verificaciones,
            'salidas' => count($salidas), 'segundos_fuera' => (int) array_sum(array_column($salidas, 'segundos')),
            'ejecuciones' => (int) ($sesion['ejecuciones'] ?? 0),
            'puntaje_servidor' => (string) $puntaje,
        ]);
        $artefacto->update(['ensayado_en' => $ensayo->created_at, 'ensayado_equipo' => $ensayo->equipo]);

        return ['ensayo' => $ensayo, 'items' => $items, 'sesion' => $sesion + ['salidas' => $salidas], 'version' => count($artefacto->versiones()) > 1 ? $version : null];
    }

    /** «Chrome 131 · Windows · 1366×768», a partir del agente del navegador. */
    public static function equipo(string $ua, string $pantalla): string
    {
        $navegador = match (true) {
            (bool) preg_match('~Edg/(\d+)~', $ua, $m) => 'Edge '.$m[1],
            (bool) preg_match('~Firefox/(\d+)~', $ua, $m) => 'Firefox '.$m[1],
            (bool) preg_match('~Chrome/(\d+)~', $ua, $m) => 'Chrome '.$m[1],
            (bool) preg_match('~Version/(\d+).*Safari~', $ua, $m) => 'Safari '.$m[1],
            default => 'Navegador desconocido',
        };
        $sistema = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'sistema desconocido',
        };

        return mb_substr(implode(' · ', array_filter([$navegador, $sistema, $pantalla])), 0, 250);
    }
}
