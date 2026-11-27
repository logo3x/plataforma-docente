<?php

namespace App\Calificacion;

use App\Evaluacion\ConfiguracionActividad;
use App\Models\Actividad;
use App\Models\Estudiante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Grupos de trabajo de una actividad grupal (6.8.1 a 6.8.3). Dos vías, con valor por defecto según el modo:
 *  - «docente»: el docente arma los grupos antes de aplicar; la entrega queda a nombre de todo el grupo
 *    de quien la sube, sin que el estudiante declare a nadie (por defecto en el parcial sin conexión);
 *  - «estudiante»: quien entrega escoge a sus compañeros de la lista del curso, nunca tecleando documentos
 *    (por defecto en el taller con conexión).
 * El docente puede forzar cualquiera de las dos en una actividad concreta.
 */
final class GruposTrabajo
{
    public const MODOS = [
        'docente' => 'El docente define los grupos antes de aplicar',
        'estudiante' => 'El estudiante que entrega escoge a sus compañeros de la lista',
    ];

    public function modo(Actividad $a): string
    {
        if (array_key_exists((string) $a->grupos_por, self::MODOS)) {
            return $a->grupos_por;
        }

        return $this->modoPorDefecto($a);
    }

    /**
     * Si la entrega se asigna con los grupos del docente: la vía es «docente» y ya los armó. Mientras no los
     * arme, cada estudiante escoge a sus compañeros, para que una actividad grupal nunca quede sin grupos.
     */
    public function usaGruposDelDocente(Actividad $a): bool
    {
        return $this->modo($a) === 'docente' && DB::table('integrantes_trabajo')->where('actividad_id', $a->id)->exists();
    }

    /** 6.8: en sala (sin conexión) los arma el docente; en la casa, el estudiante. */
    public function modoPorDefecto(Actividad $a): string
    {
        return $a->artefacto && ConfiguracionActividad::efectiva($a->artefacto)['modo_desconectado'] ? 'docente' : 'estudiante';
    }

    /** Estudiantes que pueden entrar en un grupo: los activos de los grupos de la actividad (o de todo el periodo). */
    public function candidatos(Actividad $a): Collection
    {
        $grupos = $a->grupos()->pluck('grupos.id');
        $dictadoId = $a->componente->division->dictado_id;

        return Estudiante::query()->where('dictado_id', $dictadoId)
            ->whereHas('grupos', fn ($q) => $q->where('grupo_estudiante.activo', true)
                ->when($grupos->isNotEmpty(), fn ($g) => $g->whereIn('grupos.id', $grupos)))
            ->with('grupos')->orderBy('apellidos')->orderBy('nombres')->get();
    }

    /** @return Collection<int, Collection<int, Estudiante>> número de grupo → integrantes */
    public function grupos(Actividad $a): Collection
    {
        return Estudiante::query()->join('integrantes_trabajo as it', 'it.estudiante_id', '=', 'estudiantes.id')
            ->where('it.actividad_id', $a->id)->orderBy('it.numero')->orderBy('apellidos')
            ->get(['estudiantes.*', 'it.numero'])->groupBy('numero');
    }

    /** Número de grupo de cada estudiante. @return array<int, int> */
    public function asignacion(Actividad $a): array
    {
        return DB::table('integrantes_trabajo')->where('actividad_id', $a->id)->pluck('numero', 'estudiante_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Guarda los grupos del docente. Quien no tiene número queda sin grupo.
     *
     * @param  array<int|string, int|string|null>  $numeroPorEstudiante  id de estudiante → número de grupo
     * @return array{grupos: int, asignados: int}
     */
    public function guardar(Actividad $a, array $numeroPorEstudiante): array
    {
        $validos = $this->candidatos($a)->pluck('id')->flip();
        $filas = [];
        foreach ($numeroPorEstudiante as $id => $numero) {
            if ($numero === null || $numero === '' || (int) $numero === 0) {
                continue;
            }
            if (! isset($validos[(int) $id])) {
                throw new InvalidArgumentException('Uno de los estudiantes no está en los grupos de esta actividad.');
            }
            if ((int) $numero < 1 || (int) $numero > 200) {
                throw new InvalidArgumentException('El número de grupo debe estar entre 1 y 200.');
            }
            $filas[] = ['actividad_id' => $a->id, 'estudiante_id' => (int) $id, 'numero' => (int) $numero];
        }

        DB::transaction(function () use ($a, $filas) {
            DB::table('integrantes_trabajo')->where('actividad_id', $a->id)->delete();
            DB::table('integrantes_trabajo')->insert($filas);
        });

        return ['grupos' => collect($filas)->pluck('numero')->unique()->count(), 'asignados' => count($filas)];
    }

    /**
     * Reparte al azar a quienes aún no tienen grupo, de a $tamano, sin mover a los ya asignados.
     *
     * @return array<int, int> la asignación nueva completa (para mostrarla antes de guardar)
     */
    public function repartir(Actividad $a, int $tamano): array
    {
        $actual = $this->asignacion($a);
        $sueltos = $this->candidatos($a)->reject(fn ($e) => isset($actual[$e->id]))->shuffle()->values();
        $siguiente = $actual ? max($actual) + 1 : 1;
        foreach ($sueltos->chunk(max(2, $tamano)) as $i => $trozo) {
            foreach ($trozo as $e) {
                $actual[$e->id] = $siguiente + $i;
            }
        }

        return $actual;
    }

    /** Compañeros de grupo definidos por el docente, incluido él mismo; vacío si no está en ninguno. */
    public function delGrupoDe(Actividad $a, Estudiante $e): Collection
    {
        $numero = DB::table('integrantes_trabajo')->where('actividad_id', $a->id)->where('estudiante_id', $e->id)->value('numero');

        return $numero === null ? collect() : Estudiante::query()->whereIn('id',
            DB::table('integrantes_trabajo')->where('actividad_id', $a->id)->where('numero', $numero)->pluck('estudiante_id'))->get();
    }

    /**
     * Lista para escoger compañeros dentro del examen (vía «estudiante»): nombre y grupo del curso, con una
     * referencia interna en lugar del documento, para que el archivo no reparta los documentos de todo el curso.
     *
     * @return array<int, array{r: string, n: string, g: ?string}>
     */
    public function listaParaExamen(Actividad $a): array
    {
        return $this->candidatos($a)->map(fn (Estudiante $e) => [
            'r' => 'e'.$e->id, 'n' => $e->nombreCompleto(), 'g' => $e->grupos->first()?->nombre,
        ])->values()->all();
    }
}
