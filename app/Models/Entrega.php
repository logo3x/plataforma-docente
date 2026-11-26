<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Archivo entregado por un estudiante o un grupo de trabajo (6.1, 6.8). */
class Entrega extends Model
{
    protected $table = 'entregas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cifrada' => 'boolean',
            'puntaje' => 'decimal:4',
            'desglose' => 'array',
            'calificada_en' => 'datetime',
            'sesion' => 'array',
            'procesada_en' => 'datetime',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function subidaPor(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class, 'subida_por_id');
    }

    /** Integrantes: el grupo viaja con la entrega (6.8). */
    public function integrantes(): BelongsToMany
    {
        return $this->belongsToMany(Estudiante::class, 'entrega_estudiante');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(Respuesta::class);
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class);
    }
}
