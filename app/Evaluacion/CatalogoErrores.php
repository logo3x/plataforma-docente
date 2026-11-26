<?php

namespace App\Evaluacion;

use App\Models\Entrega;
use App\Models\ErrorFrecuente;
use App\Models\Materia;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de errores frecuentes por lenguaje (4.4.4, anexo A), mantenido por el docente.
 *
 * Una entrada relaciona un mensaje del motor con su explicación y qué revisar. El patrón es un texto en el
 * que {x} representa un nombre («{x} is not defined»), o una expresión regular. La comparación es la misma
 * aquí y en el examen (examen.js, explicar): sin distinguir mayúsculas, la primera entrada que coincide gana,
 * y las de una materia van antes que las generales; dentro de cada grupo, los patrones más largos primero.
 */
final class CatalogoErrores
{
    public const LENGUAJES = ['javascript' => 'JavaScript', 'html' => 'HTML y CSS', 'sql' => 'SQL', 'php' => 'PHP y Laravel'];

    /** {x}: un nombre, sin espacios ni comillas. */
    public const COMODIN = '([^\s"\'«»]+)';

    /** Base inicial: el anexo A y lo que antes estaba fijo en el código del examen. */
    public const BASE = [
        ['javascript', 'Unexpected end of input', 'El código terminó con algo sin cerrar.', 'Cuente las llaves y los paréntesis de apertura y de cierre.'],
        ['javascript', 'Unexpected token', 'Hay un símbolo donde no se esperaba.', 'Paréntesis, llaves o comas de la línea señalada y de la anterior.'],
        ['javascript', '{x} is not defined', 'Se usa «{x}», un nombre que no existe todavía.', 'La ortografía del nombre y que esté declarado antes de usarse.'],
        ['javascript', '{x} is not a function', 'Se intenta llamar a «{x}», que no es una función.', 'La ortografía del método y el tipo del valor sobre el que se llama.'],
        ['javascript', 'Cannot read properties of {x}', 'Se pide un dato de algo que es {x}: no existe.', 'El elemento o la clave que se busca; suele ser un selector que no encontró nada.'],
        ['javascript', 'Assignment to constant variable', 'Se reasigna algo declarado como constante.', 'Use let si el valor debe cambiar.'],
        ['javascript', 'Missing initializer in const declaration', 'Una constante se declaró sin valor.', 'Las constantes se inicializan al declararse.'],
        ['javascript', 'Maximum call stack size exceeded', 'Una función se llama a sí misma sin parar.', 'El caso base de la recursión: la condición que la detiene.'],
        ['html', '> y no se cerró', 'Una etiqueta quedó sin cierre: el navegador intenta adivinar y el resultado se desordena.', 'Cada etiqueta de apertura con su cierre, en el mismo orden.'],
        ['html', 'no se cerró con «>» o tiene comillas sin cerrar', 'Un atributo quedó sin comillas de cierre: todo lo que sigue se toma como parte del atributo.', 'Las comillas de apertura y de cierre de cada atributo.'],
        ['html', 'pero lo último abierto es', 'Las etiquetas se cierran en un orden distinto al que se abrieron (anidamiento cruzado).', 'Cierre primero la última que abrió.'],
        ['html', 'sobra </{x}', 'Se cierra una etiqueta que no está abierta.', 'Si sobra ese cierre o si falta su apertura más arriba.'],
        ['php', 'syntax error, unexpected end of file', 'Falta cerrar una llave o un paréntesis.', 'Cuente las llaves de la función o del bloque.'],
        ['php', 'Undefined variable ${x}', 'Se usa la variable ${x}, que la vista no recibió.', 'Que la ruta o el controlador la envíe a la vista.'],
        ['php', 'Undefined array key {x}', 'Se pide la clave {x}, que el arreglo no tiene.', 'La ortografía de la clave contra la definición del arreglo.'],
        ['php', 'View [{x}] not found', 'El nombre de la vista no coincide con el archivo.', 'El nombre del archivo en resources/views y el que se usa al retornar la vista.'],
        ['php', 'Route [{x}] not defined', 'Se genera un enlace con un nombre que ninguna ruta tiene.', 'Que la ruta tenga ->name(…) y que no haya error de escritura.'],
        ['php', 'Page Expired', 'Falta el token de seguridad en el formulario.', 'Que @csrf esté dentro del formulario.'],
        ['php', 'Class "{x}" not found', 'Falta importar la clase {x}.', 'La línea use al inicio del archivo.'],
        ['php', 'no tiene su @', 'Una directiva de plantilla quedó abierta.', 'Cada directiva con su pareja exacta de cierre (@if con @endif, @foreach con @endforeach).'],
        ['php', 'cierra un @', 'Las directivas se cierran en un orden distinto al que se abrieron.', 'Cierre primero la última directiva que abrió.'],
        ['sql', 'no such table: {x}', 'La tabla «{x}» no existe con ese nombre.', 'El nombre de la tabla; las tablas de la actividad se ven con «Ver tablas».'],
        ['sql', 'no such column: {x}', 'La columna «{x}» no existe en las tablas de la consulta.', 'El nombre de la columna y de qué tabla proviene (el alias).'],
        ['sql', 'near "{x}": syntax error', 'Falta o sobra una palabra clave cerca de «{x}».', 'El orden de las cláusulas (SELECT … FROM … WHERE … GROUP BY … HAVING … ORDER BY) y las comas de la lista de columnas.'],
        ['sql', 'incomplete input', 'La instrucción quedó incompleta.', 'Un paréntesis o unas comillas sin cerrar, o una cláusula sin terminar.'],
        ['sql', 'ambiguous column name: {x}', 'La columna «{x}» está en más de una tabla.', 'Indique de qué tabla es: tabla.{x} o alias.{x}.'],
        ['sql', 'misuse of aggregate', 'Una función de agregado (COUNT, SUM, AVG…) está donde no se puede usar.', 'Para filtrar por un agregado use HAVING, no WHERE.'],
        ['sql', 'a GROUP BY clause is required before HAVING', 'HAVING filtra grupos y no hay grupos.', 'Agregue el GROUP BY antes del HAVING.'],
        ['sql', 'no such function: {x}', 'La función «{x}» no existe en SQLite.', 'El nombre de la función.'],
        ['sql', 'UNIQUE constraint failed: {x}', 'Se repite un valor que debe ser único en «{x}».', 'Los valores que inserta o actualiza en esa columna.'],
        ['sql', 'NOT NULL constraint failed: {x}', 'Falta un valor obligatorio en «{x}».', 'Que el INSERT dé un valor a esa columna.'],
        ['sql', 'FOREIGN KEY constraint failed', 'El valor no existe en la tabla relacionada.', 'Que exista primero el registro al que apunta la llave foránea.'],
    ];

