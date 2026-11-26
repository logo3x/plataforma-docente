<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Constancia de una publicación del material: formato, versión y ficha de metadatos usada. */
class Publicacion extends Model
{
    protected $table = 'publicaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ficha' => 'array'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
