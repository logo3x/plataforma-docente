<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Devolución del informe individual a un estudiante (6.9). */
class Devolucion extends Model
{
    protected $table = 'devoluciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enlace_vence_en' => 'datetime',
            'revocada_en' => 'datetime',
            'abierta_en' => 'datetime',
            'entregada_en' => 'datetime',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    /** Recibida: el estudiante abrió su enlace o el docente la marcó como entregada. */
    public function recibida(): bool
    {
        return $this->abierta_en !== null || $this->entregada_en !== null;
    }
}