    /** La primera vez, el catálogo del docente se llena con la base. Si después lo vacía, no se vuelve a llenar solo. */
    public function asegurarBase(User $docente): void
    {
        if ($docente->catalogo_errores_en !== null) {
            return;
        }
        DB::transaction(function () use ($docente) {
            $this->restaurarBase($docente);
            $docente->forceFill(['catalogo_errores_en' => now()])->save();
        });
    }

    /** Agrega las entradas base que falten (por patrón y lenguaje). Devuelve cuántas agregó. */
    public function restaurarBase(User $docente): int
    {
        $hay = $docente->erroresFrecuentes()->get(['lenguaje', 'patron'])->map(fn ($e) => $e->lenguaje.'|'.$e->patron)->flip();
        $n = 0;
        foreach (self::BASE as [$lenguaje, $patron, $explicacion, $revision]) {
            if (! isset($hay[$lenguaje.'|'.$patron])) {
                $docente->erroresFrecuentes()->create(compact('lenguaje', 'patron', 'explicacion', 'revision') + ['base' => true]);
                $n++;
            }
        }

        return $n;
    }

    /** Entradas activas que aplican a una materia, en el orden en que se comparan. */
    public function vigentes(User $docente, ?Materia $materia, ?array $lenguajes = null): Collection
    {
        $this->asegurarBase($docente);

        return $docente->erroresFrecuentes()->where('activo', true)
            ->where(fn ($q) => $q->whereNull('materia_id')->when($materia, fn ($q) => $q->orWhere('materia_id', $materia->id)))
            ->when($lenguajes, fn ($q) => $q->whereIn('lenguaje', $lenguajes))
            ->get()
            ->sortBy(fn (ErrorFrecuente $e) => [$e->materia_id === null ? 1 : 0, -mb_strlen($e->patron), $e->id])->values();
    }

