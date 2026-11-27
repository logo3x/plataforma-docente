<?php

namespace App\Historial;

use App\Evaluacion\EditorBanco;
use App\Models\Artefacto;
use App\Models\Material;
use App\Models\Rubrica;
use App\Models\UnidadDidactica;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Historial de versiones de lo generado (7.6): material de clase, rúbricas, unidades didácticas y bancos de
 * examen. Antes de cada cambio se guarda cómo estaba; restaurar una versión es otro cambio (y deja su propia foto),
 * así que nunca se pierde nada.
 */
final class Versiones
{
    public function __construct(private readonly EditorBanco $bancos) {}

    /** Guarda cómo está ahora, antes de cambiarlo. */
    public function guardar(Model $m): Version
    {
        return Version::query()->create([
            'versionable_type' => $m->getMorphClass(), 'versionable_id' => $m->getKey(),
            'numero' => (int) Version::query()->where(['versionable_type' => $m->getMorphClass(), 'versionable_id' => $m->getKey()])->max('numero') + 1,
            'titulo' => (string) $m->titulo, 'datos' => $this->foto($m), 'user_id' => auth()->id(),
        ]);
    }

    /** @return Collection<int, Version> de la más reciente a la más antigua */
    public function de(Model $m): Collection
    {
        return Version::query()->where(['versionable_type' => $m->getMorphClass(), 'versionable_id' => $m->getKey()])->orderByDesc('numero')->get();
    }

    /** Vuelve a una versión; lo que había queda como versión nueva. */
    public function restaurar(Version $v): Model
    {
        $m = $v->versionable ?? throw new InvalidArgumentException('Lo que tenía esta versión ya no existe.');

        return DB::transaction(function () use ($m, $v) {
            $this->guardar($m);
            $this->aplicar($m, $v->datos);

            return $m;
        });
    }

    private function aplicar(Model $m, array $d): void
    {
        match (true) {
            $m instanceof Material, $m instanceof UnidadDidactica => $m->update(['titulo' => $d['titulo'], 'contenido' => $d['contenido']]),
            $m instanceof Rubrica => $m->update(['titulo' => $d['titulo'], 'proposito' => $d['proposito'] ?? null, 'criterios' => $d['criterios']]),
            // Un banco con entregas no se toca: EditorBanco lo impide y lo explica
            $m instanceof Artefacto => $this->bancos->guardar($m, $this->conIdsVigentes($m, $d['banco'])),
            default => throw new InvalidArgumentException('Esto no tiene versiones.'),
        };
    }

    private function foto(Model $m): array
    {
        return match (true) {
            $m instanceof Material, $m instanceof UnidadDidactica => ['titulo' => $m->titulo, 'contenido' => $m->contenido],
            $m instanceof Rubrica => ['titulo' => $m->titulo, 'proposito' => $m->proposito, 'criterios' => $m->criterios],
            $m instanceof Artefacto => ['titulo' => $m->titulo, 'banco' => $this->bancos->exportar($m, true)],
            default => throw new InvalidArgumentException('Esto no tiene versiones.'),
        };
    }

    /** Los ítems que siguen existiendo conservan su identidad; los demás se crean de nuevo. */
    private function conIdsVigentes(Artefacto $a, array $banco): array
    {
        $vigentes = $a->items()->pluck('id')->all();
        $banco['items'] = array_map(fn ($it) => in_array($it[EditorBanco::ID] ?? null, $vigentes, true) ? $it : array_diff_key($it, [EditorBanco::ID => true]), $banco['items']);

        return $banco;
    }
}
