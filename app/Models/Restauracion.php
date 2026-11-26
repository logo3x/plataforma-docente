<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Periodo restaurado desde un respaldo, con la bitácora original tal como venía (3.1.10). */
class Restauracion extends Model
{
    protected $table = 'restauraciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['generado_en' => 'datetime', 'resumen' => 'array', 'bitacora_original' => 'array'];
    }

    public function dictado(): BelongsTo
    {
        return $this->belongsTo(Dictado::class);
    }
}
