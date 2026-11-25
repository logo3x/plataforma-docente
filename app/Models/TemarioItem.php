<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Unidad, tema o resultado de aprendizaje del temario (7.1). */
class TemarioItem extends Model
{
    protected $table = 'temario_items';

    protected $guarded = ['id'];

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('orden');
    }
}
