<?php

namespace App\Notas;

use App\Archivos\LectorTabla;
use App\Models\Actividad;
use App\Models\Componente;
use App\Models\Estudiante;
use App\Models\Grupo;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Carga de notas desde un archivo que crea una columna nueva enlazada a cada estudiante (3.1.5).
 *
 *  - Cruce por documento como método principal; por apellidos y nombres COMPLETOS como respaldo.
 *  - Nunca por apellido suelto: empareja a personas distintas.
 *  - Respeta la columna de grupo del archivo cuando existe.
 *  - Convierte la escala del archivo (0–5, 0–10, 0–50, 0–100) a la del dictado.
 *  - Vista previa obligatoria; nada se escribe hasta confirmar.
 */
final class ImportadorNotas
{
    public const ESCALAS_ORIGEN = ['5', '10', '50', '100'];

    /** Sinónimos de encabezado (ya normalizados) para la detección automática. */
    private const SINONIMOS = [
        'documento' => ['numero_documento', 'numero_de_documento', 'documento', 'cedula', 'identificacion', 'doc', 'nro_documento', 'num_documento', 'id_estudiante'],
        'nombre' => ['nombre_alumno', 'nombre_completo', 'nombre_del_alumno', 'nombre_del_estudiante', 'nombre_estudiante', 'nombre', 'estudiante', 'alumno', 'apellidos_y_nombres', 'nombres_y_apellidos'],
        'apellidos' => ['apellidos', 'apellido'],
        'nombres' => ['nombres'],
        'nota' => ['nota', 'calificacion', 'definitiva', 'nota_final', 'puntaje', 'valor', 'total', 'resultado', 'score', 'grade'],
        'grupo' => ['grupo', 'jornada', 'curso', 'seccion'],
    ];

    public function __construct(private readonly LectorTabla $lector) {}

    /**
     * @return array{encabezados: array<int, string>, filas: array<int, array<int, string>>}
     */
    public function leer(string $ruta, string $extension): array
    {
        $tabla = $this->lector->leer($ruta, $extension);
        if ($tabla['filas'] === []) {
            throw new InvalidArgumentException('El archivo no tiene filas de datos.');
        }

        return $tabla;
    }

    /**
     * Índice de columna sugerido para cada campo, o null si no se reconoce.
     *
     * @param  array<int, string>  $encabezados
     * @return array{documento:?int, nombre:?int, apellidos:?int, nombres:?int, nota:?int, grupo:?int}
     */
    public function detectarColumnas(array $encabezados): array
    {
        $norm = array_map([LectorTabla::class, 'normalizarEncabezado'], $encabezados);
        $mapa = [];

        foreach (self::SINONIMOS as $campo => $sinonimos) {
            $mapa[$campo] = null;
            foreach ($sinonimos as $s) {
                $i = array_search($s, $norm, true);
                if ($i !== false && ! in_array($i, $mapa, true)) {
                    $mapa[$campo] = $i;
                    break;
                }
            }
        }

        return $mapa;
    }

    /**
     * Escala de origen sugerida. Solo se propone otra escala si la MAYORÍA de las notas supera
     * el máximo del dictado: un valor aislado fuera de rango (un 7 mal digitado) no debe hacer
     * que se conviertan todas las notas; se reporta como fuera de rango.
     *
     * @param  array<int, array<int, string>>  $filas
     */
    public function detectarEscala(array $filas, ?int $columnaNota, Escala $destino): string
    {
        $propia = (string) BigDecimal::of($destino->maximo)->strippedOfTrailingZeros();
        $valores = array_values(array_filter(
            array_map(fn ($f) => $this->numero($f[$columnaNota] ?? ''), $columnaNota === null ? [] : $filas)
        ));
        $encima = array_filter($valores, fn (BigDecimal $v) => $v->isGreaterThan($destino->maximo));

        if ($valores === [] || count($encima) * 2 <= count($valores)) {
            return $propia;
        }

        $maximo = array_reduce($valores, fn ($m, BigDecimal $v) => $v->isGreaterThan($m) ? $v : $m, BigDecimal::zero());
        foreach (self::ESCALAS_ORIGEN as $e) {
            if ($maximo->isLessThanOrEqualTo($e)) {
                return $e;
            }
        }

        return '100';
    }

