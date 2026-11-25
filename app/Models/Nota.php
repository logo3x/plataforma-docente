<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota de un estudiante en una actividad. valor = null significa celda vacía (3.1.6).
 * No se modifica directamente: use App\Notas\RegistroNotas para que quede en la bitácora (3.5).
 */
class Nota extends Model
{
    protected $table = 'notas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:4', 'ajuste_individual' => 'boolean'];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(NotaCambio::class)->orderBy('id');
    }
}
