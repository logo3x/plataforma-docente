<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Comentario de un clic para errores que se repiten al calificar abiertas (6.7.13). */
class ComentarioFrecuente extends Model
{
    protected $table = 'comentarios_frecuentes';

    protected $guarded = ['id'];
}
