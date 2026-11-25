<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pregunta del banco, etiquetada por tema y dificultad (5.4.14). */
class Pregunta extends Model
{
    protected $table = 'preguntas';

    protected $guarded = ['id'];

    protected $hidden = ['clave'];

    protected function casts(): array
    {
        return [
            'enunciado' => 'array',
            'clave' => 'encrypted:array',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function tema(): BelongsTo
    {
        return $this->belongsTo(TemarioItem::class, 'temario_item_id');
    }
}
