<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un estudiante en una sesión en vivo: su avance, sus salidas y cómo terminó. */
class ParticipanteVivo extends Model
{
    protected $table = 'participantes_vivo';

    protected $guarded = ['id'];

    protected $hidden = ['clave_hash', 'instantanea'];

    protected function casts(): array
    {
        return ['ultimo_latido' => 'datetime', 'instantanea_en' => 'datetime', 'cierre_solicitado_en' => 'datetime', 'finalizado_en' => 'datetime'];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionVivo::class, 'sesion_vivo_id');
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cierre_solicitado_por');
    }

    public function finalizado(): bool
    {
        return $this->finalizado_en !== null;
    }
}