    /**
     * Analiza sin escribir nada.
     *
     * @param  array<int, array<int, string>>  $filas
     * @param  array{documento:?int, nombre:?int, apellidos:?int, nombres:?int, nota:?int, grupo:?int}  $columnas
     * @return array{filas: array<int, array<string, mixed>>, sin_nota: array<int, Estudiante>, resumen: array<string, int>}
     */
    public function analizar(Grupo $grupo, array $filas, array $columnas, string $escalaOrigen): array
    {
        if ($columnas['nota'] === null) {
            throw new InvalidArgumentException('Indique qué columna trae la nota.');
        }
        if ($columnas['documento'] === null && $columnas['nombre'] === null
            && ($columnas['apellidos'] === null || $columnas['nombres'] === null)) {
            throw new InvalidArgumentException('Indique la columna del documento o la del nombre completo del estudiante.');
        }

        $escala = $grupo->dictado->escala();
        $estudiantes = $grupo->dictado->estudiantes()->with('grupos')->get();
        $delGrupo = $grupo->estudiantes()->wherePivot('activo', true)->pluck('estudiantes.id')->all();
        $porDocumento = $estudiantes->keyBy(fn (Estudiante $e) => $this->normalizarDocumento($e->documento));
        $porNombre = $this->indicePorNombre($estudiantes);
        $grupoNorm = $this->normalizarTexto($grupo->nombre);

        $resultado = [];
        $asignados = [];

        foreach ($filas as $i => $f) {
            $fila = [
                'numero' => $i + 2,
                'documento' => $columnas['documento'] !== null ? ($f[$columnas['documento']] ?? '') : '',
                'nombre' => $this->nombreDeFila($f, $columnas),
                'grupo' => $columnas['grupo'] !== null ? ($f[$columnas['grupo']] ?? '') : '',
                'original' => $columnas['nota'] !== null ? ($f[$columnas['nota']] ?? '') : '',
                'valor' => null,
                'estudiante_id' => null,
                'estudiante' => null,
                'cruce' => null,      // documento | nombre
                'estado' => 'ok',     // ok | sin_nota | no_cruzo | ambiguo | otro_grupo | repetida | fuera_de_rango | no_numerica
                'detalle' => null,
            ];

            // 1. Cruce: documento primero; nombre completo solo como respaldo
            $e = null;
            $doc = $this->normalizarDocumento($fila['documento']);
            if ($doc !== '' && $porDocumento->has($doc)) {
                $e = $porDocumento->get($doc);
                $fila['cruce'] = 'documento';
            } elseif ($fila['nombre'] !== '') {
                $candidatos = $porNombre[$this->normalizarTexto($fila['nombre'])] ?? [];
                if (count($candidatos) === 1) {
                    $e = $candidatos[0];
                    $fila['cruce'] = 'nombre';
                    if ($doc !== '') {
                        $fila['detalle'] = "El documento del archivo ({$fila['documento']}) no está en el dictado; cruzó por nombre completo.";
                    }
                } elseif (count($candidatos) > 1) {
                    $fila['estado'] = 'ambiguo';
                    $fila['detalle'] = count($candidatos).' estudiantes con el mismo nombre completo: use el documento.';
                }
            }

            if ($e === null) {
                if ($fila['estado'] === 'ok') {
                    $fila['estado'] = 'no_cruzo';
                    $fila['detalle'] = $doc !== '' ? 'El documento no está en el dictado.' : 'El nombre no coincide completo con ningún estudiante.';
                }
                $resultado[] = $fila;

                continue;
            }
            $fila['estudiante_id'] = $e->id;
            $fila['estudiante'] = $e->nombreCompleto();

            // 2. Grupo: la columna del archivo manda; y el estudiante debe estar en este grupo
            if ($fila['grupo'] !== '' && $this->normalizarTexto($fila['grupo']) !== $grupoNorm) {
                $fila['estado'] = 'otro_grupo';
                $fila['detalle'] = "El archivo lo asigna al grupo «{$fila['grupo']}».";
            } elseif (! in_array($e->id, $delGrupo, true)) {
                $fila['estado'] = 'otro_grupo';
                $fila['detalle'] = 'Pertenece a '.($e->grupos->pluck('nombre')->implode(', ') ?: 'otro grupo').'.';
            } elseif (isset($asignados[$e->id])) {
                // 3. Repetidas
                $fila['estado'] = 'repetida';
                $fila['detalle'] = "Ya tiene nota en la fila {$asignados[$e->id]}; se usa la primera.";
            } else {
                // 4. Valor y conversión de escala
                $valor = $this->numero($fila['original']);
                if (trim($fila['original']) === '') {
                    $fila['estado'] = 'sin_nota';
                    $fila['detalle'] = 'Celda vacía: queda vacía, no en cero.';
                } elseif ($valor === null) {
                    $fila['estado'] = 'no_numerica';
                    $fila['detalle'] = "«{$fila['original']}» no es un número.";
                } elseif ($valor->isNegative() || $valor->isGreaterThan($escalaOrigen)) {
                    $fila['estado'] = 'fuera_de_rango';
                    $fila['detalle'] = "Fuera de la escala 0–{$escalaOrigen} del archivo.";
                } else {
                    $fila['valor'] = BigDecimal::of($escala->maximo)->isEqualTo($escalaOrigen) && BigDecimal::of($escala->minimo)->isZero()
                        ? (string) $valor
                        : $escala->convertirDesde((string) $valor, $escalaOrigen);
                    $asignados[$e->id] = $fila['numero'];
                }
            }

            $resultado[] = $fila;
        }

        $conNota = array_keys($asignados);
        $sinNota = $estudiantes->whereIn('id', $delGrupo)->whereNotIn('id', $conNota)->sortBy('apellidos')->values()->all();

        $contar = fn (string $estado) => count(array_filter($resultado, fn ($f) => $f['estado'] === $estado));

        return [
            'filas' => $resultado,
            'sin_nota' => $sinNota,
            'resumen' => [
                'total' => count($resultado),
                'importables' => $contar('ok'),
                'por_documento' => count(array_filter($resultado, fn ($f) => $f['estado'] === 'ok' && $f['cruce'] === 'documento')),
                'por_nombre' => count(array_filter($resultado, fn ($f) => $f['estado'] === 'ok' && $f['cruce'] === 'nombre')),
                'no_cruzo' => $contar('no_cruzo') + $contar('ambiguo'),
                'otro_grupo' => $contar('otro_grupo'),
                'repetidas' => $contar('repetida'),
                'fuera_de_rango' => $contar('fuera_de_rango') + $contar('no_numerica'),
                'sin_nota' => $contar('sin_nota'),
                'estudiantes_sin_nota' => count($sinNota),
            ],
        ];
    }

