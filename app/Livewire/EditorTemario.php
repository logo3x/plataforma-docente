<?php

namespace App\Livewire;

use App\Models\Materia;
use App\Models\TemarioItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Temario editable de la materia: lo extraído por la IA o armado a mano (7.1.2, 7.1.7). */
class EditorTemario extends Component
{
    #[Locked]
    public int $materiaId;

    public string $nuevaUnidad = '';

    /** @var array<int, string> */
    public array $nuevoTema = [];

    /** @var array<int, string> */
    public array $nuevoResultado = [];

    public ?string $mensaje = null;

    public function mount(Materia $materia): void
    {
        Gate::authorize('gestionar', $materia);
        $this->materiaId = $materia->id;
    }

    public function agregarUnidad(): void
    {
        if (trim($this->nuevaUnidad) === '') {
            return;
        }
        $this->crear(null, 'unidad', $this->nuevaUnidad);
        $this->nuevaUnidad = '';
    }

    public function agregarHijo(int $unidadId, string $tipo): void
    {
        $unidad = $this->item($unidadId);
        $campo = $tipo === 'tema' ? 'nuevoTema' : 'nuevoResultado';
        $titulo = trim($this->{$campo}[$unidadId] ?? '');
        if ($titulo === '' || $unidad->tipo !== 'unidad' || ! in_array($tipo, ['tema', 'resultado'], true)) {
            return;
        }
        $this->crear($unidad->id, $tipo, $titulo);
        $this->{$campo}[$unidadId] = '';
    }

    public function renombrar(int $id, string $titulo): void
    {
        if (trim($titulo) !== '') {
            $this->item($id)->update(['titulo' => Str::limit(trim($titulo), 250, ''), 'origen' => 'manual']);
        }
    }

    public function describir(int $id, string $texto): void
    {
        $this->item($id)->update(['descripcion' => trim($texto) ?: null]);
    }

    public function mover(int $id, int $dir): void
    {
        $i = $this->item($id);
        $vecino = TemarioItem::query()->where('materia_id', $this->materiaId)->where('parent_id', $i->parent_id)
            ->where('orden', $dir < 0 ? '<' : '>', $i->orden)->orderBy('orden', $dir < 0 ? 'desc' : 'asc')->first();
        if ($vecino) {
            [$i->orden, $vecino->orden] = [$vecino->orden, $i->orden];
            $i->save();
            $vecino->save();
        }
    }

    public function eliminar(int $id): void
    {
        $i = $this->item($id);
        $ids = $i->hijos()->pluck('id')->push($i->id);
        $marcados = DB::table('avances_temario')->whereIn('temario_item_id', $ids)->count();
        $i->delete();
        $this->mensaje = "«{$i->titulo}» eliminado".($marcados ? " (tenía {$marcados} marca(s) de avance en la lista de chequeo)." : '.');
    }

    /** 7.1.3: el docente toma la sugerencia (pasa a la descripción) o la descarta. */
    public function aceptarSugerencia(int $id): void
    {
        $i = $this->item($id);
        $i->update(['descripcion' => trim(($i->descripcion ? $i->descripcion."\n" : '').$i->sugerencia), 'sugerencia' => null]);
    }

    public function descartarSugerencia(int $id): void
    {
        $this->item($id)->update(['sugerencia' => null]);
    }

    public function render(): View
    {
        return view('livewire.editor-temario', [
            'unidades' => TemarioItem::query()->where('materia_id', $this->materiaId)->whereNull('parent_id')->orderBy('orden')
                ->with(['hijos' => fn ($q) => $q->orderBy('orden')])->get(),
        ]);
    }

    private function crear(?int $padre, string $tipo, string $titulo): void
    {
        TemarioItem::query()->create([
            'materia_id' => $this->materiaId, 'parent_id' => $padre, 'tipo' => $tipo, 'titulo' => Str::limit(trim($titulo), 250, ''),
            'orden' => (int) TemarioItem::query()->where('materia_id', $this->materiaId)->where('parent_id', $padre)->max('orden') + 1,
            'origen' => 'manual',
        ]);
    }

    private function item(int $id): TemarioItem
    {
        return TemarioItem::query()->where('materia_id', $this->materiaId)->findOrFail($id);
    }
}
