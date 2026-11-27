<?php

namespace App\Material;

use App\Models\Generador;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Catálogo de generadores de documentos (7.4.3): los de fábrica (guía de laboratorio, manual interactivo
 * y boletín, 7.5 y 7.10) y los que crea el docente. Un generador es configuración: nombre, campos propios
 * e instrucciones en lenguaje natural con {{campo}}; la salida es siempre un documento por secciones, así
 * que no hace falta código nuevo ni saber JSON. Clave «f:guia» para los de fábrica y «g:12» para los propios.
 */
final class Generadores
{
    /** Qué código lleva el documento. */
    public const CODIGO = [
        'no' => 'Sin código',
        'ejemplos' => 'Con ejemplos de código cuando ayuden',
        'interactivo' => 'Interactivo: cada sección con un ejemplo que se ejecuta y un ejercicio que se comprueba solo',
    ];

    /** Variables que la plataforma llena sola y no pueden usarse como campos. */
    public const RESERVADAS = ['tema', 'grupo', 'memoria', 'docente', 'nota_minima', 'nota_maxima', 'nota_aprobatoria', 'pesos_cortes'];

    public const FABRICA = [
        'guia' => [
            'nombre' => 'Guía de laboratorio',
            'descripcion' => 'Práctica guiada para hacer en clase o en casa: objetivo, requisitos, pasos, verificación y entregables.',
            'codigo' => 'ejemplos',
            'campos' => [
                ['clave' => 'duracion', 'etiqueta' => 'Duración de la práctica', 'defecto' => '2 horas'],
                ['clave' => 'herramientas', 'etiqueta' => 'Herramientas que usa', 'defecto' => 'navegador y editor de código'],
                ['clave' => 'entregable', 'etiqueta' => 'Qué entrega el estudiante', 'defecto' => 'el código y un informe corto con capturas'],
            ],
            'instrucciones' => "Escriba una guía de laboratorio de {{duracion}} sobre el tema.\n"
                .'Secciones, en este orden: Objetivo; Requisitos (lo que debe saber y tener instalado: {{herramientas}}); '
                .'Procedimiento (pasos numerados, cada uno con lo que se hace y lo que se debe ver); Verificación (cómo sabe el estudiante que lo logró); '
                ."Entregables ({{entregable}}); Preguntas de cierre.\n"
                .'Cada paso debe poder seguirse sin el docente al lado.',
        ],
        'manual' => [
            'nombre' => 'Manual interactivo',
            'descripcion' => 'Tutorial paso a paso con ejemplos que se ejecutan y ejercicios que se comprueban solos, en el entorno de prueba de la plataforma.',
            'codigo' => 'interactivo',
            'campos' => [
                ['clave' => 'pasos', 'etiqueta' => 'Cuántos pasos', 'defecto' => '5'],
                ['clave' => 'punto_de_partida', 'etiqueta' => 'Qué sabe ya el estudiante', 'defecto' => 'variables, condicionales y ciclos'],
            ],
            'instrucciones' => "Escriba un manual interactivo de {{pasos}} pasos sobre el tema, para un estudiante que ya sabe {{punto_de_partida}}.\n"
                .'Cada paso es una sección: explica una sola idea, la muestra con un ejemplo corto que se ejecuta tal cual y termina con un ejercicio pequeño '
                .'que practica justo esa idea. La dificultad sube de a poco.',
        ],
        'boletin' => [
            'nombre' => 'Boletín',
            'descripcion' => 'Boletín para el curso: novedades, recordatorios de fechas, un tema destacado y recursos recomendados.',
            'codigo' => 'no',
            'campos' => [
                ['clave' => 'publico', 'etiqueta' => 'Para quién', 'defecto' => 'los estudiantes del curso'],
                ['clave' => 'novedades', 'etiqueta' => 'Novedades y fechas que debe incluir', 'defecto' => ''],
            ],
            'instrucciones' => "Escriba un boletín corto para {{publico}}.\n"
                .'Secciones: Novedades (incluya exactamente estas, sin inventar fechas: {{novedades}}); Tema destacado (explicado en pocas líneas y con un ejemplo cotidiano); '
                ."Recursos recomendados (solo del material de referencia); Para la próxima clase.\n"
                .'Tono cercano y frases cortas.',
        ],
    ];

