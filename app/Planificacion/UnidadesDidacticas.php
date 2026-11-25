<?php

namespace App\Planificacion;

use App\Models\Materia;
use App\Models\TemarioItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reglas de una unidad didáctica, iguales para la hecha a mano y la generada por IA (7.4).
 * Distingue errores (impiden guardar) de advertencias (el docente decide): un tema que no está
 * en el temario o un tema de la unidad que ninguna sesión cubre no bloquean, pero se avisan.
 */
final class UnidadesDidacticas
{
    public const FORMATO = 'plataforma-unidad';

    public const MAX_SESIONES = 30;

    public const EVALUACION_SESION = ['ninguna' => 'Sin evaluación', 'diagnostica' => 'Diagnóstica', 'formativa' => 'Formativa', 'sumativa' => 'Sumativa'];

    public const TIPOS_ACTIVIDAD = ['quiz' => 'Quiz', 'taller' => 'Taller', 'parcial' => 'Parcial', 'trabajo' => 'Trabajo o proyecto', 'sustentacion' => 'Sustentación', 'otra' => 'Otra'];

    /** @return array{titulo: string, proposito: string, resultados: array, previos: array, sesiones: array, evaluacion: array} */
    public function normalizar(array $u): array
    {
        $t = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/\R/', $t($v))), fn ($x) => $x !== ''));

        $sesiones = [];
        foreach (array_values(is_array($u['sesiones'] ?? null) ? $u['sesiones'] : []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $ev = is_array($s['evaluacion'] ?? null) ? $s['evaluacion'] : ['descripcion' => $s['evaluacion'] ?? ''];
            $s = [
                'titulo' => $t($s['titulo'] ?? ''),
                'temas' => $lista($s['temas'] ?? []),
                'objetivo' => $t($s['objetivo'] ?? ''),
                'duracion' => preg_replace('/\D/', '', $t($s['duracion'] ?? '')),
                'inicio' => $t($s['inicio'] ?? ''),
                'desarrollo' => $t($s['desarrollo'] ?? ''),
                'cierre' => $t($s['cierre'] ?? ''),
                'recursos' => $lista($s['recursos'] ?? []),
                'evaluacion' => [
                    'tipo' => Str::lower(Str::ascii($t($ev['tipo'] ?? ''))) ?: 'ninguna',
                    'descripcion' => $t($ev['descripcion'] ?? ''),
                ],
            ];
            if ($s['titulo'] === '' && $s['objetivo'] === '' && $s['desarrollo'] === '' && $s['temas'] === []) {
                continue;                                                    // sesión vacía del editor
            }
            $sesiones[] = $s;
        }

        $evaluacion = [];
        foreach (array_values(is_array($u['evaluacion'] ?? null) ? $u['evaluacion'] : []) as $e) {
            if (is_array($e) && ($t($e['actividad'] ?? '') !== '' || $t($e['descripcion'] ?? '') !== '')) {
                $evaluacion[] = ['actividad' => $t($e['actividad'] ?? ''), 'tipo' => Str::lower(Str::ascii($t($e['tipo'] ?? 'otra'))) ?: 'otra', 'descripcion' => $t($e['descripcion'] ?? '')];
            }
        }

        return [
            'titulo' => $t($u['titulo'] ?? ''),
            'proposito' => $t($u['proposito'] ?? ''),
            'resultados' => $lista($u['resultados'] ?? []),
            'previos' => $lista($u['previos'] ?? []),
            'sesiones' => $sesiones,
            'evaluacion' => $evaluacion,
        ];
    }

    /** @return array<int, string> lo que impide guardar */
    public function errores(array $u): array
    {
        $u = $this->normalizar($u);
        $e = [];
        if ($u['titulo'] === '') {
            $e[] = 'Falta el título de la unidad didáctica.';
        }
        if ($u['sesiones'] === []) {
            return [...$e, 'La unidad necesita al menos una sesión.'];
        }
        if (count($u['sesiones']) > self::MAX_SESIONES) {
            $e[] = 'Tiene '.count($u['sesiones']).' sesiones; el máximo es '.self::MAX_SESIONES.'.';
        }
        foreach ($u['sesiones'] as $i => $s) {
            $n = 'Sesión '.($i + 1).($s['titulo'] !== '' ? " («{$s['titulo']}»)" : '');
            foreach (['titulo' => 'el título', 'objetivo' => 'el objetivo', 'desarrollo' => 'qué se hace en el desarrollo'] as $campo => $nombre) {
                if ($s[$campo] === '') {
                    $e[] = "{$n}: falta {$nombre}.";
                }
            }
            if ($s['duracion'] === '' || (int) $s['duracion'] < 10 || (int) $s['duracion'] > 600) {
                $e[] = "{$n}: la duración debe estar entre 10 y 600 minutos.";
            }
            if (! array_key_exists($s['evaluacion']['tipo'], self::EVALUACION_SESION)) {
                $e[] = "{$n}: la evaluación debe ser diagnóstica, formativa, sumativa o ninguna.";
            }
        }
        foreach ($u['evaluacion'] as $i => $ev) {
            if ($ev['actividad'] === '') {
                $e[] = 'Actividad de evaluación '.($i + 1).': falta el nombre.';
            }
            if (! array_key_exists($ev['tipo'], self::TIPOS_ACTIVIDAD)) {
                $e[] = 'Actividad de evaluación «'.$ev['actividad'].'»: el tipo debe ser '.implode(', ', array_keys(self::TIPOS_ACTIVIDAD)).'.';
            }
        }

        return $e;
    }

    /**
     * Lo que el docente debe revisar pero no impide guardar: temas que no están en el temario y
     * temas de la unidad que ninguna sesión cubre.
     *
     * @return array<int, string>
     */
    public function advertencias(array $u, Materia $materia, ?TemarioItem $unidad): array
    {
        $u = $this->normalizar($u);
        $temario = $materia->temario()->where('tipo', 'tema')->get();
        if ($temario->isEmpty()) {
            return [];
        }
        $a = [];
        $usados = [];
        foreach ($u['sesiones'] as $i => $s) {
            foreach ($s['temas'] as $tema) {
                $item = $this->tema($temario, $tema);
                if ($item === null) {
                    $a[] = 'Sesión '.($i + 1)." trabaja «{$tema}», que no está en el temario de la materia.";
                } else {
                    $usados[] = $item->id;
                }
            }
        }
        if ($unidad) {
            $faltan = $temario->where('parent_id', $unidad->id)->reject(fn ($t) => in_array($t->id, $usados, true));
            if ($faltan->isNotEmpty()) {
                $a[] = 'Ninguna sesión cubre estos temas de la unidad: '.$faltan->pluck('titulo')->join(', ').'.';
            }
        }

        return $a;
    }

    /** Tema del temario que corresponde al nombre escrito, sin importar mayúsculas ni tildes. */
    public function tema(Collection $temario, string $nombre): ?TemarioItem
    {
        $clave = fn ($s) => Str::of($s)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();

        return $temario->first(fn ($t) => $clave($t->titulo) === $clave($nombre));
    }

    public function minutos(array $u): int
    {
        return array_sum(array_map(fn ($s) => (int) $s['duracion'], $this->normalizar($u)['sesiones']));
    }

    /** Unidad vacía para el editor manual: las sesiones salen de los temas de la unidad, si hay. */
    public function plantillaVacia(?TemarioItem $unidad, int $duracion = 120): array
    {
        $temas = $unidad ? $unidad->hijos()->where('tipo', 'tema')->pluck('titulo')->all() : [];
        $resultados = $unidad ? $unidad->hijos()->where('tipo', 'resultado')->pluck('titulo')->all() : [];
        $sesion = fn (string $titulo, array $temas) => ['titulo' => $titulo, 'temas' => $temas, 'objetivo' => '', 'duracion' => (string) $duracion,
            'inicio' => '', 'desarrollo' => '', 'cierre' => '', 'recursos' => [], 'evaluacion' => ['tipo' => 'formativa', 'descripcion' => '']];

        return [
            'titulo' => $unidad?->titulo ?? '',
            'proposito' => (string) $unidad?->descripcion,
            'resultados' => $resultados,
            'previos' => [],
            'sesiones' => $temas ? array_map(fn ($t) => $sesion($t, [$t]), $temas) : [$sesion('', [])],
            'evaluacion' => [],
        ];
    }
}
