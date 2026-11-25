<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Trazabilidad de cada llamada al motor: plantillas, material de contexto y respuesta (7.7, 8.4.6). */
class GeneracionIa extends Model
{
    protected $table = 'generaciones_ia';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plantillas' => 'array',
            'contenidos_contexto' => 'array',
            'contiene_datos_estudiantes' => 'boolean',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function motor(): BelongsTo
    {
        return $this->belongsTo(ConfigMotorIa::class, 'motor_ia_id');
    }

    public function resultado(): MorphTo
    {
        return $this->morphTo();
    }
}
