<?php

namespace App\Estudiantes;

use App\Archivos\LectorTabla;
use App\Models\Estudiante;
use App\Models\Grupo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as EscritorXlsx;

/**
 * Cargue masivo de estudiantes con la plantilla oficial de secretaría (3.4).
 *
 *  1. leer()       → filas crudas del XLSX o CSV, reconociendo encabezados por nombre
 *  2. analizar()   → validación y vista previa; no escribe nada
 *  3. confirmar()  → importa las filas válidas y devuelve el reporte final
 *
 * La identidad es únicamente `numero_documento`; nunca se cruza por nombre ni apellido.
 */
final class ImportadorListaClase
{
    public const COLUMNAS = [
        'nombre_alumno', 'numero_documento', 'numero_matriculas',
        'numero_convocatorias', 'observacion', 'email', 'telefono',
    ];

    public const OBLIGATORIAS = ['nombre_alumno', 'numero_documento'];

    /** A partir de cuántas matrículas se muestra la marca informativa (3.4.7). */
    public const MATRICULAS_ALTAS = 3;

    /**
     * @return array<int, array<string, string>> filas indexadas por nombre de columna
     */
    public function leer(string $ruta, string $extension): array
    {
        $tabla = (new LectorTabla)->leer($ruta, $extension);
        $encabezados = array_map(fn ($h) => mb_strtolower($h), $tabla['encabezados']);

        $faltan = array_diff(self::OBLIGATORIAS, $encabezados);
        if ($faltan !== []) {
            throw new InvalidArgumentException(
                'El archivo no tiene el formato de la plantilla oficial. Faltan las columnas: '.implode(', ', $faltan).'.'
            );
        }

        return array_map(function (array $celdas) use ($encabezados) {
            $registro = [];
            foreach ($encabezados as $i => $h) {
                if (in_array($h, self::COLUMNAS, true)) {
                    $registro[$h] = $celdas[$i] ?? '';
                }
            }

            return $registro;
        }, $tabla['filas']);
    }

    /**
     * Valida y clasifica sin escribir nada (3.4.2, 3.4.3).
     *
     * @param  array<int, array<string, string>>  $crudas
     * @return array{filas: array<int, FilaLista>, ausentes: array<int, Estudiante>, resumen: array<string, int>}
     */
    public function analizar(Grupo $grupo, array $crudas): array
    {
        $filas = [];
        $vistos = [];

        foreach ($crudas as $i => $c) {
            $numero = $i + 2; // fila 1 = encabezados
            $documento = preg_replace('/[\s.]/', '', $c['numero_documento'] ?? '');
            [$apellidos, $nombres, $revisar] = $this->partirNombre($c['nombre_alumno'] ?? '');
            $email = ($c['email'] ?? '') !== '' ? mb_strtolower($c['email']) : null;

            $fila = new FilaLista(
                $numero, $documento, $apellidos, $nombres, $revisar,
                $this->entero($c['numero_matriculas'] ?? ''), $this->entero($c['numero_convocatorias'] ?? ''),
                ($c['observacion'] ?? '') ?: null, $email, ($c['telefono'] ?? '') ?: null,
            );

            if ($documento === '') {
                $fila->errores[] = 'Documento vacío.';
            } elseif (isset($vistos[$documento])) {
                $fila->errores[] = "Documento repetido en el archivo (también en la fila {$vistos[$documento]}).";
            } else {
                $vistos[$documento] = $numero;
            }
            if (trim($c['nombre_alumno'] ?? '') === '') {
                $fila->errores[] = 'Fila sin nombre.';
            } elseif ($revisar) {
                $fila->advertencias[] = 'El nombre no trae coma: se importa completo en apellidos para que usted lo separe.';
            }
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $fila->advertencias[] = "Correo mal formado ({$email}): se importa sin correo.";
                $fila->email = null;
            }
            if ($fila->matriculas !== null && $fila->matriculas >= self::MATRICULAS_ALTAS) {
                $fila->advertencias[] = "Ha matriculado la materia {$fila->matriculas} veces (informativo).";
            }

            $filas[] = $fila;
        }

