<?php

namespace App\Livewire;

use App\Calificacion\CalificadorEntrega;
use App\Evaluacion\SesionEnVivo;
use App\Models\Estudiante;
use App\Models\SesionVivo;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Panel en vivo del grupo (13.3): quién inició, su avance, sus salidas, si tiene señal y quién finalizó;
 * finalización masiva o de los elegidos (13.1). Se actualiza solo.
 */
class PanelEnVivo extends Component
{
    #[Locked]
    public int $sesionId;

    /** @var array<int, string> */
    public array $elegidos = [];

    public ?string $aviso = null;

    public function mount(SesionVivo $sesion): void
    {
        Gate::authorize('gestionar', $sesion->actividad->componente->division->dictado);
        $this->sesionId = $sesion->id;
    }

    public function finalizarElegidos(SesionEnVivo $vivo): void
    {
        $n = $vivo->finalizar($this->sesion(), auth()->user(), array_map('intval', $this->elegidos));
        $this->elegidos = [];
        $this->aviso = "Se pidió finalizar {$n} examen(es). Cada uno se cierra y entrega solo; el que no responda en "
            .SesionEnVivo::ESPERA_CIERRE.' s se registra con lo último que envió.';
    }

    public function finalizarTodos(SesionEnVivo $vivo): void
    {
        $n = $vivo->finalizar($this->sesion(), auth()->user());
        $vivo->cerrar($this->sesion());
        $this->aviso = "Se pidió finalizar los {$n} examen(es) abiertos y se cerró la sesión: nadie más puede entrar.";
    }

    public function cerrar(SesionEnVivo $vivo): void
    {
        $vivo->cerrar($this->sesion());
        $this->aviso = 'Sesión cerrada: nadie más puede entrar. Los que están respondiendo siguen hasta finalizar.';
    }

    public function render(SesionEnVivo $vivo, CalificadorEntrega $calificador): View
    {
        $sesion = $this->sesion();
        $vivo->barrer($sesion);
        $actividad = $sesion->actividad->load('grupos', 'componente.division.dictado');
        $participantes = $sesion->participantes()->with('entrega', 'solicitante', 'estudiante')->get()
            ->sortBy(fn ($p) => [$p->finalizado() ? 1 : 0, $p->estudiante?->apellidos ?? $p->nombre])->values();

        // Esperados: los estudiantes de los grupos de la actividad que todavía no entran
        $grupos = $actividad->grupos->isNotEmpty() ? $actividad->grupos : $actividad->componente->division->dictado->grupos;
        $esperados = Estudiante::query()->whereHas('grupos', fn ($q) => $q->whereIn('grupos.id', $grupos->pluck('id')))
            ->whereNotIn('documento', $participantes->pluck('documento'))->orderBy('apellidos')->get();

        return view('livewire.panel-en-vivo', [
            'sesion' => $sesion,
            'participantes' => $participantes,
            'esperados' => $esperados,
            'escala' => $actividad->componente->division->dictado->escala(),
            'calificador' => $calificador,
            'sinSenal' => now()->subSeconds(SesionEnVivo::SIN_SENAL),
            'masivas' => $participantes->whereNotNull('cierre_solicitado_en')->groupBy(fn ($p) => $p->cierre_solicitado_en->format('H:i').'|'.($p->solicitante?->name ?? '—')),
        ]);
    }

    private function sesion(): SesionVivo
    {
        return SesionVivo::query()->with('actividad')->findOrFail($this->sesionId);
    }
}
