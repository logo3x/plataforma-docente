<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Supletorio, habilitación o recuperación; nunca borra la nota original (3.6). */
class SegundaOportunidad extends Model
{
    protected $table = 'segundas_oportunidades';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:4', 'tope' => 'decimal:4', 'fecha' => 'date'];
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function ambito(): MorphTo
    {
        return $this->morphTo();
    }
}
