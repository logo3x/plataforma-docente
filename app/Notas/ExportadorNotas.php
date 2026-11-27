<?php

namespace App\Notas;

use App\Calificacion\EstadisticasActividad;
use App\Models\Grupo;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as OpcionesCsv;
use OpenSpout\Writer\CSV\Writer as EscritorCsv;
use OpenSpout\Writer\XLSX\Writer as EscritorXlsx;

/**
 * Exportación del registro de notas (3.1.11).
 *
 *  - XLSX: una hoja por división (todas las actividades, promedios y definitiva) y la hoja «Planilla».
 *  - CSV:  solo la planilla, con `;` y BOM para que Excel en español la abra bien.
 *
 * Las notas salen con el redondeo del dictado. Mientras una división esté incompleta, su
 * definitiva se marca como tal y la nota del periodo no se exporta como final (3.1.14).
 */
final class ExportadorNotas
{
    public function xlsx(Grupo $grupo, string $ruta): void
    {
        $tablero = new TableroNotas($grupo);
        $escala = $tablero->escala();
        $negrita = (new Style)->withFontBold(true);

        $w = new EscritorXlsx;
        $w->openToFile($ruta);
        $primera = true;

        foreach ($tablero->pestanas() as $division) {
            $hoja = $primera ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $hoja->setName($this->nombreHoja($division->nombre));
            $primera = false;

            $encabezado = ['Documento', 'Apellidos', 'Nombres'];
            foreach ($division->componentes as $c) {
                foreach ($c->actividades as $a) {
                    $encabezado[] = "{$c->nombre}: {$a->nombre}";
                }
                $encabezado[] = "Promedio {$c->nombre} ({$c->porcentaje()} %)";
            }
            array_push($encabezado, "Definitiva {$division->nombre}", 'Estado');
            $w->addRow(Row::fromValuesWithStyle($encabezado, $negrita));

            foreach ($tablero->filasDivision($division) as $fila) {
                $e = $fila['estudiante'];
                $r = $fila['resultado'];
                $valores = [$e->documento, $e->apellidos, $e->nombres];
                foreach ($division->componentes as $c) {
                    foreach ($c->actividades as $a) {
                        $valores[] = $this->numero($tablero->notaEfectiva($a->id, $e->id), $escala);
                    }
                    $valores[] = $this->numero($r->promedios[$c->id] ?? null, $escala);
                }
                array_push($valores, $this->numero($r->definitiva, $escala), $this->texto($r->estado));
                $w->addRow(Row::fromValues($valores));
            }
        }

        $w->addNewSheetAndMakeItCurrent()->setName('Planilla');
        foreach ($this->planilla($tablero) as $i => $fila) {
            $w->addRow($i === 0 ? Row::fromValuesWithStyle($fila, $negrita) : Row::fromValues($fila));
        }

        // 6.10.6: las estadísticas de las actividades calificadas en la plataforma viajan con el corte
        $actividades = $grupo->dictado->divisiones()->with('componentes.actividades')->get()
            ->flatMap(fn ($d) => $d->componentes->flatMap(fn ($c) => $c->actividades->map(fn ($a) => [$d, $c, $a])))
            ->filter(fn ($x) => $x[2]->artefacto_id !== null && $x[2]->entregas()->exists());
        if ($actividades->isNotEmpty()) {
            $w->addNewSheetAndMakeItCurrent()->setName('Estadísticas');
            foreach ($actividades as [$d, $c, $a]) {
                $est = new EstadisticasActividad($a, $grupo);
                $r = $est->resumen();
                $w->addRow(Row::fromValuesWithStyle(["{$d->nombre} › {$c->nombre} › {$a->nombre}"], $negrita));
                $w->addRow(Row::fromValues(['Calificadas', $r['n'], 'Promedio', $this->numero($r['promedio'], $escala),
                    'Aprobados', $r['aprobados'], 'Reprobados', $r['reprobados']]));
                $w->addRow(Row::fromValuesWithStyle(['Ítem', 'Tipo', 'Tema', 'Enunciado', 'Respuestas', 'Acierto %', 'Discriminación', 'Anulado', 'Revisar'], $negrita));
                foreach ($est->items() as $i) {
                    $w->addRow(Row::fromValues([$i['numero'], $i['tipo'], $i['tema'] ?? '', $i['texto'], $i['respuestas'],
                        $i['acierto'] === null ? null : round($i['acierto'] * 100, 1), $i['discriminacion'] === null ? null : (float) $i['discriminacion'],
                        $i['item']->anulada_en ? 'Sí' : 'No', implode(' ', $i['marcas'])]));
                }
                $w->addRow(Row::fromValues([]));
            }
        }

        $w->close();
    }

    public function csv(Grupo $grupo, string $ruta): void
    {
        $w = new EscritorCsv(new OpcionesCsv(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
        $w->openToFile($ruta);
        foreach ($this->planilla(new TableroNotas($grupo)) as $fila) {
            $w->addRow(Row::fromValues(array_map(fn ($v) => is_float($v) ? str_replace('.', ',', (string) $v) : $v, $fila)));
        }
        $w->close();
    }

    /**
     * Planilla consolidada: definitiva y aporte por división, nota del periodo y estado.
     *
     * @return array<int, array<int, mixed>>
     */
    public function planilla(TableroNotas $tablero): array
    {
        $escala = $tablero->escala();
        $encabezado = ['Documento', 'Apellidos', 'Nombres'];
        foreach ($tablero->pestanas() as $d) {
            array_push($encabezado, "{$d->nombre} ({$d->porcentaje()} %)", "Estado {$d->nombre}");
        }
        array_push($encabezado, 'Nota del periodo', 'Estado del periodo');

        $filas = [$encabezado];
        foreach ($tablero->filasConsolidado() as $f) {
            $e = $f['estudiante'];
            $fila = [$e->documento, $e->apellidos, $e->nombres];
            foreach ($tablero->pestanas() as $d) {
                $r = $f['divisiones'][$d->id];
                array_push($fila, $this->numero($r->definitiva, $escala), $this->texto($r->estado));
            }
            $final = $f['estado'] !== EstadoCorte::Incompleto;
            array_push($fila,
                $final ? $this->numero($f['acumulado'], $escala) : null,          // no se exporta como final (3.1.14)
                $final ? $this->texto($f['estado']) : 'En curso (acumulado '.$escala->mostrar($f['acumulado']).')');
            $filas[] = $fila;
        }

        return $filas;
    }

    private function numero(?string $valor, Escala $escala): ?float
    {
        return $valor === null ? null : (float) $escala->mostrar($valor);
    }

    private function texto(EstadoCorte $e): string
    {
        return match ($e) {
            EstadoCorte::Aprobado => 'Aprobado',
            EstadoCorte::Reprobado => 'Reprobado',
            EstadoCorte::Incompleto => 'Incompleto',
            EstadoCorte::SinActividades => 'Sin actividades',
        };
    }

    /** Excel limita los nombres de hoja a 31 caracteres y prohíbe algunos símbolos. */
    private function nombreHoja(string $nombre): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $nombre), 0, 31);
    }
}
