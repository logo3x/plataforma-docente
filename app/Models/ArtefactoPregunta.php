<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ítem de un artefacto: pregunta, banco/versión, peso y anulación (5.4, 6.5.5). */
class ArtefactoPregunta extends Model
{
    protected $table = 'artefacto_pregunta';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['peso' => 'decimal:4', 'anulada_en' => 'datetime'];
    }

    public function artefacto(): BelongsTo
    {
        return $this->belongsTo(Artefacto::class);
    }

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(Pregunta::class);
    }
}
