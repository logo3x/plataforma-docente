<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Par de claves RSA-OAEP del dictado; la privada se guarda cifrada (revisión A2). */
class ClaveCifrado extends Model
{
    protected $table = 'claves_cifrado';

    protected $guarded = ['id'];

    protected $hidden = ['privada_jwk'];

    protected function casts(): array
    {
        return ['privada_jwk' => 'encrypted', 'rotada_en' => 'datetime'];
    }

    public function dictado(): BelongsTo
    {
        return $this->belongsTo(Dictado::class);
    }
}
