<?php

namespace App\Material;

use App\Models\Materia;
use Illuminate\Support\Str;

/**
 * Reglas de un conjunto de ideas de proyecto (7.4, «Planificación»): propuestas de trabajo que
 * integran los temas que el grupo ya vio, con el problema que resuelven, entregables, hitos por
 * semana, criterios de evaluación y notas para el docente (que no van en el enunciado del estudiante).
 */
final class IdeasProyecto implements TipoMaterial
{
    public const TIPO = 'proyectos';

    public const FORMATO = 'plataforma-proyectos';

    public const MAX_PROYECTOS = 10;

    public const MAX_SEMANAS = 18;

    public const DIFICULTADES = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'];

    public function tipo(): string
    {
        return self::TIPO;
    }

    public function formato(): string
    {
        return self::FORMATO;
    }

    public function coleccion(): string
    {
        return 'proyectos';
    }

    public function nombreElemento(array $elemento): string
    {
        return (string) ($elemento['titulo'] ?? '');
    }

    public function reglasGeneracion(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'between:1,'.self::MAX_PROYECTOS],
            'semanas' => ['required', 'integer', 'between:1,'.self::MAX_SEMANAS],
            'integrantes' => ['required', 'regex:/^[1-8](-[1-8])?$/'],
            'contexto' => ['nullable', 'string', 'max:500'],
            'vistos' => ['nullable', 'boolean'],
        ];
    }

    public function variables(Materia $materia, array $op): array
    {
        $codigo = $materia->perfil_codigo
            ? '- Si el proyecto incluye software, use las herramientas de la materia'.(filled($materia->estilo['herramientas'] ?? null) ? " ({$materia->estilo['herramientas']})" : '').'.'
            : '- La materia no exige programar: proponga productos acordes (informes, diseños, prototipos, análisis).';

        return [
            'cantidad' => (string) max(1, min(self::MAX_PROYECTOS, (int) ($op['cantidad'] ?? 3))),
            'semanas' => (string) max(1, min(self::MAX_SEMANAS, (int) ($op['semanas'] ?? 4))),
            'integrantes' => (string) ($op['integrantes'] ?? '2-3'),
            'contexto' => filled($op['contexto'] ?? null) ? trim($op['contexto']) : 'necesidades reales de empresas y comunidades de la región',
            'reglas_codigo' => $codigo,
        ];
    }

    /** @return array{titulo: string, proposito: string, proyectos: array<int, array>} */
    public function normalizar(array $d): array
    {
        $t = fn ($v) => is_scalar($v) ? trim((string) $v) : '';
        $lista = fn ($v) => array_values(array_filter(array_map($t, is_array($v) ? $v : preg_split('/\R/', $t($v))), fn ($x) => $x !== ''));

        $proyectos = [];
        foreach (array_values(is_array($d['proyectos'] ?? null) ? $d['proyectos'] : []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $hitos = [];
            foreach (array_values(is_array($p['hitos'] ?? null) ? $p['hitos'] : []) as $h) {
                $h = is_array($h) ? $h : ['entrega' => $h];
                if ($t($h['entrega'] ?? '') !== '') {
                    $hitos[] = ['semana' => preg_replace('/\D/', '', $t($h['semana'] ?? '')), 'entrega' => $t($h['entrega'])];
                }
            }
            usort($hitos, fn ($a, $b) => (int) $a['semana'] <=> (int) $b['semana']);
            $p = [
                'titulo' => $t($p['titulo'] ?? ''),
                'resumen' => $t($p['resumen'] ?? ''),
                'problema' => $t($p['problema'] ?? ''),
                'temas' => $lista($p['temas'] ?? []),
                'dificultad' => Str::lower(Str::ascii($t($p['dificultad'] ?? ''))) ?: 'media',
                'integrantes' => str_replace(' ', '', $t($p['integrantes'] ?? '')),
                'semanas' => preg_replace('/\D/', '', $t($p['semanas'] ?? '')),
                'entregables' => $lista($p['entregables'] ?? []),
                'hitos' => $hitos,
                'criterios' => $lista($p['criterios'] ?? []),
                'extension' => $t($p['extension'] ?? ''),
                'notas_docente' => $t($p['notas_docente'] ?? ''),
            ];
            if ($p['titulo'] === '' && $p['problema'] === '' && $p['entregables'] === []) {
                continue;                                                    // proyecto vacío del editor
            }
            $proyectos[] = $p;
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'proposito' => $t($d['proposito'] ?? ''), 'proyectos' => $proyectos];
    }

    public function errores(array $d): array
    {
        $d = $this->normalizar($d);
        $e = [];
        if ($d['titulo'] === '') {
            $e[] = 'Falta el título del conjunto de ideas.';
        }
        if ($d['proyectos'] === []) {
            return [...$e, 'Debe haber al menos un proyecto.'];
        }
        if (count($d['proyectos']) > self::MAX_PROYECTOS) {
            $e[] = 'Tiene '.count($d['proyectos']).' proyectos; el máximo es '.self::MAX_PROYECTOS.'.';
        }
        foreach ($d['proyectos'] as $i => $p) {
            $n = 'Proyecto '.($i + 1).($p['titulo'] !== '' ? " («{$p['titulo']}»)" : '');
            foreach (['titulo' => 'el título', 'problema' => 'el problema o la necesidad que resuelve'] as $campo => $nombre) {
                if ($p[$campo] === '') {
                    $e[] = "{$n}: falta {$nombre}.";
                }
            }
            if ($p['entregables'] === []) {
                $e[] = "{$n}: debe decir qué se entrega.";
            }
            if ($p['criterios'] === []) {
                $e[] = "{$n}: debe decir con qué criterios se evalúa.";
            }
            $semanas = (int) $p['semanas'];
            if ($semanas < 1 || $semanas > self::MAX_SEMANAS) {
                $e[] = "{$n}: la duración debe estar entre 1 y ".self::MAX_SEMANAS.' semanas.';
            }
            if (! preg_match('/^([1-8])(?:-([1-8]))?$/', $p['integrantes'], $m) || (isset($m[2]) && (int) $m[2] < (int) $m[1])) {
                $e[] = "{$n}: los integrantes se escriben como un número o un rango, por ejemplo 1 o 2-3 (máximo 8).";
            }
            if (! array_key_exists($p['dificultad'], self::DIFICULTADES)) {
                $e[] = "{$n}: la dificultad debe ser baja, media o alta.";
            }
            foreach ($p['hitos'] as $h) {
                if ($h['semana'] === '' || (int) $h['semana'] < 1 || ($semanas && (int) $h['semana'] > $semanas)) {
                    $e[] = "{$n}: el hito «{$h['entrega']}» debe caer entre la semana 1 y la {$semanas}.";
                }
            }
        }

        return $e;
    }

    public function advertencias(array $d, Materia $materia): array
    {
        $d = $this->normalizar($d);
        $clave = fn ($s) => Str::of($s)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
        $temas = $materia->temario()->where('tipo', 'tema')->pluck('titulo')->map($clave)->all();
        $a = [];
        foreach ($d['proyectos'] as $i => $p) {
            $n = 'Proyecto '.($i + 1);
            foreach ($p['temas'] as $tema) {
                if ($temas && ! in_array($clave($tema), $temas, true)) {
                    $a[] = "{$n} usa «{$tema}», que no está en el temario de la materia.";
                }
            }
            if ($p['hitos'] === []) {
                $a[] = "{$n} no tiene hitos: sin entregas parciales es difícil acompañar el avance.";
            } elseif ($p['semanas'] !== '' && (int) end($p['hitos'])['semana'] !== (int) $p['semanas']) {
                $a[] = "{$n}: ningún hito cae en la última semana (la {$p['semanas']}); falta la entrega final.";
            }
        }

        return $a;
    }

    public function plantillaVacia(array $temas): array
    {
        return ['titulo' => '', 'proposito' => '', 'proyectos' => [[
            'titulo' => '', 'resumen' => '', 'problema' => '', 'temas' => $temas, 'dificultad' => 'media', 'integrantes' => '2-3', 'semanas' => '4',
            'entregables' => [], 'hitos' => [['semana' => '2', 'entrega' => ''], ['semana' => '4', 'entrega' => '']], 'criterios' => [], 'extension' => '', 'notas_docente' => '',
        ]]];
    }
}
