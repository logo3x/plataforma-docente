<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Respuesta de una entrega a un ítem, con su puntaje y desglose (6.4). */
class Respuesta extends Model
{
    protected $table = 'respuestas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'respuesta' => 'array',
            'desglose' => 'array',
            'puntaje' => 'decimal:4',
            'puntaje_max' => 'decimal:4',
        ];
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ArtefactoPregunta::class, 'artefacto_pregunta_id');
    }
}
