<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Observación de aula y su destino: lección aprendida, requerimiento o descartada (anexo B). */
class EntradaBitacora extends Model
{
    protected $table = 'bitacora_aula';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'para_ia' => 'boolean'];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }
}
