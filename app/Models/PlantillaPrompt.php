<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Plantilla versionada de una de las tres capas de prompt (8). */
class PlantillaPrompt extends Model
{
    protected $table = 'plantillas_prompt';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }
}
