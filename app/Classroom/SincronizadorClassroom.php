<?php

namespace App\Classroom;

use App\Calificacion\EntregasArchivo;
use App\Evaluacion\ProcesadorEntregas;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\EnvioClassroom;
use App\Models\Estudiante;
use App\Models\Grupo;
use App\Models\User;
use App\Notas\TableroNotas;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Integración con Google Classroom (decisión pendiente 4): la plataforma lee las entregas y escribe las notas.
 *
 *  - Los estudiantes se cruzan por correo institucional, nunca por nombre (3.1.5).
 *  - Las entregas de Classroom entran por el mismo camino que un archivo subido: el examen se identifica por su
 *    sigla y su documento cifrado, así que un examen subido a la tarea equivocada se detecta (lección de los
 *    ceros del cierre, 6.1); si lo subió otra persona, queda una alerta.
 *  - Las notas solo se pueden escribir en tareas creadas por esta aplicación (regla de Classroom). Por
 *    defecto van como borrador: el docente las devuelve desde Classroom, cuando quiera.
 */
final class SincronizadorClassroom
{
    public function __construct(private readonly ClienteClassroom $api, private readonly ProcesadorEntregas $procesador, private readonly EntregasArchivo $archivos) {}

    public function vincularGrupo(User $u, Grupo $grupo, string $curso): array
    {
        $c = collect($this->api->cursos($u))->firstWhere('id', $curso) ?? throw new InvalidArgumentException('Ese curso no está entre sus cursos activos de Classroom.');
        $grupo->update(['classroom_curso_id' => $c['id'], 'classroom_curso_nombre' => trim($c['nombre'].' '.($c['seccion'] ?? ''))]);

        return $this->cruzar($u, $grupo);
    }

    /** @return array{cruzados: int, sin_correo: array, no_estan: array, solo_en_classroom: array} */
    public function cruzar(User $u, Grupo $grupo): array
    {
        $alumnos = collect($this->api->alumnos($u, $grupo->classroom_curso_id))->filter(fn ($a) => $a['email'])->keyBy('email');
        $estudiantes = $grupo->estudiantes()->wherePivot('activo', true)->orderBy('apellidos')->get();
        $r = ['cruzados' => 0, 'sin_correo' => [], 'no_estan' => [], 'solo_en_classroom' => []];
        $usados = [];
        foreach ($estudiantes as $e) {
            $correo = $e->email ? mb_strtolower(trim($e->email)) : null;
            if (! $correo) {
                $r['sin_correo'][] = $e->nombreCompleto();
            } elseif ($a = $alumnos->get($correo)) {
                $e->update(['classroom_usuario_id' => $a['id']]);
                $usados[] = $correo;
                $r['cruzados']++;
            } else {
                $r['no_estan'][] = $e->nombreCompleto()." ({$correo})";
            }
        }
        $r['solo_en_classroom'] = $alumnos->except($usados)->map(fn ($a) => "{$a['nombre']} ({$a['email']})")->values()->all();

        return $r;
    }

    /** El curso de Classroom de la actividad: el de su grupo, o el único del periodo que tenga uno. */
    public function cursoDe(Actividad $a): ?Grupo
    {
        $grupos = $a->grupos()->whereNotNull('classroom_curso_id')->get();
        if ($grupos->isEmpty()) {
            $grupos = $a->componente->division->dictado->grupos()->whereNotNull('classroom_curso_id')->get();
        }

        return $grupos->count() === 1 ? $grupos->first() : null;
    }

    public function vincularTarea(User $u, Actividad $a, string $tarea): void
    {
        $grupo = $this->cursoDe($a) ?? throw new InvalidArgumentException('Vincule primero el grupo de la actividad con su curso de Classroom.');
        $t = collect($this->api->tareas($u, $grupo->classroom_curso_id))->firstWhere('id', $tarea) ?? throw new InvalidArgumentException('Esa tarea no está en el curso.');
        $a->update(['classroom_tarea_id' => $t['id'], 'classroom_tarea' => $t + ['curso' => $grupo->classroom_curso_id]]);
    }

