<?php

namespace App\Dictados;

use App\Models\Division;
use App\Models\Grupo;
use App\Models\Horario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fechas de clase de un grupo (2.6.2): el horario semanal repetido entre el inicio del primer corte y el
 * fin del último, sin los festivos nacionales ni los días sin clase del periodo. Se calculan, no se guardan:
 * cambiar el horario, las fechas de un corte o un día sin clase las rehace solas.
 */
final class CalendarioClases
{
    /**
     * @return array{rango: ?array{0: CarbonImmutable, 1: CarbonImmutable},
     *     clases: Collection<int, array{numero: int, fecha: CarbonImmutable, horario: Horario, corte: ?Division}>,
     *     omitidas: Collection<int, array{fecha: CarbonImmutable, horario: Horario, motivo: string}>}
     */
    public function de(Grupo $grupo): array
    {
        $dictado = $grupo->dictado;
        $cortes = $dictado->divisiones()->whereNull('parent_id')->get();
        $inicio = $cortes->pluck('fecha_inicio')->filter()->min();
        $fin = $cortes->pluck('fecha_fin')->filter()->max();
        $horarios = $grupo->horarios;
        if (! $inicio || ! $fin || $horarios->isEmpty()) {
            return ['rango' => $inicio && $fin ? [CarbonImmutable::parse($inicio), CarbonImmutable::parse($fin)] : null, 'clases' => collect(), 'omitidas' => collect()];
        }
        $inicio = CarbonImmutable::parse($inicio)->startOfDay();
        $fin = CarbonImmutable::parse($fin)->startOfDay();
        $sinClase = $dictado->diasSinClase;

        $clases = collect();
        $omitidas = collect();
        for ($d = $inicio; $d->lte($fin); $d = $d->addDay()) {
            foreach ($horarios->where('dia', $d->dayOfWeekIso) as $h) {
                $motivo = FestivosColombia::nombre($d)
                    ? 'Festivo: '.FestivosColombia::nombre($d)
                    : $sinClase->first(fn ($s) => $d->betweenIncluded($s->desde, $s->hasta))?->motivo;
                if ($motivo) {
                    $omitidas->push(['fecha' => $d, 'horario' => $h, 'motivo' => $motivo]);

                    continue;
                }
                $clases->push(['numero' => $clases->count() + 1, 'fecha' => $d, 'horario' => $h,
                    'corte' => $cortes->first(fn ($c) => $c->fecha_inicio && $c->fecha_fin && $d->betweenIncluded($c->fecha_inicio, $c->fecha_fin))]);
            }
        }

        return ['rango' => [$inicio, $fin], 'clases' => $clases, 'omitidas' => $omitidas];
    }

    /** Las clases en formato iCalendar, para llevarlas a Google Calendar u Outlook. */
    public function ics(Grupo $grupo): string
    {
        $materia = $grupo->dictado->materia->nombre;
        $esc = fn (string $t) => addcslashes(str_replace(["\r\n", "\n"], '\n', $t), ',;\\');
        $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Plataforma educativa//Calendario de clases//ES', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$esc("{$materia} {$grupo->dictado->periodo} · {$grupo->nombre}"), 'X-WR-TIMEZONE:America/Bogota'];
        foreach ($this->de($grupo)['clases'] as $c) {
            $dia = $c['fecha']->format('Ymd');
            $l = [...$l, 'BEGIN:VEVENT',
                'UID:clase-'.$grupo->id.'-'.$dia.'-'.$c['horario']->id.'@plataforma-educativa',
                'DTSTAMP:'.now('UTC')->format('Ymd\THis\Z'),
                'DTSTART;TZID=America/Bogota:'.$dia.'T'.str_replace(':', '', substr($c['horario']->hora_inicio, 0, 5)).'00',
                'DTEND;TZID=America/Bogota:'.$dia.'T'.str_replace(':', '', substr($c['horario']->hora_fin, 0, 5)).'00',
                'SUMMARY:'.$esc("{$materia} · clase {$c['numero']}".($c['corte'] ? " · {$c['corte']->nombre}" : '')),
                ...($c['horario']->aula ? ['LOCATION:'.$esc($c['horario']->aula)] : []),
                'END:VEVENT'];
        }
        $l[] = 'END:VCALENDAR';

        return implode("\r\n", $l)."\r\n";
    }
}
