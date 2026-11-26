<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Conclusión del docente sobre un par de entregas parecidas (6.11.4). */
class RevisionSimilitud extends Model
{
    protected $table = 'revisiones_similitud';

    protected $guarded = ['id'];

    public const CONCLUSIONES = ['sin_copia' => 'No es copia', 'posible_copia' => 'Posible copia: hablar con los estudiantes', 'copia' => 'Copia confirmada'];
}