    /** Crea la tarea en Classroom con los puntos de la escala del periodo; así se le pueden escribir las notas. */
    public function crearTarea(User $u, Actividad $a, ?string $descripcion, bool $publicar): void
    {
        $grupo = $this->cursoDe($a) ?? throw new InvalidArgumentException('Vincule primero el grupo de la actividad con su curso de Classroom.');
        $puntos = (float) (string) $a->componente->division->dictado->escala()->maximo;
        $t = $this->api->crearTarea($u, $grupo->classroom_curso_id, $a->nombre, $descripcion, $puntos, $publicar);
        $a->update(['classroom_tarea_id' => $t['id'], 'classroom_tarea' => $t + ['curso' => $grupo->classroom_curso_id]]);
    }

    /**
     * Trae las entregas entregadas (o devueltas) de la tarea y las procesa como si se hubieran subido.
     *
     * @return array<int, array{estado: string, mensaje: string}>
     */
    public function importar(User $u, Actividad $a): array
    {
        $t = $a->classroom_tarea ?? throw new InvalidArgumentException('Vincule primero la actividad con una tarea de Classroom.');
        $dictado = $a->componente->division->dictado;
        $porUsuario = Estudiante::query()->where('dictado_id', $dictado->id)->whereNotNull('classroom_usuario_id')->get()->keyBy('classroom_usuario_id');
        $res = [];
        foreach ($this->api->entregas($u, $t['curso'], $t['id']) as $s) {
            if (! in_array($s['estado'], ['TURNED_IN', 'RETURNED'], true)) {
                continue;                                         // sin entregar todavía
            }
            $e = $porUsuario->get($s['alumno']);
            $quien = $e?->nombreCompleto() ?? 'Un alumno de Classroom que no cruzó con la lista';
            if ($s['archivos'] === []) {
                $res[] = ['estado' => 'rechazada', 'mensaje' => "{$quien}: entregó sin adjuntar archivos."];

                continue;
            }
            if ($a->entregas()->where('classroom_entrega_id', $s['id'])->exists()) {
                $res[] = ['estado' => 'duplicada', 'mensaje' => "{$quien}: ya se había importado."];

                continue;
            }
            $res = [...$res, ...($a->artefacto_id ? $this->importarExamen($u, $a, $s, $e, $quien) : $this->importarArchivo($u, $a, $s, $e, $quien))];
        }

        return $res;
    }

    private function importarExamen(User $u, Actividad $a, array $s, ?Estudiante $e, string $quien): array
    {
        $res = [];
        foreach ($s['archivos'] as $f) {
            try {
                $d = $this->api->descargar($u, $f['id']);
            } catch (RuntimeException $ex) {
                $res[] = ['estado' => 'rechazada', 'mensaje' => "{$quien}, {$f['nombre']}: {$ex->getMessage()}"];

                continue;
            }
            if (! is_array(json_decode($d['contenido'], true))) {
                $res[] = ['estado' => 'rechazada', 'mensaje' => "{$quien}, {$f['nombre']}: no es el archivo .json del examen."];

                continue;
            }
            $r = $this->procesador->recibir($a, $d['contenido'], $f['nombre']);
            if ($r['entrega']) {
                $r['entrega']->update(['classroom_entrega_id' => $s['id']]);
                $this->alertasDeClassroom($a, $r['entrega'], $e, $s);
            }
            $res[] = ['estado' => $r['estado'], 'mensaje' => "{$quien} — {$r['mensaje']}"];
        }

        return $res;
    }

    private function importarArchivo(User $u, Actividad $a, array $s, ?Estudiante $e, string $quien): array
    {
        // Un trabajo es una entrega: se toma el primer archivo que sirve (el PDF, si la actividad lo pide)
        foreach ($s['archivos'] as $f) {
            try {
                $d = $this->api->descargar($u, $f['id']);
            } catch (RuntimeException $ex) {
                $ultimo = "{$f['nombre']}: {$ex->getMessage()}";

                continue;
            }
            $r = $this->archivos->recibirContenido($a, $f['nombre'], $d['contenido'], $d['mime'], $e ? collect([$e]) : collect(), ['classroom_entrega_id' => $s['id']]);
            if ($r['entrega']) {
                $this->alertasDeClassroom($a, $r['entrega'], $e, $s);

                return [['estado' => $r['estado'], 'mensaje' => "{$quien} — {$r['mensaje']}".(count($s['archivos']) > 1 ? ' (traía '.count($s['archivos']).' archivos; se tomó este).' : '')]];
            }
            $ultimo = $r['mensaje'];
        }

        return [['estado' => 'rechazada', 'mensaje' => "{$quien}: ".($ultimo ?? 'ningún archivo sirvió.')]];
    }

