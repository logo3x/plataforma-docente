<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Estudiante de un dictado; el documento es único dentro del dictado (9.1). */
class Estudiante extends Model
{
    protected $table = 'estudiantes';

    protected $guarded = ['id'];

    /** 3.4.10: contacto fuera de las vistas proyectables. */
    protected $hidden = ['email', 'telefono', 'observacion'];

    protected function casts(): array
    {
        return ['revisar_nombre' => 'boolean'];
    }

    public function dictado(): BelongsTo
    {
        return $this->belongsTo(Dictado::class);
    }

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'grupo_estudiante')->withPivot('activo', 'doble_revisado_en');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    public function nombreCompleto(): string
    {
        return "{$this->apellidos}, {$this->nombres}";
    }
}
