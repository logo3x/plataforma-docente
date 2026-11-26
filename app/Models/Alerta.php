<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alerta informativa, nunca sancionatoria (6.3). */
class Alerta extends Model
{
    protected $table = 'alertas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'revisada_en' => 'datetime', 'informar' => 'boolean'];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }
}
