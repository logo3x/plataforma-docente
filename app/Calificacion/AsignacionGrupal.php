<?php

namespace App\Calificacion;

use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\Nota;
use App\Notas\RegistroNotas;
use Brick\Math\BigDecimal;

/**
 * Entrega grupal (6.8): se califica una vez y la nota baja a todos los integrantes,
 * sin buscar a nadie por nombre. Aplica el tope por tamaño de grupo (6.8.7.1) y alerta
 * en lugar de calificar dos veces a quien aparece en dos entregas (6.8.8).
 */
final class AsignacionGrupal
{
    public function __construct(private readonly RegistroNotas $registro) {}

    /**
     * @return string operación de la bitácora (deshacible)
     */
    /** Notas con ajuste individual que se dejaron como estaban, desde que se creó el servicio. */
    public int $conservadas = 0;

    public function asignar(Entrega $entrega, string $nota, ?string $comentario = null, ?string $motivoExtra = null): string
    {
        $actividad = $entrega->actividad;
        $integrantes = $entrega->integrantes()->get();
        $tamano = $integrantes->count();

        $topeAplicado = null;
        if ($actividad->tamano_grupo !== null && $actividad->tope_exceso !== null
            && $tamano > $actividad->tamano_grupo
            && BigDecimal::of($nota)->isGreaterThan($actividad->tope_exceso)) {
            $topeAplicado = $actividad->tope_exceso;
            $nota = $actividad->tope_exceso;
        }

        $motivo = $topeAplicado === null
            ? null
            : 'Tope de '.$actividad->componente->division->dictado->escala()->mostrar($topeAplicado)
                .": grupo de {$tamano} integrantes en actividad para {$actividad->tamano_grupo}.";
        if ($motivoExtra !== null) {
            $motivo = trim($motivoExtra.' '.($motivo ?? ''));
        }

        return $this->registro->operacion(function () use ($entrega, $actividad, $integrantes, $nota, $tamano, $motivo, $comentario) {
            foreach ($integrantes as $estudiante) {
                $otra = Entrega::query()
                    ->where('actividad_id', $actividad->id)
                    ->whereKeyNot($entrega->id)
                    ->whereHas('integrantes', fn ($q) => $q->whereKey($estudiante->id))
                    ->first();

                // 6.8.7: la nota ajustada a un solo integrante (reclamo aceptado o corrección a mano) se conserva
                if (Nota::query()->where('actividad_id', $actividad->id)->where('estudiante_id', $estudiante->id)->where('ajuste_individual', true)->exists()) {
                    $this->conservadas++;

                    continue;
                }

                if ($otra !== null) {
                    Alerta::query()->firstOrCreate([
                        'actividad_id' => $actividad->id,
                        'estudiante_id' => $estudiante->id,
                        'tipo' => 'estudiante_en_dos_entregas',
                    ], ['entrega_id' => $entrega->id, 'datos' => ['entregas' => [$otra->id, $entrega->id]]]);

                    continue;
                }

                $this->registro->asignar(
                    $actividad,
                    $estudiante,
                    $nota,
                    $tamano > 1 ? 'grupal' : 'calificador',
                    $motivo,
                    $entrega->id,
                    comentario: $comentario,
                );
            }
        });
    }
}
