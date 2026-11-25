<?php

namespace App\Evaluacion;

use App\Models\Artefacto;
use App\Models\ArtefactoPregunta;
use App\Models\Materia;
use App\Models\Pregunta;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Importa una actividad evaluable (quiz, parcial, taller) desde el formato «plataforma-banco» v1
 * (docs/formato-banco.md). Separa lo que ve el estudiante (enunciado) de la clave, que se
 * guarda cifrada y nunca sale en el examen (5.4.5).
 */
final class ImportadorBanco
{
    public const FORMATO = 'plataforma-banco';

    public const TIPOS = ['unica', 'vf', 'multiple', 'emparejar', 'ordenar', 'completar', 'abierta', 'reto'];

    /**
     * @return array<int, string> errores en lenguaje claro; vacío si el banco es válido
     */
    public function validar(array $b): array
    {
        $errores = [];
        if (($b['formato'] ?? null) !== self::FORMATO || (int) ($b['version'] ?? 0) !== 1) {
            return ['El archivo no es un banco «plataforma-banco» versión 1.'];
        }
        foreach (['sigla', 'titulo', 'valor'] as $campo) {
            if (blank($b[$campo] ?? null)) {
                $errores[] = "Falta «{$campo}».";
            }
        }
        if (isset($b['sigla']) && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.]{1,39}$/', (string) $b['sigla'])) {
            $errores[] = 'La sigla solo admite letras, números, guiones y puntos (máximo 40).';
        }
        $items = $b['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return [...$errores, 'El banco no tiene ítems.'];
        }

        $suma = BigDecimal::zero();
        foreach (array_values($items) as $i => $it) {
            $n = $i + 1;
            $tipo = $it['tipo'] ?? null;
            if (! in_array($tipo, self::TIPOS, true)) {
                $errores[] = "Ítem {$n}: tipo «{$tipo}» desconocido.";

                continue;
            }
            if (blank($it['enunciado'] ?? null)) {
                $errores[] = "Ítem {$n}: falta el enunciado.";
            }
            try {
                $peso = BigDecimal::of((string) ($it['peso'] ?? ''));
                if (! $peso->isPositive()) {
                    throw new InvalidArgumentException;
                }
                $suma = $suma->plus($peso);
            } catch (MathException|InvalidArgumentException) {
                $errores[] = "Ítem {$n}: el peso debe ser un número mayor que cero.";
            }
            foreach ($this->erroresDeTipo($tipo, $it) as $e) {
                $errores[] = "Ítem {$n} ({$tipo}): {$e}";
            }
        }

        try {
            if (! $suma->isEqualTo((string) ($b['valor'] ?? '0'))) {
                $errores[] = "Los pesos suman {$suma} y el valor de la actividad es {$b['valor']}: deben coincidir (9.2.4).";
            }
        } catch (MathException) {
            $errores[] = 'El valor de la actividad no es un número.';
        }

