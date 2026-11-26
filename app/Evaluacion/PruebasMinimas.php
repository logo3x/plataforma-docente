<?php

namespace App\Evaluacion;

use App\Calificacion\CalificadorDeterminista;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pruebas mínimas antes de aplicar una actividad (9.2), las que se pueden hacer sin navegador.
 * Las de ejecución de código (9.2.8, 9.2.9) las hace el examen de ensayo en el equipo de la sala.
 * Cada prueba dice qué comprobó, si pasó y, si falló, dónde.
 */
final class PruebasMinimas
{
    /** Claves que nunca deben viajar en el archivo del estudiante (5.4.5, 9.2.7). */
    private const CLAVES_PROHIBIDAS = ['correcta', 'correctas', 'pares', 'orden', 'verificaciones', 'prueba', 'fraccion', 'referencia', 'rubrica', 'justificacion', 'solucion', 'solucionario'];

    public function __construct(
        private readonly CalificadorDeterminista $determinista,
        private readonly GeneradorExamen $generador,
        private readonly ClavesDictado $claves,
    ) {}

    /** @return array<int, array{numero: int, nombre: string, estado: string, detalle: string}> estado: ok | falla | navegador */
    public function ejecutar(Actividad $actividad): array
    {
        $artefacto = $actividad->artefacto()->with('items.pregunta')->firstOrFail();
        $items = $artefacto->items->whereNull('anulada_en')->values();
        $valor = BigDecimal::of((string) ($artefacto->configuracion['valor'] ?? $actividad->valor_max));
        $r = [];

        // 1. Respuestas perfectas de cada versión dan el valor total exacto
        foreach ($items->groupBy('banco') as $banco => $delBanco) {
            $total = $this->total($delBanco, fn ($it) => $this->perfecta($it));
            $r[] = $this->resultado(1, "Respuestas perfectas de la versión {$banco} dan el valor total", $total->isEqualTo($valor),
                "Suman {$this->n($total)} de {$this->n($valor)}.".($items->contains(fn ($i) => in_array($i->pregunta->tipo, ['abierta', 'reto'], true))
                    ? ' Las abiertas y los retos se cuentan con puntaje completo: los califican el docente y el navegador.' : ''));
        }

        // 2. En blanco da cero
        $blanco = $this->total($items, fn () => null, abiertasCompletas: false);
        $r[] = $this->resultado(2, 'El examen en blanco da cero', $blanco->isZero(), "Da {$this->n($blanco)}.");

        // 3. Un caso intermedio conocido: correcta la primera mitad de los ítems calificables en el servidor
        $servidor = $items->filter(fn ($i) => in_array($i->pregunta->tipo, CalificadorDeterminista::TIPOS_SERVIDOR, true))->values();
        $mitad = $servidor->take((int) ceil($servidor->count() / 2))->pluck('id')->all();
        $esperado = $servidor->whereIn('id', $mitad)->reduce(fn ($s, $i) => $s->plus((string) $i->peso), BigDecimal::zero());
        $obtenido = $this->total($servidor, fn ($it) => in_array($it->id, $mitad, true) ? $this->perfecta($it) : null);
        $r[] = $servidor->isEmpty()
            ? $this->resultado(3, 'Un caso intermedio da la fracción esperada', true, 'No aplica: no hay ítems que califique el servidor.')
            : $this->resultado(3, 'Un caso intermedio da la fracción esperada', $obtenido->isEqualTo($esperado),
                'Con '.count($mitad).' de '.$servidor->count()." ítems correctos: esperado {$this->n($esperado)}, obtenido {$this->n($obtenido)}.");

        // 4. Los pesos suman el valor
        foreach ($items->groupBy('banco') as $banco => $delBanco) {
            $suma = $delBanco->reduce(fn ($s, $i) => $s->plus((string) $i->peso), BigDecimal::zero());
            $r[] = $this->resultado(4, "Los pesos de la versión {$banco} suman el valor de la actividad", $suma->isEqualTo($valor), "Suman {$this->n($suma)} de {$this->n($valor)}.");
        }

        // 5. Las respuestas del solucionario existen dentro de cada pregunta
        $malos = $items->map(fn ($it) => $this->claveInconsistente($it))->filter()->values();
        $r[] = $this->resultado(5, 'Cada respuesta correcta existe entre las opciones de su pregunta', $malos->isEmpty(),
            $malos->isEmpty() ? 'Revisadas '.$items->count().' preguntas.' : $malos->join(' '));

        // 6. Cifrado y descifrado de ida y vuelta con la clave del periodo
        $dictado = $actividad->componente->division->dictado;
        try {
            $muestra = json_encode(['prueba' => 'ida y vuelta', 'ñ' => 'áéíóú', 't' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE);
            $ok = $this->claves->descifrar($dictado, $this->claves->cifrar($dictado, $muestra)) === $muestra;
            $r[] = $this->resultado(6, 'Cifrado y descifrado con la clave del periodo', $ok, $ok ? 'El calificador recupera exactamente lo cifrado.' : 'Lo descifrado no coincide.');
        } catch (Throwable $e) {
            $r[] = $this->resultado(6, 'Cifrado y descifrado con la clave del periodo', false, $e->getMessage());
        }

        // 7. El archivo del estudiante no lleva respuestas, verificaciones ni la clave privada
        $r[] = $this->archivoLimpio($actividad, $items);

        // 8 y 9: necesitan ejecutar código en un navegador real
        $r[] = ['numero' => 8, 'nombre' => 'Los retos de código se califican con sus verificaciones', 'estado' => 'navegador',
            'detalle' => 'Se comprueba con el examen de ensayo: responda los retos y suba la entrega aquí.'];
        $r[] = ['numero' => 9, 'nombre' => 'El entorno de prueba reporta sus cinco estados', 'estado' => 'navegador',
            'detalle' => 'El examen de ensayo lo verifica solo al iniciar, en el equipo de la sala.'];

        // 10. Cada ítem produce su detalle autoexplicativo (6.4)
        $sinDetalle = $servidor->filter(fn ($it) => $this->determinista->calificar($it->pregunta->tipo, $it->pregunta->clave, $this->perfecta($it), (string) $it->peso)->desglose === [])
            ->map(fn ($it) => $it->orden + 1);
        $r[] = $this->resultado(10, 'Cada ítem produce su detalle para el informe', $sinDetalle->isEmpty(),
            $sinDetalle->isEmpty() ? 'Todos los ítems calificables explican su resultado.' : 'Sin detalle: ítem(s) '.$sinDetalle->join(', ').'.');

        return $r;
    }

    /** @param  array<int, array{estado: string}>  $resultados */
    public static function aprobadas(array $resultados): bool
    {
        return collect($resultados)->every(fn ($x) => $x['estado'] !== 'falla');
    }

    /** La respuesta perfecta de un ítem, en el formato en que la envía el examen. */
    public function perfecta(ArtefactoPregunta $it): mixed
    {
        $c = $it->pregunta->clave;

        return match ($it->pregunta->tipo) {
            'unica', 'vf' => $c['correcta'],
            'multiple' => array_values($c['correctas']),
            'emparejar' => $c['pares'],
            'ordenar' => array_values($c['orden']),
            'completar' => array_map(fn ($h) => is_array($h) ? (string) reset($h) : (string) $h, $c['huecos']),
            default => null,
        };
    }

    private function total(Collection $items, callable $respuesta, bool $abiertasCompletas = true): BigDecimal
    {
        return $items->reduce(function (BigDecimal $s, ArtefactoPregunta $it) use ($respuesta, $abiertasCompletas) {
            if (! in_array($it->pregunta->tipo, CalificadorDeterminista::TIPOS_SERVIDOR, true)) {
                return $abiertasCompletas ? $s->plus((string) $it->peso) : $s;
            }

            return $s->plus($this->determinista->calificar($it->pregunta->tipo, $it->pregunta->clave, $respuesta($it), (string) $it->peso)->puntaje);
        }, BigDecimal::zero());
    }

    private function claveInconsistente(ArtefactoPregunta $it): ?string
    {
        $e = $it->pregunta->enunciado;
        $c = $it->pregunta->clave;
        $n = 'Ítem '.($it->orden + 1).':';
        $opciones = array_map('strval', array_keys($e['opciones'] ?? []));

        return match ($it->pregunta->tipo) {
            'unica' => in_array((string) $c['correcta'], $opciones, true) ? null : "{$n} la respuesta «{$c['correcta']}» no es una de sus opciones.",
            'multiple' => array_diff(array_map('strval', $c['correctas']), $opciones) ? "{$n} marca como correctas opciones que no existen." : null,
            'emparejar' => array_diff(array_map('strval', array_keys($c['pares'])), array_map('strval', array_keys($e['izquierda'] ?? [])))
                || array_diff(array_map('strval', $c['pares']), array_map('strval', array_keys($e['derecha'] ?? []))) ? "{$n} empareja elementos que no existen." : null,
            'ordenar' => collect($c['orden'])->map(fn ($x) => (string) $x)->sort()->values()->all() !== collect(array_keys($e['pasos'] ?? []))->map(fn ($x) => (string) $x)->sort()->values()->all()
                ? "{$n} el orden correcto no usa exactamente los pasos del enunciado." : null,
            'completar' => count($c['huecos']) !== (int) ($e['huecos'] ?? -1) ? "{$n} tiene ".count($c['huecos'])." respuestas para {$e['huecos']} huecos." : null,
            default => null,
        };
    }

    private function archivoLimpio(Actividad $actividad, Collection $items): array
    {
        $html = $this->generador->html($actividad);
        preg_match('~<script type="application/json" id="datos-examen">(.*?)</script>~s', $html, $m);
        $datos = json_decode($m[1] ?? 'null', true);
        if (! is_array($datos)) {
            return $this->resultado(7, 'El archivo del estudiante no contiene las respuestas', false, 'No se pudieron leer los datos del examen generado.');
        }

        $hallazgos = [];
        $recorrer = function ($v, string $ruta) use (&$recorrer, &$hallazgos) {
            if (! is_array($v)) {
                return;
            }
            foreach ($v as $k => $x) {
                if (is_string($k) && in_array($k, self::CLAVES_PROHIBIDAS, true)) {
                    $hallazgos[] = "aparece «{$k}» en {$ruta}";
                }
                $recorrer($x, "{$ruta}.{$k}");
            }
        };
        $recorrer($datos, 'examen');

        // Textos que solo conoce el docente: pruebas de las verificaciones, justificaciones, referencias y la clave privada
        $texto = html_entity_decode($html);
        $secretos = $items->flatMap(fn ($it) => [
            ...array_column($it->pregunta->clave['verificaciones'] ?? [], 'prueba'),
            $it->pregunta->justificacion, $it->pregunta->clave['referencia'] ?? null,
        ])->filter(fn ($s) => is_string($s) && mb_strlen($s) >= 12);
        foreach ($secretos as $s) {
            if (str_contains($texto, $s)) {
                $hallazgos[] = 'aparece un texto de la clave: «'.mb_substr($s, 0, 40).'…»';
            }
        }
        $privada = $this->claves->de($actividad->componente->division->dictado)->privada_jwk;
        $nucleo = substr(preg_replace('/-----[^-]+-----|\s+/', '', $privada), 40, 60);
        if (str_contains($texto, 'PRIVATE KEY') || str_contains(preg_replace('/\s+/', '', $texto), $nucleo)) {
            $hallazgos[] = 'aparece la clave privada del periodo';
        }

        return $this->resultado(7, 'El archivo del estudiante no contiene respuestas, verificaciones ni la clave privada', $hallazgos === [],
            $hallazgos === [] ? 'Revisado el examen generado: '.number_format(strlen($html) / 1024, 0, ',', '.').' KB, '.count($datos['items'] ?? []).' ítems.' : ucfirst(implode('; ', array_slice($hallazgos, 0, 5))).'.');
    }

    private function resultado(int $numero, string $nombre, bool $ok, string $detalle): array
    {
        return ['numero' => $numero, 'nombre' => $nombre, 'estado' => $ok ? 'ok' : 'falla', 'detalle' => $detalle];
    }

    private function n(BigDecimal $v): string
    {
        return (string) $v->strippedOfTrailingZeros();
    }
}
