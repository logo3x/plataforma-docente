<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Reclamo de un estudiante sobre una calificación y su resolución (6.5.6). */
class Reclamo extends Model
{
    public const ACCIONES = [
        'anulacion' => 'Se anuló el ítem para todo el grupo',
        'correccion_clave' => 'Se corrigió la clave y se recalificó a todo el grupo',
        'ajuste_nota' => 'Se ajustó la nota del estudiante',
    ];

    protected $table = 'reclamos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detalle' => 'array', 'atendido_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Un reclamo atendido es parte de la traza: no se reabre, no se edita, no se borra
        static::updating(function (self $r) {
            if ($r->getOriginal('estado') !== 'abierto') {
                throw new LogicException('Un reclamo atendido no se modifica.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un reclamo no se borra: queda en el registro.'));
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ArtefactoPregunta::class, 'artefacto_pregunta_id');
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
