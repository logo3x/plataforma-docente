<?php

namespace App\Evaluacion;

use App\Calificacion\RevisionEstatica;
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

    /** Versiones de un ítem (5.4.2): A y B en el primer banco; 2A y 2B en el segundo, para otra jornada. */
    public const VERSIONES = ['A', 'B', '2A', '2B'];

    /**
     * javascript: se ejecuta en un Worker; html: página con HTML, CSS y JavaScript en una vista previa aislada;
     * sql: consultas sobre una base SQLite en memoria creada con el «esquema» del reto;
     * php: PHP y Laravel, sin ejecución, revisados en el servidor con criterios estáticos (4.6).
     */
    public const LENGUAJES_RETO = ['javascript', 'html', 'sql', 'php'];

    /** Lo que el navegador del docente necesita para evaluar una verificación: prueba, o consulta de SQL con sus datos. */
    public static function criterio(array $v): array
    {
        return array_filter(['nombre' => $v['nombre'] ?? null, 'prueba' => $v['prueba'] ?? null, 'consulta' => $v['consulta'] ?? null,
            'orden' => (bool) ($v['orden'] ?? false), 'datos' => $v['datos'] ?? null], fn ($x) => $x !== null);
    }

    /** Archivos de un reto de PHP: [{nombre, plantilla}]; sin «archivos», uno solo con la plantilla del reto. */
    public static function archivosPhp(array $x): array
    {
        $a = array_values(array_filter((array) ($x['archivos'] ?? []), 'is_array'));

        return $a !== []
            ? array_map(fn ($f) => ['nombre' => (string) ($f['nombre'] ?? ''), 'plantilla' => (string) ($f['plantilla'] ?? '')], $a)
            : [['nombre' => 'codigo.php', 'plantilla' => (string) ($x['plantilla_codigo'] ?? '')]];
    }

    /** Lo que el examen necesita saber del lenguaje de un reto; nada para JavaScript, el de siempre. */
    private static function publicoDeLenguaje(array $x): array
    {
        return match ($x['lenguaje'] ?? 'javascript') {
            'html' => ['lenguaje' => 'html'],
            'sql' => ['lenguaje' => 'sql', 'esquema' => (string) ($x['esquema'] ?? '')],
            'php' => array_filter(['lenguaje' => 'php', 'archivos' => self::archivosPhp($x), 'alcance' => filled($x['alcance'] ?? null) ? (string) $x['alcance'] : null]),
            default => [],
        };
    }

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

        $sumas = [];
        $firmas = [];
        foreach (array_values($items) as $i => $it) {
            $n = $i + 1;
            $tipo = $it['tipo'] ?? null;
            $version = self::versionDe($it);
            if ($version === null) {
                $errores[] = "Ítem {$n}: la versión «{$it['version']}» no existe; use A, B, 2A o 2B.";

                continue;
            }
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
                $sumas[$version] = ($sumas[$version] ?? BigDecimal::zero())->plus($peso);
                $firmas[$version][] = $tipo.':'.$peso->strippedOfTrailingZeros();
            } catch (MathException|InvalidArgumentException) {
                $errores[] = "Ítem {$n}: el peso debe ser un número mayor que cero.";
            }
            foreach ($this->erroresDeTipo($tipo, $it) as $e) {
                $errores[] = "Ítem {$n} ({$tipo}): {$e}";
            }
        }

        try {
            foreach ($sumas as $version => $suma) {
                if (! $suma->isEqualTo((string) ($b['valor'] ?? '0'))) {
                    $errores[] = (count($sumas) > 1 ? "Los pesos de la versión {$version} suman {$suma}" : "Los pesos suman {$suma}")
                        ." y el valor de la actividad es {$b['valor']}: deben coincidir (9.2.4).";
                }
            }
        } catch (MathException) {
            $errores[] = 'El valor de la actividad no es un número.';
        }

        // 5.4.2: las versiones de un banco tienen los mismos tipos de ítem y los mismos valores
        foreach ([['A', 'B'], ['2A', '2B']] as [$x, $y]) {
            if (isset($firmas[$y]) && ! isset($firmas[$x])) {
                $errores[] = "Hay ítems de la versión {$y} pero ninguno de la {$x}.";
            } elseif (isset($firmas[$x], $firmas[$y])) {
                [$fx, $fy] = [$firmas[$x], $firmas[$y]];
                sort($fx);
                sort($fy);
                if ($fx !== $fy) {
                    $errores[] = "Las versiones {$x} y {$y} deben tener los mismos tipos de ítem con los mismos pesos (".count($firmas[$x]).' frente a '.count($firmas[$y]).' ítems).';
                }
            }
        }
        if (isset($firmas['2A']) && ! isset($firmas['A'])) {
            $errores[] = 'Hay un segundo banco (2A) pero no el primero (A).';
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
                    'banco' => self::versionDe($it),
                    'peso' => (string) $it['peso'],
                    'orden' => $orden,
                ]);
            }

            return $artefacto;
        });
    }

    /** De qué versión sale otra (5.4.2): B de A; el segundo banco, 2A de A y 2B de B (o de A si no hay B). */
    public static function origenDe(Artefacto $a, string $version): string
    {
        return match ($version) {
            'B', '2A' => 'A',
            '2B' => in_array('B', $a->versiones(), true) ? 'B' : 'A',
            default => throw new InvalidArgumentException("No se genera la versión {$version}."),
        };
    }

    /** El ítem guardado de vuelta en formato «plataforma-banco», para mostrarlo o derivar otra versión. */
    public static function aBanco(ArtefactoPregunta $ap): array
    {
        $p = $ap->pregunta;
        $e = $p->enunciado;
        $c = $p->clave;
        $it = ['tipo' => $p->tipo, 'version' => $ap->banco, 'peso' => (string) BigDecimal::of((string) $ap->peso)->strippedOfTrailingZeros(),
            'tema' => $e['tema'] ?? null, 'dificultad' => $p->dificultad, 'enunciado' => $e['texto'] ?? ''];

        return array_filter($it + match ($p->tipo) {
            'unica' => ['opciones' => $e['opciones'], 'correcta' => $c['correcta']],
            'vf' => ['correcta' => (bool) $c['correcta']],
            'multiple' => ['opciones' => $e['opciones'], 'correctas' => $c['correctas']],
            'emparejar' => ['izquierda' => $e['izquierda'], 'derecha' => $e['derecha'], 'pares' => $c['pares']],
            'ordenar' => ['pasos' => $e['pasos'], 'orden' => $c['orden']],
            'completar' => ['plantilla' => $e['plantilla'], 'huecos' => $c['huecos']],
            'abierta' => ['lineas' => $e['lineas'] ?? 6, 'referencia' => $c['referencia'] ?? null, 'rubrica' => $c['rubrica'] ?? null],
            'reto' => self::publicoDeLenguaje($e) + ['plantilla_codigo' => $e['plantilla_codigo'] ?? '', 'casos_visibles' => $e['casos_visibles'] ?? [], 'verificaciones' => $c['verificaciones']],
            default => [],
        } + ['justificacion' => $p->justificacion], fn ($v) => $v !== null);
    }

    /**
     * Revisa una versión nueva para un examen ya importado: cada ítem válido, la suma igual al valor
     * y los mismos tipos con los mismos pesos que la versión de la que sale.
     *
     * @return array<int, string>
     */
    public function validarVersion(Artefacto $a, array $items, string $version): array
    {
        if (! in_array($version, ['B', '2A', '2B'], true)) {
            return ["No se genera la versión {$version}."];
        }
        if (in_array($version, $a->versiones(), true)) {
            return ["El examen ya tiene la versión {$version}."];
        }
        if ($a->actividades()->whereHas('entregas')->exists()) {
            return ['El examen ya tiene entregas: una versión nueva cambiaría a qué estudiante le toca cada una. Hágala sobre una copia del examen.'];
        }
        $items = array_values(array_filter($items, 'is_array'));
        $errores = $this->validar(['formato' => self::FORMATO, 'version' => 1, 'sigla' => $a->sigla, 'titulo' => $a->titulo,
            'valor' => (string) ($a->configuracion['valor'] ?? '0'), 'items' => array_map(fn ($it) => ['version' => 'A'] + $it, $items)]);

        // Un reto de HTML sigue siendo de HTML en la otra versión
        $firma = fn (iterable $xs) => collect($xs)->map(fn ($x) => ($x['tipo'] ?? '?').(in_array($x['lenguaje'] ?? null, ['html', 'sql', 'php'], true) ? ' de '.strtoupper($x['lenguaje']) : '').':'.(is_numeric($x['peso'] ?? null) ? BigDecimal::of((string) $x['peso'])->strippedOfTrailingZeros() : '?'))->sort()->values()->all();
        $origen = self::origenDe($a, $version);
        $base = $a->items()->with('pregunta')->where('banco', $origen)->get()->map(fn ($ap) => ['tipo' => $ap->pregunta->tipo, 'lenguaje' => $ap->pregunta->enunciado['lenguaje'] ?? null, 'peso' => (string) $ap->peso]);
        if ($firma($items) !== $firma($base)) {
            $errores[] = "La versión {$version} debe tener los mismos tipos de ítem con los mismos pesos que la {$origen} ("
                .count($items).' frente a '.$base->count().' ítems).';
        }

        return $errores;
    }

    /** Agrega la versión nueva al examen, después de validarla. */
    public function agregarVersion(Artefacto $a, array $items, string $version): int
    {
        if ($e = $this->validarVersion($a, $items, $version)) {
            throw new InvalidArgumentException(implode("\n", $e));
        }
        $temas = $a->materia->temario()->where('tipo', 'tema')->get()->keyBy(fn ($t) => self::normalizarTema($t->titulo));
        $orden = (int) $a->items()->max('orden');

        return DB::transaction(function () use ($a, $items, $version, $temas, $orden) {
            foreach (array_values($items) as $i => $it) {
                [$enunciado, $clave] = $this->separar($it);
                $pregunta = Pregunta::query()->create([
                    'materia_id' => $a->materia_id,
                    'temario_item_id' => filled($it['tema'] ?? null) ? $temas->get(self::normalizarTema($it['tema']))?->id : null,
                    'tipo' => $it['tipo'],
                    'dificultad' => in_array($it['dificultad'] ?? null, ['baja', 'media', 'alta'], true) ? $it['dificultad'] : 'media',
                    'enunciado' => $enunciado, 'clave' => $clave, 'justificacion' => $it['justificacion'] ?? null,
                ]);
                ArtefactoPregunta::query()->create(['artefacto_id' => $a->id, 'pregunta_id' => $pregunta->id, 'banco' => $version,
                    'peso' => (string) $it['peso'], 'orden' => $orden + 1 + $i]);
            }

            return count($items);
        });
    }

    /** Versión del ítem; sin indicar, A. Null si no es válida. */
    public static function versionDe(array $it): ?string
    {
        $v = strtoupper(trim((string) ($it['version'] ?? 'A')));

        return in_array($v, self::VERSIONES, true) ? $v : null;
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
    public function separar(array $it): array
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
            'reto' => [$publico + self::publicoDeLenguaje($it) + [
                'plantilla_codigo' => $it['plantilla_codigo'] ?? '',
                'casos_visibles' => array_values($it['casos_visibles'] ?? []),
                'reservadas' => count(array_filter($it['verificaciones'], fn ($v) => $v['reservada'] ?? false)),
            ] + (($it['lenguaje'] ?? null) === 'php' ? [
                // Revisión estática: el estudiante sabe qué se va a revisar (4.6), sin ver cómo
                'criterios_visibles' => array_values(array_map(fn ($v) => $v['nombre'], array_filter($it['verificaciones'], fn ($v) => empty($v['reservada'])))),
            ] : []), ['verificaciones' => array_values($it['verificaciones'])] + (($it['lenguaje'] ?? null) === 'php' ? [
                'lenguaje' => 'php',
                'archivos' => array_column(self::archivosPhp($it), 'nombre'),
                'alcance' => filled($it['alcance'] ?? null) ? (string) $it['alcance'] : null,
            ] : [])],
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
                $lenguaje = $it['lenguaje'] ?? 'javascript';
                if (! in_array($lenguaje, self::LENGUAJES_RETO, true)) {
                    $e[] = '«lenguaje» debe ser «javascript», «html», «sql» o «php».';
                }
                if ($lenguaje === 'php') {
                    $nombres = array_column(self::archivosPhp($it), 'nombre');
                    if (count($nombres) > 6 || in_array('', $nombres, true) || count(array_unique($nombres)) !== count($nombres)) {
                        $e[] = '«archivos» admite hasta 6, cada uno con un «nombre» distinto (por ejemplo routes/web.php).';
                    }
                }
                if ($lenguaje === 'sql' && (! is_string($it['esquema'] ?? null) || trim($it['esquema']) === '')) {
                    $e[] = 'un reto de SQL necesita «esquema»: las instrucciones que crean y llenan sus tablas.';
                }
                $v = (array) ($it['verificaciones'] ?? []);
                if ($v === []) {
                    $e[] = 'necesita al menos una verificación.';
                    break;
                }
                $suma = BigDecimal::zero();
                $ids = [];
                foreach ($v as $ver) {
                    // En SQL se compara con una «consulta» de referencia o se evalúa una «prueba» que da 1
                    // En PHP, un solo criterio estático: «patron», «contiene» o «sintaxis»
                    $criterio = match ($lenguaje) {
                        'sql' => filled($ver['prueba'] ?? null) || filled($ver['consulta'] ?? null),
                        'php' => count(array_filter([filled($ver['patron'] ?? null), filled($ver['contiene'] ?? null), ! empty($ver['sintaxis'])])) === 1,
                        default => filled($ver['prueba'] ?? null),
                    };
                    if (blank($ver['id'] ?? null) || blank($ver['nombre'] ?? null) || ! $criterio) {
                        $e[] = match ($lenguaje) {
                            'sql' => 'cada verificación necesita id, nombre legible y una «consulta» de referencia o una «prueba».',
                            'php' => 'cada verificación necesita id, nombre legible y un solo criterio: «patron», «contiene» o «sintaxis».',
                            default => 'cada verificación necesita id, nombre legible y prueba.',
                        };
                        break 2;
                    }
                    if ($lenguaje === 'php' && filled($ver['patron'] ?? null) && ! RevisionEstatica::patronValido((string) $ver['patron'])) {
                        $e[] = "el patrón de «{$ver['id']}» no es una expresión regular válida.";
                    }
                    if ($lenguaje === 'php' && isset($ver['archivo']) && ! in_array($ver['archivo'], array_column(self::archivosPhp($it), 'nombre'), true)) {
                        $e[] = "«{$ver['id']}» revisa el archivo «{$ver['archivo']}», que el reto no tiene.";
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
