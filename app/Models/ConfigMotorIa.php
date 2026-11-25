<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Configuración de un motor de IA local u online (1.5). */
class ConfigMotorIa extends Model
{
    protected $table = 'motores_ia';

    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'es_local' => 'boolean',
            'predeterminado' => 'boolean',
            'api_key' => 'encrypted',
        ];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