        $documentos = array_map(fn (FilaLista $f) => $f->documento, array_filter($filas, fn ($f) => $f->importable()));
        $existentes = Estudiante::query()
            ->where('dictado_id', $grupo->dictado_id)
            ->whereIn('documento', $documentos ?: ['-'])
            ->with('grupos')
            ->get()
            ->keyBy('documento');

        foreach ($filas as $f) {
            if (! $f->importable()) {
                continue;
            }
            $e = $existentes->get($f->documento);
            $f->estado = $e ? 'existente' : 'nuevo';
            $otros = $e?->grupos->where('id', '!=', $grupo->id)->pluck('nombre')->all() ?? [];
            if ($otros !== []) {
                $f->advertencias[] = 'También está en el grupo '.implode(', ', $otros).' (3.1.12).';
            }
        }

        $ausentes = $grupo->estudiantes()
            ->wherePivot('activo', true)
            ->whereNotIn('documento', array_map(fn ($f) => $f->documento, $filas) ?: ['-'])
            ->orderBy('apellidos')
            ->get()
            ->all();

        return [
            'filas' => $filas,
            'ausentes' => $ausentes,
            'resumen' => [
                'total' => count($filas),
                'nuevos' => count(array_filter($filas, fn ($f) => $f->estado === 'nuevo')),
                'existentes' => count(array_filter($filas, fn ($f) => $f->estado === 'existente')),
                'con_errores' => count(array_filter($filas, fn ($f) => ! $f->importable())),
                'con_advertencias' => count(array_filter($filas, fn ($f) => $f->importable() && $f->advertencias !== [])),
                'ausentes' => count($ausentes),
            ],
        ];
    }

    /**
     * Importa las filas válidas. Nunca borra: los ausentes solo se listan (3.4.6).
     *
     * @param  array<int, FilaLista>  $filas
     * @return array{creados:int, actualizados:int, omitidos:array<int, FilaLista>, revisar:array<int, FilaLista>}
     */
    public function confirmar(Grupo $grupo, array $filas): array
    {
        $creados = 0;
        $actualizados = 0;

        DB::transaction(function () use ($grupo, $filas, &$creados, &$actualizados) {
            foreach ($filas as $f) {
                if (! $f->importable()) {
                    continue;
                }

                $estudiante = Estudiante::query()->firstOrNew([
                    'dictado_id' => $grupo->dictado_id,
                    'documento' => $f->documento,
                ]);
                $nuevo = ! $estudiante->exists;

                $datos = [
                    'numero_matriculas' => $f->matriculas,
                    'numero_convocatorias' => $f->convocatorias,
                    'observacion' => $f->observacion,
                    'email' => $f->email,
                    'telefono' => $f->telefono,
                ];
                // A un estudiante existente no se le pisan los nombres que el docente pudo corregir
                if ($nuevo) {
                    $datos += ['apellidos' => $f->apellidos, 'nombres' => $f->nombres, 'revisar_nombre' => $f->revisarNombre];
                }
                $estudiante->fill($datos)->save();

                $grupo->estudiantes()->syncWithoutDetaching([$estudiante->id => ['activo' => true]]);
                $nuevo ? $creados++ : $actualizados++;
            }
        });

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'omitidos' => array_values(array_filter($filas, fn ($f) => ! $f->importable())),
            'revisar' => array_values(array_filter($filas, fn ($f) => $f->importable() && $f->advertencias !== [])),
        ];
    }

    /** Plantilla en blanco descargable, para que el formato siempre coincida (3.4.9). */
    public function plantilla(string $ruta): void
    {
        $w = new EscritorXlsx;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(self::COLUMNAS));
        $w->addRow(Row::fromValues(['PEREZ GOMEZ, ANA MARIA', '1098765432', 1, 1, '', 'ana.perez@ejemplo.edu.co', '3001234567']));
        $w->close();
    }

    /** «APELLIDOS, NOMBRES» por la primera coma; sin coma no se adivina (3.4.5). */
    private function partirNombre(string $nombre): array
    {
        $nombre = preg_replace('/\s+/u', ' ', trim($nombre));
        $pos = mb_strpos($nombre, ',');

        if ($pos === false) {
            return [$nombre, '', true];
        }

        return [trim(mb_substr($nombre, 0, $pos)), trim(mb_substr($nombre, $pos + 1)), false];
    }

    private function entero(string $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
