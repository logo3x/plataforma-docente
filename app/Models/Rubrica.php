<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rúbrica de la materia: criterios con peso (porcentaje que suman 100) y, en cada criterio,
 * niveles de desempeño con el porcentaje del criterio que otorgan.
 */
class Rubrica extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criterios' => 'array'];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(Pregunta::class);
    }

    public function generacion(): BelongsTo
    {
        return $this->belongsTo(GeneracionIa::class, 'generacion_ia_id');
    }
}
