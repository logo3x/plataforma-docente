<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un ensayo de la actividad en un equipo de la sala (5.6.4). */
class Ensayo extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verificaciones' => 'array', 'cifrada' => 'boolean', 'equipo_listo' => 'boolean'];
    }

    public function artefacto(): BelongsTo
    {
        return $this->belongsTo(Artefacto::class);
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }
}
