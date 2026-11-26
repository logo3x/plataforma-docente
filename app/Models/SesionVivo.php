<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Aplicación de una actividad con internet: los exámenes reportan y entregan al servidor (sección 13). */
class SesionVivo extends Model
{
    protected $table = 'sesiones_vivo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cerrada_en' => 'datetime'];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(ParticipanteVivo::class);
    }

    public function abierta(): bool
    {
        return $this->cerrada_en === null;
    }
}
