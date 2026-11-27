<?php

namespace App\Calificacion;

use App\Evaluacion\ImportadorBanco;
use App\Models\Actividad;
use App\Models\ArtefactoPregunta;
use App\Models\Respuesta;
use App\Notas\Calculo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cambios sobre un examen ya aplicado que afectan a todo el grupo (6.5.4, 6.5.5): anular un ítem
 * o corregir su clave. Siempre se reprocesan TODAS las actividades que aplicaron el examen, no
 * solo la del reclamante, y las notas que ya estaban en el registro se actualizan con su motivo.
 */
final class AjustesDeExamen
{
    /** Tipos cuya clave se puede corregir desde la interfaz. */
    public const CORREGIBLES = ['unica', 'vf', 'multiple', 'completar', 'emparejar', 'ordenar', 'reto'];

    public function __construct(
        private readonly PasoAlRegistro $paso,
        private readonly CalificadorDeterminista $determinista,
    ) {}

    /** @return int notas del registro que cambiaron */
    public function anular(ArtefactoPregunta $item, string $regla, string $motivo): int
    {
        $item->update(['anulada_en' => now(), 'regla_anulacion' => $regla, 'motivo_anulacion' => $motivo]);

        return $this->reprocesar($item, "Anulación del ítem {$this->numero($item)}: {$motivo}.");
    }

    public function restaurar(ArtefactoPregunta $item): int
    {
        $motivo = $item->motivo_anulacion;
        $item->update(['anulada_en' => null, 'regla_anulacion' => null, 'motivo_anulacion' => null]);

        return $this->reprocesar($item, "Se revierte la anulación del ítem {$this->numero($item)} ({$motivo}).");
    }

    /**
     * Corrige la clave de un ítem (por ejemplo, admite otra respuesta válida que no estaba
     * contemplada) y vuelve a calificar ese ítem en todas las entregas con lo que cada estudiante
     * respondió. La clave anterior queda en la traza del reclamo.
     *
     * @param  array<string, mixed>  $nueva  clave completa nueva
     * @return array{recalificadas: int, notas: int, anterior: array<string, mixed>}
     */
    public function corregirClave(ArtefactoPregunta $item, array $nueva, string $motivo): array
    {
        $pregunta = $item->pregunta;
        if (! in_array($pregunta->tipo, self::CORREGIBLES, true)) {
            throw new InvalidArgumentException('La clave de este tipo de ítem no se corrige desde aquí; anúlelo o ajuste la nota.');
        }
        $this->validarClave($pregunta->tipo, $nueva, $pregunta->enunciado);
        $anterior = $pregunta->clave;

        $recalificadas = DB::transaction(function () use ($pregunta, $nueva, $item) {
            $pregunta->update(['clave' => array_merge($pregunta->clave, $nueva)]);
            $n = 0;
            $clave = $pregunta->fresh()->clave;
            foreach (Respuesta::query()->where('artefacto_pregunta_id', $item->id)->get() as $r) {
                if (! CalificadorDeterminista::loCalificaElServidor($pregunta->tipo, $clave)) {
                    // Un reto que se ejecuta en el navegador del docente: queda pendiente para volver a ejecutarlo
                    $r->update(['puntaje' => null, 'desglose' => null, 'calificado_por' => null]);
                    $n++;

                    continue;
                }
                $res = $this->determinista->calificar($pregunta->tipo, $clave, $r->respuesta['valor'] ?? null, $item->peso);
                $r->update(['puntaje' => Calculo::paraGuardar($res->puntaje), 'desglose' => $res->desglose, 'calificado_por' => 'servidor']);
                $n++;
            }

            return $n;
        });

        return [
            'recalificadas' => $recalificadas,
            'notas' => $this->reprocesar($item, "Corrección de la clave del ítem {$this->numero($item)}: {$motivo}."),
            'anterior' => $anterior,
        ];
    }

    public function numero(ArtefactoPregunta $item): int
    {
        return ArtefactoPregunta::query()->where('artefacto_id', $item->artefacto_id)->where('orden', '<', $item->orden)->count() + 1;
    }

    private function reprocesar(ArtefactoPregunta $item, string $motivo): int
    {
        return Actividad::query()->where('artefacto_id', $item->artefacto_id)->get()
            ->sum(fn (Actividad $a) => $this->paso->ejecutar($a, $motivo, soloYaRegistradas: true)['cambiadas']);
    }

    private function validarClave(string $tipo, array $c, array $enunciado): void
    {
        $opciones = array_map('strval', array_keys($enunciado['opciones'] ?? []));
        $ok = match ($tipo) {
            'unica' => in_array((string) ($c['correcta'] ?? ''), $opciones, true),
            'vf' => is_bool($c['correcta'] ?? null),
            'multiple' => ($c['correctas'] ?? []) !== [] && array_diff(array_map('strval', $c['correctas']), $opciones) === [],
            'completar' => count($c['huecos'] ?? []) === (int) ($enunciado['huecos'] ?? 0)
                && collect($c['huecos'])->every(fn ($h) => collect((array) $h)->filter(fn ($x) => trim((string) $x) !== '')->isNotEmpty()),
            'emparejar' => array_map('strval', array_keys($c['pares'] ?? [])) == array_map('strval', array_keys($enunciado['izquierda'] ?? []))
                && array_diff(array_map('strval', $c['pares'] ?? []), array_map('strval', array_keys($enunciado['derecha'] ?? []))) === [],
            'ordenar' => count($c['orden'] ?? []) === count($enunciado['pasos'] ?? [])
                && array_diff(array_map('strval', array_keys($enunciado['pasos'] ?? [])), array_map('strval', $c['orden'] ?? [])) === [],
            // Las verificaciones de un reto se validan como en la importación del banco
            'reto' => app(ImportadorBanco::class)->validar(['formato' => 'plataforma-banco', 'version' => 1, 'sigla' => 'X1', 'titulo' => 'x', 'valor' => '1',
                'items' => [['tipo' => 'reto', 'peso' => '1', 'enunciado' => 'x', 'lenguaje' => $enunciado['lenguaje'] ?? 'javascript', 'esquema' => $enunciado['esquema'] ?? null,
                    'archivos' => $enunciado['archivos'] ?? null,
                    'verificaciones' => $c['verificaciones'] ?? []]]]) === [],
            default => false,
        };
        if (! $ok) {
            throw new InvalidArgumentException('La clave corregida no es válida para este ítem.');
        }
    }
}
