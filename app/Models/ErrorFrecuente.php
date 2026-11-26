<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada del catálogo de errores frecuentes: un mensaje del motor, su explicación y qué revisar (4.4.4). */
class ErrorFrecuente extends Model
{
    protected $table = 'errores_frecuentes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['es_regex' => 'boolean', 'activo' => 'boolean', 'base' => 'boolean'];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }
}
