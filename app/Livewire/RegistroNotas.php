<?php

namespace App\Livewire;

use App\Models\Actividad;
use App\Models\Componente;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\Nota;
use App\Models\NotaCambio;
use App\Notas\RegistroNotas as Registro;
use App\Notas\TableroNotas;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Registro de notas de un grupo (módulo 1): pestaña por corte y consolidado, columnas
 * dinámicas, celdas editables, deshacer por operación e historial de cada nota.
 */
class RegistroNotas extends Component
{
    #[Locked]
    public int $grupoId;

    /** Id de la división activa o 'consolidado'. */
    #[Url(as: 'vista')]
    public string $pestana = '';

    /** @var array<int, string> nombre de la nueva columna por componente */
    public array $nuevaActividad = [];

    /** @var array<string, string> errores por celda "actividad-estudiante" */
    public array $errores = [];

    public ?string $mensaje = null;

    /** Historial abierto: ['titulo' => ..., 'cambios' => [...]] */
    public ?array $historial = null;

    public function mount(Grupo $grupo): void
    {
        Gate::authorize('gestionar', $grupo);
        $this->grupoId = $grupo->id;

        if ($this->pestana === '') {
            $primera = $grupo->dictado->divisiones()->whereNull('parent_id')->orderBy('orden')->first();
            $this->pestana = $primera ? (string) $primera->id : 'consolidado';
        }
    }

    public function guardar(int $actividadId, int $estudianteId, string $valor): void
    {
        $actividad = $this->actividad($actividadId);
        $estudiante = $this->estudiante($estudianteId);
        $clave = "{$actividadId}-{$estudianteId}";
        unset($this->errores[$clave]);
        $this->mensaje = null;

        $valor = trim(str_replace(',', '.', $valor));   // se acepta coma decimal
        $valor = $valor === '' ? null : $valor;

        try {
            if ($valor !== null) {
                BigDecimal::of($valor);
            }
            $registro = app(Registro::class);
            $op = $registro->operacion(fn () => $registro->asignar($actividad, $estudiante, $valor, 'digitada'));
            $this->apilar($op, "Nota de {$estudiante->nombreCompleto()} en «{$actividad->nombre}»");
        } catch (MathException) {
            $this->errores[$clave] = 'No es un número.';
        } catch (InvalidArgumentException $e) {
            $this->errores[$clave] = $e->getMessage();
        }
    }

    public function crearActividad(int $componenteId): void
    {
        $componente = Componente::query()->findOrFail($componenteId);
        abort_unless($componente->division->dictado_id === $this->grupo()->dictado_id, 403);

        $nombre = trim($this->nuevaActividad[$componenteId] ?? '');
        if ($nombre === '') {
            $this->mensaje = 'Escriba el nombre de la actividad.';

            return;
        }

        $actividad = $componente->actividades()->create([
            'nombre' => Str::limit($nombre, 120, ''),
            'valor_max' => $this->grupo()->dictado->escala_max,
            'orden' => (int) $componente->actividades()->max('orden') + 1,
        ]);
        $actividad->grupos()->attach($this->grupoId);   // la columna es de este grupo

        $this->nuevaActividad[$componenteId] = '';
        $this->mensaje = "Actividad «{$actividad->nombre}» creada.";
    }

