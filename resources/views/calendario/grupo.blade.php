<x-app :titulo="'Calendario — '.$grupo->dictado->materia->nombre.' '.$grupo->nombre">
    @php($H = \App\Models\Horario::class)
    @php($dictado = $grupo->dictado)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }}</a> › Calendario de {{ $grupo->nombre }}</div>
    <h1>Calendario de clases <span class="suave">{{ $grupo->nombre }}</span></h1>
    <p class="suave">Registre una semana de clases: las fechas salen solas entre el inicio del primer corte y el fin del último, sin los festivos nacionales ni los días sin clase que marque.</p>

    @if ($errors->any())<div class="aviso aviso-error" role="alert"><ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    @unless ($rango)
        <div class="aviso aviso-info" role="note">Los cortes aún no tienen fechas. <a href="{{ route('dictados.estructura', $dictado) }}">Póngales inicio y fin</a> para calcular las clases.</div>
    @endunless

    <div class="rejilla calendario-rejilla">
        <form method="POST" action="{{ route('calendario.horario', $grupo) }}" class="tarjeta">
            @csrf @method('PUT')
            <h2 style="margin-top:0">Horario de la semana</h2>
            <table>
                <thead><tr><th>Día</th><th>Desde</th><th>Hasta</th><th>Aula</th></tr></thead>
                <tbody>
                    @foreach ($H::DIAS as $n => $dia)
                        @php($h = $grupo->horarios->firstWhere('dia', $n))
                        <tr>
                            <td><label style="font-weight:400;white-space:nowrap"><input type="checkbox" name="dias[{{ $n }}][activo]" value="1" @checked(old("dias.{$n}.activo", (bool) $h))> {{ $dia }}</label></td>
                            <td><input type="time" name="dias[{{ $n }}][hora_inicio]" value="{{ old("dias.{$n}.hora_inicio", $h ? substr($h->hora_inicio, 0, 5) : '') }}" aria-label="Hora de inicio del {{ Str::lower($dia) }}"></td>
                            <td><input type="time" name="dias[{{ $n }}][hora_fin]" value="{{ old("dias.{$n}.hora_fin", $h ? substr($h->hora_fin, 0, 5) : '') }}" aria-label="Hora de fin del {{ Str::lower($dia) }}"></td>
                            <td><input type="text" name="dias[{{ $n }}][aula]" maxlength="60" value="{{ old("dias.{$n}.aula", $h?->aula) }}" aria-label="Aula del {{ Str::lower($dia) }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p><button class="boton" type="submit">Guardar horario</button></p>
        </form>

        <section class="tarjeta">
            <h2 style="margin-top:0">Días sin clase del periodo</h2>
            <p class="suave">Además de los festivos: receso, semana institucional, paro. Valen para todos los grupos del periodo.</p>
            @if ($sinClase->isNotEmpty())
                <ul>@foreach ($sinClase as $s)
                    <li>{{ $s->desde->format('Y-m-d') }}@if (! $s->desde->eq($s->hasta)) a {{ $s->hasta->format('Y-m-d') }}@endif · {{ $s->motivo }}
                        <form method="POST" action="{{ route('calendario.sin-clase.quitar', [$grupo, $s]) }}" style="display:inline">@csrf @method('DELETE')<button class="enlace" aria-label="Quitar «{{ $s->motivo }}»">quitar</button></form></li>
                @endforeach</ul>
            @endif
            <form method="POST" action="{{ route('calendario.sin-clase', $grupo) }}" class="fila" style="align-items:flex-end;gap:.5rem">
                @csrf
                <div><label for="desde">Desde</label><input id="desde" name="desde" type="date" required></div>
                <div><label for="hasta">Hasta <span class="suave">(opcional)</span></label><input id="hasta" name="hasta" type="date"></div>
                <div style="flex:1"><label for="motivo">Motivo</label><input id="motivo" name="motivo" type="text" maxlength="120" required placeholder="Semana de receso"></div>
                <button class="boton boton-sec" type="submit">Agregar</button>
            </form>
            @if ($festivos->isNotEmpty())
                <details style="margin-top:.75rem"><summary>Festivos nacionales del periodo ({{ $festivos->count() }})</summary>
                    <ul>@foreach ($festivos as $f => $n)<li>{{ $f }} · {{ $n }}</li>@endforeach</ul></details>
            @endif
        </section>
    </div>

    @if ($clases->isNotEmpty())
        <h2>{{ $clases->count() }} clases <span class="suave">del {{ $rango[0]->format('Y-m-d') }} al {{ $rango[1]->format('Y-m-d') }}</span></h2>
        <p><a href="{{ route('calendario.ics', $grupo) }}">Descargar para Google Calendar u Outlook (.ics)</a>
            @if ($omitidas->isNotEmpty()) <span class="suave">· {{ $omitidas->count() }} {{ $omitidas->count() === 1 ? 'clase no se cuenta' : 'clases no se cuentan' }} por festivo o día sin clase</span>@endif</p>
        @foreach ($clases->groupBy(fn ($c) => $c['corte']?->nombre ?? 'Entre cortes') as $corte => $delCorte)
            <details class="tarjeta" style="box-shadow:none" @if ($loop->first) open @endif>
                <summary><strong>{{ $corte }}</strong> · {{ $delCorte->count() }} clases</summary>
                <table>
                    <thead><tr><th class="num">#</th><th>Fecha</th><th>Día</th><th>Hora</th><th>Aula</th></tr></thead>
                    <tbody>@foreach ($delCorte as $c)
                        <tr><td class="num">{{ $c['numero'] }}</td><td>{{ $c['fecha']->format('Y-m-d') }}</td><td>{{ $H::DIAS[$c['fecha']->dayOfWeekIso] }}</td>
                            <td>{{ substr($c['horario']->hora_inicio, 0, 5) }}–{{ substr($c['horario']->hora_fin, 0, 5) }}</td><td>{{ $c['horario']->aula ?? '—' }}</td></tr>
                    @endforeach</tbody>
                </table>
            </details>
        @endforeach
        @if ($omitidas->isNotEmpty())
            <details class="tarjeta" style="box-shadow:none"><summary>Sin clase ({{ $omitidas->count() }})</summary>
                <ul>@foreach ($omitidas as $o)<li>{{ $o['fecha']->format('Y-m-d') }} · {{ $H::DIAS[$o['fecha']->dayOfWeekIso] }} · {{ $o['motivo'] }}</li>@endforeach</ul></details>
        @endif
    @endif
</x-app>
