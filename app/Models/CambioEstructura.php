<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un guardado del editor de estructura: qué cambió, cómo estaba y cuántas notas había (2.7, 3.1.2). */
class CambioEstructura extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cambios_estructura';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cambios' => 'array', 'antes' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
