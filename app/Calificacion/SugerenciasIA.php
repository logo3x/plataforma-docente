<?php

namespace App\Calificacion;

use App\Evaluacion\Rubricas;
use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\ConfigMotorIa;
use App\Models\Respuesta;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Puntaje sugerido por IA para respuestas abiertas (decisión pendiente 9, principio 1.2): la IA propone,
 * el docente decide. La sugerencia queda aparte del puntaje —la respuesta sigue sin calificar— hasta que el
 * docente la acepta, la cambia o la descarta; nunca llega al estudiante. Con rúbrica, la IA elige niveles y
 * el puntaje lo calcula la plataforma. Solo con motor local: son respuestas de estudiantes (1.5).
 *
 * La sugerencia guarda la decisión del docente (aceptada, cambiada y por cuánto, descartada) para que vea
 * qué tan confiable resulta la IA en cada pregunta antes de apoyarse en ella.
 */
final class SugerenciasIA
{
    public const LOTE = 10;

    public function __construct(private readonly ConstructorPrompt $prompts, private readonly GestorIA $ia, private readonly Rubricas $rubricas) {}

    /** Sin puntaje, con texto y sin sugerencia. */
    public function candidatas(ArtefactoPregunta $item, Actividad $actividad): Collection
    {
        return Respuesta::query()->where('artefacto_pregunta_id', $item->id)
            ->whereHas('entrega', fn ($q) => $q->where('actividad_id', $actividad->id))
            ->whereNull('puntaje')->whereNull('sugerencia')->get()
            ->filter(fn ($r) => is_string($r->respuesta['valor'] ?? null) && trim($r->respuesta['valor']) !== '')->values();
    }

    /** @return int cuántas sugerencias quedaron */
    public function proponer(User $docente, Actividad $actividad, ArtefactoPregunta $item, ?ConfigMotorIa $motor = null): int
    {
        $candidatas = $this->candidatas($item, $actividad)->take(self::LOTE);
        if ($candidatas->isEmpty()) {
            throw new InvalidArgumentException('No hay respuestas sin calificar ni sugerencia en esta pregunta.');
        }
        @set_time_limit(config('ia.tiempo_maximo'));

        $pregunta = $item->pregunta->loadMissing('rubrica');
        $rubrica = $pregunta->rubrica;
        $dictado = $actividad->componente->division->dictado;
        $maximo = (string) BigDecimal::of($item->peso)->strippedOfTrailingZeros();

        $p = $this->prompts->construir($docente, $dictado->materia, $dictado, 'puntajes', [
            'pregunta' => $pregunta->enunciado['texto'] ?? '',
            'referencia' => $pregunta->clave['referencia'] ?? '(sin respuesta de referencia)',
            'maximo' => $maximo,
            'rubrica' => $rubrica
                ? collect($rubrica->criterios)->map(fn ($c, $i) => 'Criterio '.($i + 1)." — {$c['nombre']} ({$c['peso']} %): "
                    .collect($c['niveles'])->map(fn ($n, $j) => "[{$j}] {$n['nombre']}: {$n['descripcion']}")->join('; '))->join("\n")
                : ($pregunta->clave['rubrica'] ?? '(sin rúbrica: use la respuesta de referencia)'),
            'respuestas' => $candidatas->map(fn (Respuesta $r) => "[{$r->id}] «".Str::limit(trim($r->respuesta['valor']), 2500).'»')->join("\n\n"),
        ], collect());

        $respuesta = $this->ia->generar($docente, new SolicitudIA($p['sistema'], $p['usuario'], contieneDatosEstudiantes: true, soloJson: true, maxTokens: 6000), $motor, [
            'plantillas' => $p['plantillas'], 'materia_id' => $dictado->materia_id, 'tarea' => 'puntajes',
        ]);
        $this->ia->ultima->update(['estado' => 'aprobado']);
        $motorUsado = ConfigMotorIa::query()->find($this->ia->ultima->motor_ia_id)?->modelo;
        $generacion = $this->ia->ultima->id;

        $n = 0;
        foreach ((array) (ExtractorJson::extraer($respuesta->texto)['sugerencias'] ?? []) as $s) {
            $r = $candidatas->firstWhere('id', (int) ($s['id'] ?? 0));       // solo las que se enviaron
            if ($r === null || ($v = $this->validar($s, $rubrica?->criterios, $maximo)) === null) {
                continue;
            }
            $r->update(['sugerencia' => $v + [
                'justificacion' => Str::limit(trim((string) ($s['justificacion'] ?? '')), 600),
                'rubrica_id' => $rubrica?->id, 'modelo' => $motorUsado, 'generacion_id' => $generacion, 'en' => now()->toIso8601String(), 'decision' => null,
            ]]);
            $n++;
        }

        return $n;
    }

