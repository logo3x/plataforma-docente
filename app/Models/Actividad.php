<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Columna del registro de notas: una actividad aplicada dentro de un componente (3.1.4). */
class Actividad extends Model
{
    protected $table = 'actividades';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'classroom_tarea' => 'array',
            'fecha' => 'date',
            'valor_max' => 'decimal:4',
            'peso_escrita' => 'decimal:4',
            'tope_exceso' => 'decimal:4',
            'es_grupal' => 'boolean',
            'devolucion_liberada_en' => 'datetime',
            'informe_muestra_original' => 'boolean',
        ];
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(Componente::class);
    }

    public function rubrica(): BelongsTo
    {
        return $this->belongsTo(Rubrica::class);
    }

    public function artefacto(): BelongsTo
    {
        return $this->belongsTo(Artefacto::class);
    }

    /** 6.2.3: la columna donde se digita la nota de la práctica entregada aparte (modalidad II). */
    public function practica(): HasOne
    {
        return $this->hasOne(self::class, 'practica_de_id');
    }

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'actividad_grupo');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class);
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class);
    }

    public function reclamos(): HasMany
    {
        return $this->hasMany(Reclamo::class);
    }
}