    /**
     * Crea la columna y registra las notas en una sola operación (deshacible y auditada).
     *
     * @param  array<int, array<string, mixed>>  $filas  resultado de analizar()
     * @return array{actividad: Actividad, operacion: string, importadas: int}
     */
    public function confirmar(Grupo $grupo, Componente $componente, string $nombre, array $filas, string $archivo): array
    {
        if ($componente->division->dictado_id !== $grupo->dictado_id) {
            throw new InvalidArgumentException('El componente no pertenece a este dictado.');
        }

        $registro = app(RegistroNotas::class);
        $actividad = null;
        $importadas = 0;

        $operacion = $registro->operacion(function () use ($grupo, $componente, $nombre, $filas, $archivo, $registro, &$actividad, &$importadas) {
            $actividad = $componente->actividades()->create([
                'nombre' => Str::limit(trim($nombre), 120, ''),
                'valor_max' => $grupo->dictado->escala_max,
                'orden' => (int) $componente->actividades()->max('orden') + 1,
            ]);
            $actividad->grupos()->attach($grupo->id);

            $estudiantes = Estudiante::query()->whereIn('id', array_column($filas, 'estudiante_id'))->get()->keyBy('id');
            foreach ($filas as $f) {
                if ($f['estado'] !== 'ok') {
                    continue;
                }
                $registro->asignar($actividad, $estudiantes[$f['estudiante_id']], $f['valor'], 'archivo',
                    "Importada de «{$archivo}», fila {$f['numero']} (cruce por {$f['cruce']})");
                $importadas++;
            }
        });

        return ['actividad' => $actividad, 'operacion' => $operacion, 'importadas' => $importadas];
    }

    private function nombreDeFila(array $f, array $c): string
    {
        if ($c['nombre'] !== null) {
            return trim($f[$c['nombre']] ?? '');
        }
        if ($c['apellidos'] !== null && $c['nombres'] !== null) {
            return trim(($f[$c['apellidos']] ?? '').' '.($f[$c['nombres']] ?? ''));
        }

        return '';
    }

    /**
     * Índice por nombre completo normalizado, en los dos órdenes habituales
     * («APELLIDOS NOMBRES» y «NOMBRES APELLIDOS»). Nunca por apellido solo.
     *
     * @return array<string, array<int, Estudiante>>
     */
    private function indicePorNombre(Collection $estudiantes): array
    {
        $indice = [];
        foreach ($estudiantes as $e) {
            if (trim($e->nombres) === '') {
                continue; // sin nombres separados no hay nombre completo confiable
            }
            foreach (array_unique([
                $this->normalizarTexto("{$e->apellidos} {$e->nombres}"),
                $this->normalizarTexto("{$e->nombres} {$e->apellidos}"),
            ]) as $clave) {
                $indice[$clave][] = $e;
            }
        }

        return $indice;
    }

    /** Mayúsculas, sin tildes, sin comas ni espacios repetidos. */
    private function normalizarTexto(string $t): string
    {
        $t = mb_strtoupper(trim($t));
        $t = strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);

        return trim(preg_replace('/[\s,.;]+/u', ' ', $t));
    }

    private function normalizarDocumento(string $d): string
    {
        return preg_replace('/[^0-9A-Za-z]/', '', $d);
    }

    private function numero(string $v): ?BigDecimal
    {
        $v = trim(str_replace(',', '.', $v));
        if ($v === '') {
            return null;
        }
        try {
            return BigDecimal::of($v);
        } catch (MathException) {
            return null;
        }
    }
}