    /**
     * Con rúbrica, los niveles deben existir y el puntaje sale de la rúbrica (el número de la IA no cuenta).
     * Sin rúbrica, el puntaje debe estar entre 0 y el máximo. Lo que no cumple se descarta.
     *
     * @return array{puntaje: string, seleccion: ?array}|null
     */
    public function validar(array $s, ?array $criterios, string $maximo): ?array
    {
        if ($criterios) {
            $sel = array_values((array) ($s['niveles'] ?? []));
            if (count($sel) !== count($criterios)) {
                return null;
            }
            foreach ($criterios as $i => $c) {
                if (! is_numeric($sel[$i]) || ! isset($c['niveles'][(int) $sel[$i]])) {
                    return null;
                }
                $sel[$i] = (int) $sel[$i];
            }

            return ['puntaje' => $this->rubricas->puntaje($criterios, $sel, $maximo), 'seleccion' => $sel];
        }
        try {
            $v = BigDecimal::of(str_replace(',', '.', (string) ($s['puntaje'] ?? '')));
        } catch (MathException) {
            return null;
        }
        if ($v->isNegative() || $v->isGreaterThan($maximo)) {
            return null;
        }

        return ['puntaje' => (string) $v->toScale(4, RoundingMode::HalfUp), 'seleccion' => null];
    }

    /** Qué hizo el docente con la sugerencia: aceptada tal cual, o cambiada (con su puntaje final). */
    public static function registrarDecision(Respuesta $r, ?string $final): void
    {
        $s = $r->sugerencia;
        if (! is_array($s) || ($s['decision'] ?? null) === 'descartada') {
            return;
        }
        if ($final === null) {
            $s['decision'] = null;
            unset($s['final']);
        } else {
            $s['decision'] = BigDecimal::of($final)->isEqualTo($s['puntaje']) ? 'aceptada' : 'cambiada';
            $s['final'] = $final;
        }
        $r->update(['sugerencia' => $s]);
    }

    /** Calibración de la pregunta: cuántas se aceptaron tal cual, cuántas se cambiaron y por cuánto en promedio. */
    public static function calibracion(Collection $respuestas): array
    {
        $decididas = $respuestas->filter(fn ($r) => in_array($r->sugerencia['decision'] ?? null, ['aceptada', 'cambiada'], true));
        $cambiadas = $decididas->where('sugerencia.decision', 'cambiada');

        return [
            'sugeridas' => $respuestas->filter(fn ($r) => is_array($r->sugerencia))->count(),
            'pendientes' => $respuestas->filter(fn ($r) => is_array($r->sugerencia) && ($r->sugerencia['decision'] ?? null) === null && $r->puntaje === null)->count(),
            'aceptadas' => $decididas->count() - $cambiadas->count(),
            'cambiadas' => $cambiadas->count(),
            'descartadas' => $respuestas->where('sugerencia.decision', 'descartada')->count(),
            'diferencia' => $cambiadas->isEmpty() ? null
                : round($cambiadas->avg(fn ($r) => abs((float) $r->sugerencia['final'] - (float) $r->sugerencia['puntaje'])), 2),
        ];
    }
}
