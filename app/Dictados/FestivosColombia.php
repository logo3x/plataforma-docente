<?php

namespace App\Dictados;

use Carbon\CarbonImmutable;

/**
 * Festivos nacionales de Colombia (Ley 51 de 1983, «Ley Emiliani»). Los fijos caen en su fecha; los
 * trasladables pasan al lunes siguiente si no caen en lunes; los de Semana Santa y los que dependen de
 * la Pascua se calculan desde el domingo de Pascua (algoritmo gregoriano anónimo, sin la extensión calendar).
 */
final class FestivosColombia
{
    /** @var array<int, array<string, string>> */
    private static array $cache = [];

    /** @return array<string, string> fecha Y-m-d → nombre, ordenado por fecha */
    public static function del(int $anio): array
    {
        if (isset(self::$cache[$anio])) {
            return self::$cache[$anio];
        }
        $f = fn (int $m, int $d) => CarbonImmutable::create($anio, $m, $d);
        $alLunes = fn (CarbonImmutable $d) => $d->isMonday() ? $d : $d->next(CarbonImmutable::MONDAY);
        $pascua = self::pascua($anio);

        $festivos = [
            [$f(1, 1), 'Año Nuevo'],
            [$alLunes($f(1, 6)), 'Reyes Magos'],
            [$alLunes($f(3, 19)), 'San José'],
            [$pascua->subDays(3), 'Jueves Santo'],
            [$pascua->subDays(2), 'Viernes Santo'],
            [$f(5, 1), 'Día del Trabajo'],
            [$pascua->addDays(43), 'Ascensión del Señor'],
            [$pascua->addDays(64), 'Corpus Christi'],
            [$pascua->addDays(71), 'Sagrado Corazón'],
            [$alLunes($f(6, 29)), 'San Pedro y San Pablo'],
            [$f(7, 20), 'Día de la Independencia'],
            [$f(8, 7), 'Batalla de Boyacá'],
            [$alLunes($f(8, 15)), 'Asunción de la Virgen'],
            [$alLunes($f(10, 12)), 'Día de la Raza'],
            [$alLunes($f(11, 1)), 'Todos los Santos'],
            [$alLunes($f(11, 11)), 'Independencia de Cartagena'],
            [$f(12, 8), 'Inmaculada Concepción'],
            [$f(12, 25), 'Navidad'],
        ];
        $r = [];
        foreach ($festivos as [$d, $nombre]) {
            $r[$d->format('Y-m-d')] = isset($r[$d->format('Y-m-d')]) ? $r[$d->format('Y-m-d')].' y '.$nombre : $nombre;
        }
        ksort($r);

        return self::$cache[$anio] = $r;
    }

    public static function nombre(CarbonImmutable $d): ?string
    {
        return self::del($d->year)[$d->format('Y-m-d')] ?? null;
    }

    /** Domingo de Pascua (calendario gregoriano). */
    public static function pascua(int $anio): CarbonImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($anio, $mes, $dia);
    }
}
