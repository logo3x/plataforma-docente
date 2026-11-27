<?php

namespace App\Models;

use App\Notas\Escala;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Oferta de una materia en un periodo: lista, calendario, divisiones y notas (2.6). */
class Dictado extends Model
{
    protected $table = 'dictados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'escala_min' => 'decimal:4',
            'escala_max' => 'decimal:4',
            'nota_aprobatoria' => 'decimal:4',
            'decimales' => 'integer',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origen_dictado_id');
    }

    public function divisiones(): HasMany
    {
        return $this->hasMany(Division::class)->orderBy('orden');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
    }

    public function estudiantes(): HasMany
    {
        return $this->hasMany(Estudiante::class);
    }

    public function diasSinClase(): HasMany
    {
        return $this->hasMany(DiaSinClase::class)->orderBy('desde');
    }

    public function claveCifrado(): HasOne
    {
        return $this->hasOne(ClaveCifrado::class);
    }

    /** Escala de notas configurada para este dictado (3.2). */
    public function escala(): Escala
    {
        return new Escala(
            minimo: (string) $this->escala_min,
            maximo: (string) $this->escala_max,
            aprobatoria: (string) $this->nota_aprobatoria,
            decimales: (int) $this->decimales,
            redondeo: (string) $this->redondeo,
        );
    }
}
