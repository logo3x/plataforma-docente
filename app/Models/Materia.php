<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Plantilla estable de una asignatura (2.6). */
class Materia extends Model
{
    use SoftDeletes;

    protected $table = 'materias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'perfil_codigo' => 'boolean',
            'plantilla_evaluacion' => 'array',
            'estilo' => 'array',
        ];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function temario(): HasMany
    {
        return $this->hasMany(TemarioItem::class)->orderBy('orden');
    }

    public function contenidos(): HasMany
    {
        return $this->hasMany(Contenido::class);
    }

    public function dictados(): HasMany
    {
        return $this->hasMany(Dictado::class);
    }

    public function artefactos(): HasMany
    {
        return $this->hasMany(Artefacto::class);
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(Pregunta::class);
    }

    public function rubricas(): HasMany
    {
        return $this->hasMany(Rubrica::class);
    }

    public function unidadesDidacticas(): HasMany
    {
        return $this->hasMany(UnidadDidactica::class);
    }

    public function materiales(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /** La práctica de código corre en el navegador (JavaScript): se puede verificar el código de los ejemplos. */
    public function codigoVerificable(): bool
    {
        return $this->perfil_codigo && $this->modalidad_practica === 'integrada';
    }
}
