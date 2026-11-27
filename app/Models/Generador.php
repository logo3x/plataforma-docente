<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Generador propio del docente (7.4.3): instrucciones con {{campo}} y salida en documento por secciones. */
class Generador extends Model
{
    protected $table = 'generadores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['campos' => 'array', 'funciono' => 'boolean'];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
