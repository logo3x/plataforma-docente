<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Corte, periodo, bimestre o cualquier división del periodo definida por el docente (2.7). */
class Division extends Model
{
    protected $table = 'divisiones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['peso' => 'decimal:4', 'fecha_inicio' => 'date', 'fecha_fin' => 'date'];
    }

    public function dictado(): BelongsTo
    {
        return $this->belongsTo(Dictado::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('orden');
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(Componente::class)->orderBy('orden');
    }

    /** Peso como porcentaje legible: 0.3 → "30". */
    public function porcentaje(): string
    {
        return (string) BigDecimal::of($this->peso)->multipliedBy(100)->strippedOfTrailingZeros();
    }
}
