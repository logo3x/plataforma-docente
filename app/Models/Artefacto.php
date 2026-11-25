<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Actividad generada (quiz, parcial, taller...), guardada y reutilizable (7.3). */
class Artefacto extends Model
{
    use HasUuids;

    protected $table = 'artefactos';

    protected $guarded = ['id'];

    /** El solucionario nunca sale en una respuesta JSON ni en el artefacto del estudiante (9.1). */
    protected $hidden = ['solucionario', 'especificacion_calificacion'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'configuracion' => 'array',
            'especificacion_calificacion' => 'array',
            'solucionario' => 'encrypted:array',
            'ensayado_en' => 'datetime',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArtefactoPregunta::class)->orderBy('orden');
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(Actividad::class);
    }
}
