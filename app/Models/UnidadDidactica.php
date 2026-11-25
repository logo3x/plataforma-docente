<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Secuencia didáctica de una unidad del temario (7.4). */
class UnidadDidactica extends Model
{
    protected $table = 'unidades_didacticas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['contenido' => 'array'];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(TemarioItem::class, 'temario_item_id');
    }

    public function generacion(): BelongsTo
    {
        return $this->belongsTo(GeneracionIa::class, 'generacion_ia_id');
    }
}
