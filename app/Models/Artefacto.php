<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Actividad generada (quiz, parcial, taller...), guardada y reutilizable (7.3). */
class Artefacto extends Model
{
    use HasUuids;

    protected $table = 'artefactos';

    protected $guarded = ['id'];

    /** El solucionario nunca sale en una respuesta JSON ni en el artefacto del estudiante (9.1). */
    protected $hidden = ['solucionario', 'especificacion_calificacion'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'configuracion' => 'array',
            'especificacion_calificacion' => 'array',
            'solucionario' => 'encrypted:array',
            'ensayado_en' => 'datetime',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArtefactoPregunta::class)->orderBy('orden');
    }

    /** Versiones presentes, en orden: ['A'], ['A', 'B'], ['A', 'B', '2A', '2B']... (5.4.2). */
    public function versiones(): array
    {
        return array_values(array_intersect(['A', 'B', '2A', '2B'], $this->items()->distinct()->pluck('banco')->all()));
    }

    /** Bancos del examen: 1, y 2 si tiene un segundo banco para otra jornada. */
    public function bancos(): array
    {
        return array_values(array_unique(array_map(fn ($v) => str_starts_with($v, '2') ? 2 : 1, $this->versiones())));
    }

    /** @return array<int, string> versiones de un banco: ['A', 'B'] o ['2A', '2B'] */
    public function versionesDelBanco(int $banco): array
    {
        return array_values(array_filter($this->versiones(), fn ($v) => (str_starts_with($v, '2') ? 2 : 1) === $banco));
    }

    /**
     * Versión que le toca a un documento dentro de un banco: la misma cuenta que hace el examen
     * en el navegador (FNV-1a de documento + sigla), para poder comprobarla al recibir la entrega.
     */
    public static function versionPara(string $documento, string $sigla, array $versiones): ?string
    {
        if ($versiones === []) {
            return null;
        }
        $h = 2166136261;
        foreach (mb_str_split($documento.'|'.$sigla) as $c) {
            $h ^= mb_ord($c);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }

        // Bits altos, como en el navegador: los bajos de FNV-1a casi no mezclan
        return $versiones[($h >> 16) % count($versiones)];
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(Actividad::class);
    }
}
