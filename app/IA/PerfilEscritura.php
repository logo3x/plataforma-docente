<?php

namespace App\IA;

use App\Calificacion\ComentariosIA;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perfil de escritura del docente (8.5.3): muestras reales de los comentarios que él mismo escribió a sus
 * estudiantes, para que la IA proponga en su estilo. Solo si el docente lo activa. No entran los comentarios
 * que solo aceptaron tal cual lo que propuso la IA, ni los que delatan un cálculo (8.5.1), ni los que el
 * docente quite de la lista.
 */
final class PerfilEscritura
{
    public const MAX_MUESTRAS = 12;

    public function activo(User $docente): bool
    {
        return (bool) ($docente->perfil_escritura['activo'] ?? false);
    }

    public static function huella(string $texto): string
    {
        return substr(sha1(Str::of($texto)->lower()->squish()), 0, 16);
    }

    /**
     * Candidatas: lo más reciente primero, sin repetir.
     *
     * @return Collection<int, array{texto: string, huella: string, origen: string, excluida: bool}>
     */
    public function candidatas(User $docente, int $limite = 40): Collection
    {
        $materias = DB::table('materias')->where('user_id', $docente->id)->pluck('id');
        $actividades = DB::table('actividades as a')->join('componentes as c', 'c.id', '=', 'a.componente_id')->join('divisiones as d', 'd.id', '=', 'c.division_id')
            ->join('dictados as t', 't.id', '=', 'd.dictado_id')->whereIn('t.materia_id', $materias)->pluck('a.id');

        $fuentes = [
            'respuesta abierta' => DB::table('respuestas as r')->join('entregas as e', 'e.id', '=', 'r.entrega_id')->whereIn('e.actividad_id', $actividades)
                ->whereNotNull('r.comentario')->where(fn ($q) => $q->whereNull('r.comentario_borrador')->orWhereColumn('r.comentario', '!=', 'r.comentario_borrador'))
                ->orderByDesc('r.id')->limit($limite)->pluck('r.comentario'),
            'nota' => DB::table('notas')->whereIn('actividad_id', $actividades)->whereNotNull('comentario')->orderByDesc('updated_at')->limit($limite)->pluck('comentario'),
            'trabajo calificado' => DB::table('entregas')->whereIn('actividad_id', $actividades)->whereNotNull('comentario')->orderByDesc('updated_at')->limit($limite)->pluck('comentario'),
            'comentario frecuente' => DB::table('comentarios_frecuentes')->where('user_id', $docente->id)->orderByDesc('usos')->limit($limite)->pluck('texto'),
        ];
        $excluidos = array_flip($docente->perfil_escritura['excluidos'] ?? []);
        $vistas = [];
        $r = collect();
        foreach ($fuentes as $origen => $textos) {
            foreach ($textos as $t) {
                $t = trim((string) $t);
                $h = self::huella($t);
                if (mb_strlen($t) < 25 || mb_strlen($t) > 500 || isset($vistas[$h]) || ComentariosIA::delataCalculo($t)) {
                    continue;
                }
                $vistas[$h] = true;
                $r->push(['texto' => $t, 'huella' => $h, 'origen' => $origen, 'excluida' => isset($excluidos[$h])]);
            }
        }

        return $r->take($limite)->values();
    }

    /** Las muestras que van al prompt. @return array<int, string> */
    public function muestras(User $docente): array
    {
        return $this->candidatas($docente)->reject(fn ($m) => $m['excluida'])->take(self::MAX_MUESTRAS)->pluck('texto')->all();
    }

    /** El bloque para el prompt, o '' si el perfil no está activo o no hay muestras. */
    public function bloque(User $docente): string
    {
        $m = $this->activo($docente) ? $this->muestras($docente) : [];

        return $m === [] ? '' : "## Cómo escribe este docente (muestras reales de sus comentarios)\n"
            ."Imite su manera de escribir —largo de las frases, trato, palabras que usa, cómo señala lo que falta—, no el contenido:\n- "
            .implode("\n- ", array_map(fn ($x) => str_replace("\n", ' ', $x), $m));
    }
}
