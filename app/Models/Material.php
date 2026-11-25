<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material de clase generado o escrito por el docente (7.4): ejemplos prácticos, entre otros. */
class Material extends Model
{
    protected $table = 'materiales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['contenido' => 'array'];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function generacion(): BelongsTo
    {
        return $this->belongsTo(GeneracionIa::class, 'generacion_ia_id');
    }
}
