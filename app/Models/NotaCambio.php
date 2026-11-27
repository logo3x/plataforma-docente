<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Entrada de la bitácora de cambios de nota. Solo inserción (3.5). */
class NotaCambio extends Model
{
    protected $table = 'nota_cambios';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor_anterior' => 'decimal:4', 'valor_nuevo' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora de notas es de solo lectura.'));
        static::deleting(fn () => throw new LogicException('La bitácora de notas es de solo lectura.'));
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    /** Quién hizo el cambio (el docente; vacío si fue un proceso automático). */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
