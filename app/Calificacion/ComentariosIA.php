<?php

namespace App\Calificacion;

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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Borradores de comentario para respuestas abiertas, en la voz del docente (8.5). La IA recibe la
 * pregunta, la referencia, la rúbrica, el puntaje que ya puso el docente y la respuesta, sin el
 * nombre del estudiante. Lo que propone queda como BORRADOR: el estudiante solo ve lo que el
 * docente decide usar (principio 1.2).
 */
final class ComentariosIA
{
    /** Respuestas por llamada: un modelo local responde mejor con lotes pequeños. */
    public const LOTE = 12;

    public function __construct(private readonly ConstructorPrompt $prompts, private readonly GestorIA $ia) {}

    /** Las que admiten borrador: ya calificadas, con texto, sin comentario ni borrador. */
    public function candidatas(ArtefactoPregunta $item, Actividad $actividad): Collection
    {
        return Respuesta::query()->where('artefacto_pregunta_id', $item->id)
            ->whereHas('entrega', fn ($q) => $q->where('actividad_id', $actividad->id))
            ->whereNotNull('puntaje')->whereNull('comentario')->whereNull('comentario_borrador')->get()
            ->filter(fn ($r) => is_string($r->respuesta['valor'] ?? null) && trim($r->respuesta['valor']) !== '')->values();
    }

    /** @return int cuántos borradores quedaron */
    public function proponer(User $docente, Actividad $actividad, ArtefactoPregunta $item, ?ConfigMotorIa $motor = null): int
    {
        $candidatas = $this->candidatas($item, $actividad)->take(self::LOTE);
        if ($candidatas->isEmpty()) {
            throw new InvalidArgumentException('No hay respuestas calificadas sin comentario en esta pregunta.');
        }
        @set_time_limit(config('ia.tiempo_maximo'));

        $pregunta = $item->pregunta->loadMissing('rubrica');
        $dictado = $actividad->componente->division->dictado;
        $lista = $candidatas->map(function (Respuesta $r) use ($item) {
            $niveles = collect($r->desglose['rubrica']['criterios'] ?? [])->map(fn ($c) => "{$c['nombre']}: ".($c['nivel'] ?? 'sin evaluar'))->join('; ');

            return "[{$r->id}] Puntaje: ".BigDecimal::of($r->puntaje)->strippedOfTrailingZeros().' de '.BigDecimal::of($item->peso)->strippedOfTrailingZeros()
                .($niveles ? " · Niveles: {$niveles}" : '')."\nRespuesta: «".Str::limit(trim($r->respuesta['valor']), 2500).'»';
        })->join("\n\n");

        $p = $this->prompts->construir($docente, $actividad->componente->division->dictado->materia, $dictado, 'comentarios', [
            'pregunta' => $pregunta->enunciado['texto'] ?? '',
            'referencia' => $pregunta->clave['referencia'] ?? '(sin respuesta de referencia)',
            'rubrica' => $pregunta->rubrica
                ? collect($pregunta->rubrica->criterios)->map(fn ($c) => "- {$c['nombre']}: ".collect($c['niveles'])->map(fn ($n) => "{$n['nombre']} ({$n['descripcion']})")->join('; '))->join("\n")
                : ($pregunta->clave['rubrica'] ?? '(sin rúbrica)'),
            'respuestas' => $lista,
        ], collect());

        $respuesta = $this->ia->generar($docente, new SolicitudIA($p['sistema'], $p['usuario'], contieneDatosEstudiantes: true, soloJson: true, maxTokens: 6000), $motor, [
            'plantillas' => $p['plantillas'], 'materia_id' => $dictado->materia_id, 'tarea' => 'comentarios',
        ]);
        $this->ia->ultima->update(['estado' => 'aprobado']);

        $datos = ExtractorJson::extraer($respuesta->texto);
        $n = 0;
        foreach ((array) ($datos['comentarios'] ?? []) as $c) {
            $r = $candidatas->firstWhere('id', (int) ($c['id'] ?? 0));     // solo las que se enviaron
            $texto = is_string($c['comentario'] ?? null) ? trim($c['comentario']) : '';
            if ($r && $texto !== '') {
                $r->update(['comentario_borrador' => Str::limit($texto, 1000)]);
                $n++;
            }
        }

        return $n;
    }

    /** 8.5.1: un comentario de docente no menciona cifras internas ni conteos de la calificación. */
    public static function delataCalculo(string $texto): bool
    {
        return (bool) preg_match('/\d+(?:[.,]\d+)?\s*(?:%|\/\s*\d|puntos?\b|de\s+\d)|\bpuntaje\b|\bcriterio\s+\d/iu', $texto);
    }
}
