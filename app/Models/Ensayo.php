<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un ensayo de la actividad en un equipo de la sala (5.6.4). */
class Ensayo extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verificaciones' => 'array', 'cifrada' => 'boolean', 'equipo_listo' => 'boolean'];
    }
}
