<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archivo del repositorio de la materia, usable como contexto de IA (7). */
class Contenido extends Model
{
    protected $table = 'contenidos';

    protected $guarded = ['id'];

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function temarioItem(): BelongsTo
    {
        return $this->belongsTo(TemarioItem::class);
    }
}