    /** Lo que Classroom sabe y el archivo no: si se entregó tarde y si lo subió otra persona. */
    private function alertasDeClassroom(Actividad $a, $entrega, ?Estudiante $autor, array $s): void
    {
        if ($s['tarde']) {
            Alerta::query()->create(['actividad_id' => $a->id, 'entrega_id' => $entrega->id, 'estudiante_id' => $autor?->id, 'tipo' => 'entrega_tardia', 'datos' => ['origen' => 'classroom']]);
        }
        if ($autor && ! $entrega->integrantes()->whereKey($autor->id)->exists()) {
            Alerta::query()->create(['actividad_id' => $a->id, 'entrega_id' => $entrega->id, 'estudiante_id' => $autor->id, 'tipo' => 'autor_distinto',
                'datos' => ['subio' => $autor->nombreCompleto(), 'documento' => $autor->documento]]);
        }
    }

    /**
     * Qué se enviaría: la nota vigente del registro (con segundas oportunidades) convertida a los puntos de la
     * tarea, para cada estudiante que tiene entrega en Classroom.
     *
     * @return array<int, array{estudiante: Estudiante, nota: ?string, puntos: ?float, entrega: ?string, actual: ?float}>
     */
    public function plan(User $u, Actividad $a): array
    {
        $t = $a->classroom_tarea ?? throw new InvalidArgumentException('Vincule primero la actividad con una tarea de Classroom.');
        $grupo = $this->cursoDe($a) ?? throw new InvalidArgumentException('La actividad no tiene un curso de Classroom.');
        $tablero = new TableroNotas($grupo);
        $max = $tablero->escala()->maximo;
        $porAlumno = collect($this->api->entregas($u, $t['curso'], $t['id']))->keyBy('alumno');

        return $tablero->estudiantes()->map(function (Estudiante $e) use ($tablero, $a, $porAlumno, $t, $max) {
            $nota = $tablero->notaEfectiva($a->id, $e->id);
            $s = $e->classroom_usuario_id ? $porAlumno->get($e->classroom_usuario_id) : null;
            $puntos = $nota === null || ! $t['puntos'] ? null
                : (float) (string) BigDecimal::of($nota)->multipliedBy((string) $t['puntos'])->dividedBy((string) $max, 2, RoundingMode::HalfUp);

            return ['estudiante' => $e, 'nota' => $nota, 'puntos' => $puntos, 'entrega' => $s['id'] ?? null, 'actual' => $s['nota'] ?? null];
        })->values()->all();
    }

    /** @return array{enviadas: int, sin_cambio: int, omitidas: int, errores: array<int, string>} */
    public function enviar(User $u, Actividad $a, bool $asignar): array
    {
        $t = $a->classroom_tarea;
        if (! ($t['propia'] ?? false)) {
            throw new InvalidArgumentException('Classroom solo deja poner notas en tareas creadas por esta aplicación. Cree la tarea desde la plataforma.');
        }
        $r = ['enviadas' => 0, 'sin_cambio' => 0, 'omitidas' => 0, 'errores' => []];
        foreach ($this->plan($u, $a) as $f) {
            if ($f['puntos'] === null || $f['entrega'] === null) {
                $r['omitidas']++;

                continue;
            }
            if ($f['actual'] !== null && abs($f['actual'] - $f['puntos']) < 0.005 && ! $asignar) {
                $r['sin_cambio']++;

                continue;
            }
            try {
                $this->api->calificar($u, $t['curso'], $t['id'], $f['entrega'], $f['puntos'], $asignar);
                $error = null;
                $r['enviadas']++;
            } catch (Throwable $ex) {
                $error = mb_substr($ex->getMessage(), 0, 300);
                $r['errores'][] = $f['estudiante']->nombreCompleto().': '.$error;
            }
            EnvioClassroom::query()->create(['actividad_id' => $a->id, 'estudiante_id' => $f['estudiante']->id, 'user_id' => $u->id, 'entrega_classroom_id' => $f['entrega'],
                'nota' => $f['nota'], 'puntos' => $f['puntos'], 'como' => $asignar ? 'asignada' : 'borrador', 'error' => $error]);
        }

        return $r;
    }
}
