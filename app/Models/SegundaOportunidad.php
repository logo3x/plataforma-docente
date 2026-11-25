<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Supletorio, habilitación o recuperación (3.6). Es un evento adicional: nunca borra la nota
 * original. No se edita ni se borra; si se registró por error, se anula con motivo.
 */
class SegundaOportunidad extends Model
{
    public const TIPOS = ['supletorio' => 'Supletorio', 'recuperacion' => 'Recuperación', 'habilitacion' => 'Habilitación'];

    public const REGLAS = [
        'reemplaza' => 'La nueva nota reemplaza a la anterior',
        'promedia' => 'Se promedia con la anterior',
        'tope' => 'Reemplaza, pero sin superar el tope',
    ];

    protected $table = 'segundas_oportunidades';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:4', 'tope' => 'decimal:4', 'fecha' => 'date', 'anulada_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Solo se permite marcar la anulación; los datos del evento no cambian (3.5.2)
        static::updating(function (self $s) {
            $permitidos = ['anulada_en', 'motivo_anulacion', 'updated_at'];
            if (array_diff(array_keys($s->getDirty()), $permitidos) !== []) {
                throw new LogicException('Una segunda oportunidad registrada no se edita: anúlela y registre otra.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una segunda oportunidad no se borra: se anula con motivo.'));
    }

    public function scopeVigentes(Builder $q): void
    {
        $q->whereNull('anulada_en');
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ambito(): MorphTo
    {
        return $this->morphTo();
    }

    public function letra(): string
    {
        return ['supletorio' => 'S', 'recuperacion' => 'R', 'habilitacion' => 'H'][$this->tipo];
    }
}