        return $errores;
    }

    public function importar(Materia $materia, array $b): Artefacto
    {
        $errores = $this->validar($b);
        if ($errores !== []) {
            throw new InvalidArgumentException(implode("\n", $errores));
        }
        if (Artefacto::query()->where('sigla', $b['sigla'])->exists()) {
            throw new InvalidArgumentException("Ya existe una actividad con la sigla {$b['sigla']}: use otra.");
        }

        return DB::transaction(function () use ($materia, $b) {
            $artefacto = Artefacto::query()->create([
                'materia_id' => $materia->id,
                'sigla' => $b['sigla'],
                'tipo' => $b['tipo'] ?? 'parcial',
                'titulo' => $b['titulo'],
                'configuracion' => array_merge([
                    'valor' => (string) $b['valor'],
                    'instrucciones' => null,
                    'reloj_minutos' => null,
                    'umbral_salidas' => 3,
                    'umbral_salidas_segundos' => 60,
                    'duracion_minima_minutos' => null,
                    'tiempo_ejecucion_ms' => 2000,
                ], $b['configuracion'] ?? []),
            ]);

            // 6.10.4: el «tema» de cada ítem se enlaza con el temario si coincide con un tema de la materia
            $temas = $materia->temario()->where('tipo', 'tema')->get()
                ->keyBy(fn ($t) => self::normalizarTema($t->titulo));

            foreach (array_values($b['items']) as $orden => $it) {
                [$enunciado, $clave] = $this->separar($it);
                $pregunta = Pregunta::query()->create([
                    'materia_id' => $materia->id,
                    'temario_item_id' => filled($it['tema'] ?? null) ? $temas->get(self::normalizarTema($it['tema']))?->id : null,
                    'tipo' => $it['tipo'],
                    'dificultad' => in_array($it['dificultad'] ?? null, ['baja', 'media', 'alta'], true) ? $it['dificultad'] : 'media',
                    'enunciado' => $enunciado,
                    'clave' => $clave,
                    'justificacion' => $it['justificacion'] ?? null,
                ]);
                ArtefactoPregunta::query()->create([
                    'artefacto_id' => $artefacto->id,
                    'pregunta_id' => $pregunta->id,
                    'peso' => (string) $it['peso'],
                    'orden' => $orden,
                ]);
            }

            return $artefacto;
        });
    }

    /** «Pilas y Colas » y «pilas y colas» son el mismo tema. */
    public static function normalizarTema(string $t): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', Str::ascii($t))));
    }

    /**
     * Lo que puede ver el estudiante y lo que solo ve el calificador.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function separar(array $it): array
    {
        $publico = ['texto' => $it['enunciado'], 'tema' => $it['tema'] ?? null];

        return match ($it['tipo']) {
            'unica' => [$publico + ['opciones' => $it['opciones']], ['correcta' => (string) $it['correcta']]],
            'vf' => [$publico, ['correcta' => (bool) $it['correcta']]],
            'multiple' => [$publico + ['opciones' => $it['opciones']],
                ['opciones' => array_map('strval', array_keys($it['opciones'])), 'correctas' => array_map('strval', $it['correctas'])]],
            'emparejar' => [$publico + ['izquierda' => $it['izquierda'], 'derecha' => $it['derecha']], ['pares' => $it['pares']]],
            'ordenar' => [$publico + ['pasos' => $it['pasos']], ['orden' => array_values($it['orden'])]],
            'completar' => [$publico + ['plantilla' => $it['plantilla'], 'huecos' => count($it['huecos'])], ['huecos' => array_values($it['huecos'])]],
            'abierta' => [$publico + ['lineas' => $it['lineas'] ?? 6], ['referencia' => $it['referencia'] ?? null, 'rubrica' => $it['rubrica'] ?? null]],
            'reto' => [$publico + [
                'plantilla_codigo' => $it['plantilla_codigo'] ?? '',
                'casos_visibles' => array_values($it['casos_visibles'] ?? []),
                'reservadas' => count(array_filter($it['verificaciones'], fn ($v) => $v['reservada'] ?? false)),
            ], ['verificaciones' => array_values($it['verificaciones'])]],
        };
    }

    /** @return array<int, string> */
    private function erroresDeTipo(string $tipo, array $it): array
    {
        $e = [];
        $opciones = is_array($it['opciones'] ?? null) ? array_map('strval', array_keys($it['opciones'])) : [];

        switch ($tipo) {
            case 'unica':
                if (count($opciones) < 2) {
                    $e[] = 'necesita al menos dos opciones.';
                } elseif (! in_array((string) ($it['correcta'] ?? ''), $opciones, true)) {
                    $e[] = 'la respuesta correcta no está entre las opciones (9.2.5).';
                }
                break;
            case 'vf':
                if (! is_bool($it['correcta'] ?? null)) {
                    $e[] = '«correcta» debe ser true o false.';
                }
                break;
            case 'multiple':
                $correctas = array_map('strval', (array) ($it['correctas'] ?? []));
                if (count($opciones) < 2 || $correctas === []) {
                    $e[] = 'necesita opciones y al menos una correcta.';
                } elseif (array_diff($correctas, $opciones) !== []) {
                    $e[] = 'hay respuestas correctas que no están entre las opciones (9.2.5).';
                }
                break;
            case 'emparejar':
                $izq = array_map('strval', array_keys((array) ($it['izquierda'] ?? [])));
                $der = array_map('strval', array_keys((array) ($it['derecha'] ?? [])));
                $pares = (array) ($it['pares'] ?? []);
                if ($izq === [] || array_diff(array_map('strval', array_keys($pares)), $izq) !== []
                    || array_diff(array_map('strval', array_values($pares)), $der) !== [] || count($pares) !== count($izq)) {
                    $e[] = 'cada elemento de «izquierda» debe tener su par en «derecha».';
                }
                break;
            case 'ordenar':
                $pasos = array_map('strval', array_keys((array) ($it['pasos'] ?? [])));
                $orden = array_map('strval', (array) ($it['orden'] ?? []));
                if (count($pasos) < 2 || count($orden) !== count($pasos) || array_diff($pasos, $orden) !== []) {
                    $e[] = '«orden» debe incluir exactamente una vez cada paso.';
                }
                break;
            case 'completar':
                $huecos = (array) ($it['huecos'] ?? []);
                if ($huecos === [] || substr_count((string) ($it['plantilla'] ?? ''), '___') !== count($huecos)) {
                    $e[] = 'la plantilla debe tener un «___» por cada hueco.';
                }
                break;
            case 'reto':
                $v = (array) ($it['verificaciones'] ?? []);
                if ($v === []) {
                    $e[] = 'necesita al menos una verificación.';
                    break;
                }
                $suma = BigDecimal::zero();
                $ids = [];
                foreach ($v as $ver) {
                    if (blank($ver['id'] ?? null) || blank($ver['nombre'] ?? null) || blank($ver['prueba'] ?? null)) {
                        $e[] = 'cada verificación necesita id, nombre legible y prueba.';
                        break 2;
                    }
                    $ids[] = $ver['id'];
                    try {
                        $suma = $suma->plus((string) ($ver['fraccion'] ?? ''));
                    } catch (MathException) {
                        $e[] = "la fracción de «{$ver['id']}» no es un número.";
                        break 2;
                    }
                }
                if (count(array_unique($ids)) !== count($ids)) {
                    $e[] = 'hay verificaciones con el mismo id.';
                }
                if (! $suma->isEqualTo(1)) {
                    $e[] = "las fracciones de las verificaciones suman {$suma} y deben sumar 1.";
                }
                break;
        }

        return $e;
    }
}
