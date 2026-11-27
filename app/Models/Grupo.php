<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grupo extends Model
{
    protected $table = 'grupos';

    protected $guarded = ['id'];

    public function dictado(): BelongsTo
    {
        return $this->belongsTo(Dictado::class);
    }

    public function estudiantes(): BelongsToMany
    {
        return $this->belongsToMany(Estudiante::class, 'grupo_estudiante')->withPivot('activo', 'doble_revisado_en');
    }

    /** Horario semanal del grupo (2.6.2). */
    public function horarios(): HasMany
    {
        return $this->hasMany(Horario::class)->orderBy('dia')->orderBy('hora_inicio');
    }
}