    public function renombrar(int $actividadId, string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre !== '') {
            $this->actividad($actividadId)->update(['nombre' => Str::limit($nombre, 120, '')]);
        }
    }

    public function mover(int $actividadId, int $direccion): void
    {
        $a = $this->actividad($actividadId);
        $vecina = $a->componente->actividades()
            ->where('orden', $direccion < 0 ? '<' : '>', $a->orden)
            ->orderBy('orden', $direccion < 0 ? 'desc' : 'asc')
            ->first();

        if ($vecina) {
            [$a->orden, $vecina->orden] = [$vecina->orden, $a->orden];
            $a->save();
            $vecina->save();
        }
    }

    /**
     * 3.1.4: cambia la actividad de componente, con sus notas. Solo dentro del mismo periodo; la definitiva se
     * recalcula sola porque se calcula a partir de la estructura.
     */
    public function cambiarComponente(int $actividadId, int $componenteId): void
    {
        $a = $this->actividad($actividadId);
        $destino = Componente::query()->with('division')->findOrFail($componenteId);
        abort_unless($destino->division->dictado_id === $this->grupo()->dictado_id, 403);
        if ($destino->id !== $a->componente_id) {
            $a->update(['componente_id' => $destino->id, 'orden' => (int) $destino->actividades()->max('orden') + 1]);
        }
    }

    public function vaciasACeros(int $actividadId): void
    {
        $actividad = $this->actividad($actividadId);
        $registro = app(Registro::class);

        $op = $registro->operacion(function () use ($registro, $actividad) {
            foreach ($this->tablero()->estudiantes() as $e) {
                if ($this->tablero()->nota($actividad->id, $e->id) === null) {
                    $registro->asignar($actividad, $e, $this->grupo()->dictado->escala_min, 'ceros_explicitos', 'Vacías convertidas en cero');
                }
            }
        });

        $this->apilar($op, "Vacías a cero en «{$actividad->nombre}»");
        $this->mensaje = "Las celdas vacías de «{$actividad->nombre}» quedaron en cero. Puede deshacerlo.";
    }

    public function eliminarActividad(int $actividadId, int $confirmacion): void
    {
        $actividad = $this->actividad($actividadId);

        try {
            app(Registro::class)->eliminarActividad($actividad, $confirmacion, 'Eliminada desde el registro');
            $this->mensaje = "Actividad «{$actividad->nombre}» eliminada.";
        } catch (InvalidArgumentException $e) {
            $this->mensaje = $e->getMessage();
        }
    }

    public function deshacer(): void
    {
        $pila = session($this->clavePila(), []);
        $ultima = array_pop($pila);
        if ($ultima === null) {
            return;
        }

        app(Registro::class)->deshacer($ultima['op']);
        session([$this->clavePila() => $pila]);
        $this->errores = [];
        $this->mensaje = "Deshecho: {$ultima['descripcion']}.";
    }

    public function verHistorial(int $actividadId, int $estudianteId): void
    {
        $actividad = $this->actividad($actividadId);
        $estudiante = $this->estudiante($estudianteId);
        $nota = Nota::query()->where(['actividad_id' => $actividadId, 'estudiante_id' => $estudianteId])->first();

        $this->historial = [
            'titulo' => "{$estudiante->nombreCompleto()} — {$actividad->nombre}",
            'cambios' => $nota?->cambios()->get()->map(fn ($c) => [
                'fecha' => $c->created_at->format('Y-m-d H:i'),
                'anterior' => $c->valor_anterior === null ? 'vacía' : $this->tablero()->escala()->mostrar($c->valor_anterior),
                'nuevo' => $c->valor_nuevo === null ? 'vacía' : $this->tablero()->escala()->mostrar($c->valor_nuevo),
                'origen' => $c->origen,
                'motivo' => $c->motivo,
            ])->all() ?? [],
        ];
    }

    public function cerrarHistorial(): void
    {
        $this->historial = null;
    }

    public function render(): View
    {
        $this->tableroCache = null;   // siempre datos frescos después de una acción
        $tablero = $this->tablero();
        $division = is_numeric($this->pestana) ? $tablero->division((int) $this->pestana) : null;

        return view('livewire.registro-notas', [
            'grupo' => $this->grupo(),
            'tablero' => $tablero,
            'division' => $division,
            'filas' => $division ? $tablero->filasDivision($division) : $tablero->filasConsolidado(),
            'ultimaAccion' => last(session($this->clavePila(), [])) ?: null,
            'retirados' => $this->grupo()->estudiantes()->wherePivot('activo', false)->orderBy('apellidos')->get(),
            'componentesDestino' => Componente::query()->whereHas('division', fn ($q) => $q->where('dictado_id', $this->grupo()->dictado_id))
                ->with('division')->get()->sortBy(fn ($c) => [$c->division->orden, $c->orden])->values(),
        ]);
    }

    private ?TableroNotas $tableroCache = null;

    private function tablero(): TableroNotas
    {
        return $this->tableroCache ??= new TableroNotas($this->grupo());
    }

    private function grupo(): Grupo
    {
        return Grupo::query()->with('dictado.materia')->findOrFail($this->grupoId);
    }

    /** La actividad debe pertenecer al dictado de este grupo. */
    private function actividad(int $id): Actividad
    {
        $a = Actividad::query()->with('componente.division')->findOrFail($id);
        abort_unless($a->componente->division->dictado_id === $this->grupo()->dictado_id, 403);
        $this->tableroCache = null;

        return $a;
    }

    /** El estudiante debe estar en este grupo. */
    private function estudiante(int $id): Estudiante
    {
        return $this->grupo()->estudiantes()->whereKey($id)->firstOrFail();
    }

    private function apilar(string $op, string $descripcion): void
    {
        // Una operación que no cambió nada (mismo valor reescrito) no se apila
        if (! NotaCambio::query()->where('operacion_id', $op)->exists()) {
            return;
        }
        $pila = session($this->clavePila(), []);
        $pila[] = ['op' => $op, 'descripcion' => $descripcion];
        session([$this->clavePila() => array_slice($pila, -50)]);
    }

    private function clavePila(): string
    {
        return "deshacer.grupo.{$this->grupoId}";
    }
}