    /** Lo que viaja en el examen: sin datos del docente, solo lo necesario para explicar. */
    public function paraExamen(User $docente, Materia $materia, array $lenguajes): array
    {
        return $this->vigentes($docente, $materia, $lenguajes)
            ->map(fn (ErrorFrecuente $e) => ['id' => $e->id, 'lenguaje' => $e->lenguaje, 'patron' => $e->patron, 'regex' => $e->es_regex,
                'explicacion' => $e->explicacion, 'revision' => $e->revision])->all();
    }

    public static function regex(ErrorFrecuente|array $e): string
    {
        $patron = is_array($e) ? $e['patron'] : $e->patron;
        $esRegex = is_array($e) ? ! empty($e['regex']) : $e->es_regex;

        return '~'.($esRegex ? str_replace('~', '\~', $patron) : str_replace('\{x\}', self::COMODIN, preg_quote($patron, '~'))).'~iu';
    }

    public static function regexValida(string $patron): bool
    {
        return @preg_match('~'.str_replace('~', '\~', $patron).'~iu', '') !== false;
    }

    /**
     * Explicación para un mensaje, como la ve el estudiante en el nivel de ayuda completo.
     *
     * @param  Collection<int, ErrorFrecuente>  $entradas  en orden de comparación
     * @return array{entrada: ErrorFrecuente, texto: string}|null
     */
    public function explicar(Collection $entradas, array $lenguajes, string $mensaje): ?array
    {
        foreach ($entradas as $e) {
            if (in_array($e->lenguaje, $lenguajes, true) && preg_match(self::regex($e), $mensaje, $m)) {
                $x = $m[1] ?? '';
                $texto = str_replace('{x}', $x, $e->explicacion).($e->revision ? ' Qué revisar: '.str_replace('{x}', $x, $e->revision) : '');

                return ['entrada' => $e, 'texto' => $texto];
            }
        }

        return null;
    }

    /**
     * Lo que pasó en el aula según la sesión de cada entrega de las materias del docente: cuántas veces se
     * mostró cada entrada y qué mensajes aparecieron sin explicación (para ampliar el catálogo).
     *
     * @return array{usos: array<int, int>, sin_explicar: Collection}
     */
    public function enElAula(User $docente): array
    {
        $usos = [];
        $sin = [];
        Entrega::query()->where('tipo', 'examen')->whereNotNull('sesion')
            ->whereHas('actividad.componente.division.dictado.materia', fn ($q) => $q->where('user_id', $docente->id))
            ->select(['id', 'sesion'])->chunkById(200, function ($entregas) use (&$usos, &$sin) {
                foreach ($entregas as $e) {
                    foreach ((array) ($e->sesion['catalogo'] ?? []) as $id => $n) {
                        $usos[(int) $id] = ($usos[(int) $id] ?? 0) + (int) $n;
                    }
                    foreach ((array) ($e->sesion['sin_catalogo'] ?? []) as $s) {
                        if (! is_array($s) || ! is_string($s['mensaje'] ?? null) || ! isset(self::LENGUAJES[$s['lenguaje'] ?? ''])) {
                            continue;
                        }
                        $k = $s['lenguaje'].'|'.mb_substr($s['mensaje'], 0, 300);
                        $sin[$k] ??= ['lenguaje' => $s['lenguaje'], 'mensaje' => mb_substr($s['mensaje'], 0, 300), 'veces' => 0, 'entregas' => 0];
                        $sin[$k]['veces'] += max(1, (int) ($s['veces'] ?? 1));
                        $sin[$k]['entregas']++;
                    }
                }
            });

        // Un mensaje que ya tiene entrada (porque el docente la agregó después) deja de estar pendiente
        $entradas = $docente->erroresFrecuentes()->where('activo', true)->get();
        $pendientes = collect($sin)->reject(fn ($s) => $this->explicar($entradas, [$s['lenguaje']], $s['mensaje']) !== null)
            ->sortByDesc('entregas')->values();

        return ['usos' => $usos, 'sin_explicar' => $pendientes];
    }

    /** Patrón sugerido para un mensaje nuevo: lo que va entre comillas se vuelve {x}. */
    public static function sugerirPatron(string $mensaje): string
    {
        $m = preg_replace('/^Línea \d+:\s*/u', '', trim($mensaje));
        $m = preg_replace('/^(Uncaught\s+)?\w*Error:\s*/', '', $m);

        return preg_replace(['/"[^"]+"/', "/'[^']+'/", '/«[^»]+»/u'], ['"{x}"', "'{x}'", '«{x}»'], $m);
    }
}
