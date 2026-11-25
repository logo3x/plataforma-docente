<?php

namespace App\Livewire;

use App\Calificacion\DesempenoTemas;
use App\Models\Grupo;
use App\Models\TemarioItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lista de chequeo del temario por grupo (7.1.4, 7.1.5): el avance es propio de cada dictado y grupo,
 * y lo marcado como visto es lo que la IA puede evaluar.
 */
class ListaChequeo extends Component
{
    #[Locked]
    public int $grupoId;

    public function mount(Grupo $grupo): void
    {
        Gate::authorize('gestionar', $grupo);
        $this->grupoId = $grupo->id;
    }

    public function alternar(int $temaId): void
    {
        $grupo = Grupo::query()->with('dictado')->findOrFail($this->grupoId);
        $tema = TemarioItem::query()->where('materia_id', $grupo->dictado->materia_id)->where('tipo', 'tema')->findOrFail($temaId);
        $clave = ['dictado_id' => $grupo->dictado_id, 'grupo_id' => $grupo->id, 'temario_item_id' => $tema->id];

        if (DB::table('avances_temario')->where($clave)->exists()) {
            DB::table('avances_temario')->where($clave)->delete();
        } else {
            DB::table('avances_temario')->insert($clave + ['cubierto_en' => now()->toDateString()]);
        }
    }

    public function render(): View
    {
        $grupo = Grupo::query()->with('dictado.materia')->findOrFail($this->grupoId);
        $unidades = TemarioItem::query()->where('materia_id', $grupo->dictado->materia_id)->whereNull('parent_id')->orderBy('orden')
            ->with(['hijos' => fn ($q) => $q->where('tipo', 'tema')->orderBy('orden')])->get();
        $vistos = DB::table('avances_temario')->where('grupo_id', $grupo->id)->pluck('cubierto_en', 'temario_item_id')->all();
        $total = $unidades->sum(fn ($u) => $u->hijos->count());

        return view('livewire.lista-chequeo', [
            'grupo' => $grupo, 'unidades' => $unidades, 'vistos' => $vistos,
            'desempeno' => app(DesempenoTemas::class)->deGrupo($grupo),
            'umbral' => DesempenoTemas::UMBRAL,
            'total' => $total, 'hechos' => count(array_intersect_key($vistos, $unidades->flatMap->hijos->keyBy('id')->all())),
        ]);
    }
}