    /** @return array<string, array{clave: string, nombre: string, descripcion: string, codigo: string, campos: array, instrucciones: string, fabrica: bool, funciono: bool}> */
    public function disponibles(User $docente): array
    {
        $r = [];
        foreach (self::FABRICA as $k => $g) {
            $r["f:{$k}"] = $g + ['clave' => "f:{$k}", 'fabrica' => true, 'funciono' => false, 'modelo' => null];
        }
        foreach (Generador::query()->where('user_id', $docente->id)->orderByDesc('funciono')->orderBy('nombre')->get() as $g) {
            $r["g:{$g->id}"] = $this->definicion($g);
        }

        return $r;
    }

    public function definicion(Generador $g): array
    {
        return ['clave' => "g:{$g->id}", 'nombre' => $g->nombre, 'descripcion' => (string) $g->descripcion, 'codigo' => $g->codigo,
            'campos' => $g->campos, 'instrucciones' => $g->instrucciones, 'fabrica' => false, 'funciono' => $g->funciono, 'modelo' => $g];
    }

    public function resolver(User $docente, string $clave): array
    {
        return $this->disponibles($docente)[$clave] ?? throw new InvalidArgumentException('Ese generador no existe o no es suyo.');
    }

    /** Las instrucciones con los campos llenos; un campo vacío sin valor por defecto detiene el armado (8.4.1). */
    public function instrucciones(array $def, array $valores): string
    {
        $v = [];
        foreach ($def['campos'] as $c) {
            $v[$c['clave']] = trim((string) ($valores[$c['clave']] ?? '')) !== '' ? trim((string) $valores[$c['clave']]) : trim((string) ($c['defecto'] ?? ''));
            if ($v[$c['clave']] === '') {
                throw new InvalidArgumentException("Falta «{$c['etiqueta']}».");
            }
        }

        // Lo que llena la plataforma ya va aparte en el prompt (tema, grupo): aquí se nombra
        $v += ['tema' => 'el tema indicado', 'grupo' => 'el grupo indicado', 'memoria' => ''];

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => $v[$m[1]] ?? $m[0], $def['instrucciones']);
    }

    /**
     * Valida un generador del docente. Los campos se escriben uno por línea: «clave | Etiqueta | valor por defecto».
     *
     * @return array{0: array<int, array{clave: string, etiqueta: string, defecto: string}>, 1: array<int, string>} campos y errores
     */
    public function leerCampos(string $texto, string $instrucciones): array
    {
        $campos = [];
        $errores = [];
        foreach (preg_split('/\R/', trim($texto)) as $n => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $linea));
            $clave = Str::snake(Str::ascii($p[0]));
            if (! preg_match('/^[a-z][a-z0-9_]{0,30}$/', $clave)) {
                $errores[] = 'Línea '.($n + 1).': la clave «'.$p[0].'» debe ser una palabra sin espacios.';

                continue;
            }
            if (in_array($clave, self::RESERVADAS, true)) {
                $errores[] = "«{$clave}» lo llena la plataforma: use otro nombre de campo.";
            }
            $campos[$clave] = ['clave' => $clave, 'etiqueta' => ($p[1] ?? '') !== '' ? $p[1] : Str::ucfirst(str_replace('_', ' ', $clave)), 'defecto' => $p[2] ?? ''];
        }
        if (count($campos) > 10) {
            $errores[] = 'Un generador admite hasta 10 campos.';
        }
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $instrucciones, $m);
        foreach (array_unique($m[1]) as $usado) {
            if (! isset($campos[$usado]) && ! in_array($usado, self::RESERVADAS, true)) {
                $errores[] = "Las instrucciones usan {{{$usado}}}, que no está entre los campos.";
            }
        }

        return [array_values($campos), $errores];
    }

    public static function camposComoTexto(array $campos): string
    {
        return implode("\n", array_map(fn ($c) => "{$c['clave']} | {$c['etiqueta']}".(($c['defecto'] ?? '') !== '' ? " | {$c['defecto']}" : ''), $campos));
    }
}
