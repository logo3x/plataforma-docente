<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Días del periodo sin clase, además de los festivos: receso, semana institucional, paro (2.6.2). */
class DiaSinClase extends Model
{
    protected $table = 'dias_sin_clase';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date'];
    }
}
