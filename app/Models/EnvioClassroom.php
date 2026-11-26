<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Constancia de una nota enviada a Classroom: cuál, cuántos puntos, cómo y quién. */
class EnvioClassroom extends Model
{
    protected $table = 'classroom_envios';

    protected $guarded = ['id'];

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }
}
