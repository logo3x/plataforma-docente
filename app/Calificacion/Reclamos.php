<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Estudiante;
use App\Models\Nota;
use App\Models\Reclamo;
use App\Notas\RegistroNotas;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reclamos y su resolución (6.5.4, 6.5.6). Cuando un reclamo prospera por un error del examen
 * (enunciado ambiguo, respuesta válida no contemplada), la corrección se aplica a todo el grupo,
 * no solo al reclamante. Todo queda registrado con la corrección aplicada.
 */
final class Reclamos
{
    public function __construct(private readonly AjustesDeExamen $ajustes, private readonly RegistroNotas $registro) {}

    public function registrar(Actividad $actividad, Estudiante $e, ?int $itemId, string $descripcion): Reclamo
    {
        if ($itemId !== null && ! $actividad->artefacto?->items()->whereKey($itemId)->exists()) {
            throw new InvalidArgumentException('El ítem no es de esta actividad.');
        }

        return Reclamo::query()->create([
            'actividad_id' => $actividad->id, 'estudiante_id' => $e->id, 'artefacto_pregunta_id' => $itemId,
            'descripcion' => trim($descripcion), 'user_id' => auth()->id(),
        ]);
    }

    public function rechazar(Reclamo $r, string $resolucion): void
    {
        $this->cerrar($r, 'rechazado', null, $resolucion, []);
    }

    /**
     * @param  array{accion: string, resolucion: string, regla?: string, clave?: array, valor?: string}  $d
     */
    public function aceptar(Reclamo $r, array $d): void
    {
        $this->abierto($r);

        DB::transaction(function () use ($r, $d) {
            $detalle = match ($d['accion']) {
                'anulacion' => ['regla' => $d['regla'], 'notas_recalculadas' => $this->ajustes->anular($this->item($r), $d['regla'], $d['resolucion'])],
                'correccion_clave' => $this->corregir($r, $d),
                'ajuste_nota' => $this->ajustar($r, $d),
                default => throw new InvalidArgumentException('Acción desconocida.'),
            };
            $this->cerrar($r, 'aceptado', $d['accion'], $d['resolucion'], $detalle);
        });
    }

    private function corregir(Reclamo $r, array $d): array
    {
        $res = $this->ajustes->corregirClave($this->item($r), $d['clave'], $d['resolucion']);

        return ['clave_anterior' => $res['anterior'], 'clave_nueva' => $d['clave'],
            'respuestas_recalificadas' => $res['recalificadas'], 'notas_recalculadas' => $res['notas']];
    }

    private function ajustar(Reclamo $r, array $d): array
    {
        $anterior = Nota::query()->where(['actividad_id' => $r->actividad_id, 'estudiante_id' => $r->estudiante_id])->value('valor');
        $this->registro->asignar($r->actividad, $r->estudiante, (string) $d['valor'], 'reclamo',
            "Reclamo #{$r->id} aceptado: {$d['resolucion']}", ajusteIndividual: true);

        return ['nota_anterior' => $anterior, 'nota_nueva' => (string) $d['valor']];
    }

    private function cerrar(Reclamo $r, string $estado, ?string $accion, string $resolucion, array $detalle): void
    {
        $this->abierto($r);
        $r->update(['estado' => $estado, 'accion' => $accion, 'resolucion' => trim($resolucion), 'detalle' => $detalle,
            'atendido_en' => now(), 'user_id' => auth()->id() ?? $r->user_id]);
    }

    private function abierto(Reclamo $r): void
    {
        if ($r->estado !== 'abierto') {
            throw new InvalidArgumentException('Este reclamo ya fue atendido.');
        }
    }

    private function item(Reclamo $r)
    {
        return $r->item ?? throw new InvalidArgumentException('Para anular o corregir la clave, el reclamo debe indicar el ítem.');
    }
}
