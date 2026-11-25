<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Componente con peso dentro de una división, p. ej. "Trabajos y quices" o "Parcial" (3.1.3). */
class Componente extends Model
{
    protected $table = 'componentes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['peso' => 'decimal:4'];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(Actividad::class)->orderBy('orden');
    }
}
