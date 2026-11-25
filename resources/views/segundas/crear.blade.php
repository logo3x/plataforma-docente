@php
    use App\Models\SegundaOportunidad as S;
    $v = fn ($k, $d = null) => old($k, $valores[$k] ?? $d);
    $num = fn (?string $x) => $x === null ? '—' : $escala->mostrar($x);
@endphp
<x-app titulo="Segunda oportunidad">
    <div class="migas"><a href="{{ route('registro', $grupo) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }} — {{ $grupo->nombre }}</a> › Segunda oportunidad</div>
    <h1>Segunda oportunidad — {{ $estudiante->nombreCompleto() }}</h1>
    <p class="suave">Supletorio (no presentó por causa justificada), recuperación de una actividad, o habilitación de un corte o de la materia.
        <strong>La nota original no se borra</strong>: se registra un evento aparte que queda en la bitácora. Quién tiene derecho lo decide el reglamento de la institución.</p>

    @if ($errors->any())<div class="aviso aviso-error" role="alert">{{ $errors->first() }}</div>@endif

    <form method="POST" class="tarjeta" style="max-width:760px" x-data="{ ambito: '{{ $v('ambito', '') }}', regla: '{{ $v('regla', 'reemplaza') }}' }">
        @csrf
        <div class="campo"><label for="ambito">Sobre qué</label>
            <select id="ambito" name="ambito" x-model="ambito" required>
                <option value="">— elija —</option>
                @foreach ($divisiones as $d)
                    <optgroup label="{{ $d->nombre }}">
                        @foreach ($d->componentes as $c)@foreach ($c->actividades as $a)
                            @php($orig = $tablero->nota($a->id, $estudiante->id))
                            <option value="actividad:{{ $a->id }}">Actividad: {{ $a->nombre }} ({{ $c->nombre }}) — nota actual {{ $orig === null ? 'vacía' : $escala->mostrar($orig) }}</option>
                        @endforeach @endforeach
                        @php($rd = $tablero->resultado($d, $estudiante))
                        <option value="division:{{ $d->id }}">Habilitación del {{ $d->nombre }} — definitiva {{ $num($rd->definitiva) }} ({{ $rd->estado->etiqueta() }})</option>
                    </optgroup>
                @endforeach
                <option value="dictado">Habilitación de la materia (nota final del periodo)</option>
            </select></div>

        <div class="rejilla">
            <div class="campo"><label for="tipo">Tipo</label>
                <select id="tipo" name="tipo" aria-describedby="ayuda-tipo">
                    @foreach (S::TIPOS as $k => $t)<option value="{{ $k }}" @selected($v('tipo', 'supletorio') === $k)>{{ $t }}</option>@endforeach
                </select>
                <p id="ayuda-tipo" class="suave" style="margin:.2rem 0 0;font-size:.85rem"
                   x-text="ambito.startsWith('actividad') ? 'Sobre una actividad: supletorio o recuperación.' : (ambito ? 'Sobre un corte o la materia: habilitación.' : '')"></p></div>
            <div class="campo"><label for="valor">Nota obtenida</label><input id="valor" name="valor" type="text" inputmode="decimal" value="{{ $v('valor') }}" required></div>
            <div class="campo"><label for="fecha">Fecha</label><input id="fecha" name="fecha" type="date" value="{{ $v('fecha', now()->toDateString()) }}" required style="width:100%"></div>
        </div>

        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Regla</legend>
            @foreach (S::REGLAS as $k => $t)<label style="font-weight:400"><input type="radio" name="regla" value="{{ $k }}" x-model="regla"> {{ $t }}</label>@endforeach
            <div x-show="regla === 'tope'" class="campo" style="margin-top:.4rem;max-width:14rem">
                <label for="tope">Tope</label><input id="tope" name="tope" type="text" inputmode="decimal" value="{{ $v('tope', $escala->mostrar($escala->aprobatoria)) }}"></div>
        </fieldset>
        <div class="campo"><label for="motivo">Motivo o soporte <span class="suave">(excusa médica, acta, resolución…)</span></label>
            <input id="motivo" name="motivo" type="text" value="{{ $v('motivo') }}" maxlength="300"></div>

        <div class="fila">
            <button class="boton boton-sec" formaction="{{ route('segundas.simular', [$grupo, $estudiante]) }}">Simular sin registrar</button>
            <button class="boton" formaction="{{ route('segundas.store', [$grupo, $estudiante]) }}">Registrar</button>
        </div>
    </form>

    @if ($simulacion)
        <section class="tarjeta" aria-live="polite">
            <h2 style="margin-top:0">Simulación: así quedaría <span class="suave">(nada se ha registrado)</span></h2>
            <table>
                <thead><tr><th></th><th class="num">Hoy</th><th>Estado</th><th class="num">Con esta segunda oportunidad</th><th>Estado</th></tr></thead>
                <tbody>@foreach ($simulacion as $f)<tr><td>{{ $f['que'] }}</td><td class="num">{{ $num($f['antes']) }}</td><td>{{ $f['estado_antes'] }}</td>
                    <td class="num definitiva">{{ $num($f['despues']) }}</td><td>{{ $f['estado_despues'] }}</td></tr>@endforeach</tbody>
            </table>
        </section>
    @endif

    <h2>Historial de segundas oportunidades</h2>
    @forelse ($eventos as $s)
        <div class="tarjeta" @if ($s->anulada_en) style="opacity:.75" @endif>
            <strong>{{ S::TIPOS[$s->tipo] }}</strong> · {{ $s->fecha->format('Y-m-d') }} · nota {{ $escala->mostrar($s->valor) }} · {{ S::REGLAS[$s->regla] }}@if ($s->regla === 'tope') ({{ $escala->mostrar($s->tope) }})@endif
            <div class="suave">Sobre: {{ $s->ambito instanceof \App\Models\Actividad ? 'actividad «'.$s->ambito->nombre.'»' : ($s->ambito instanceof \App\Models\Division ? $s->ambito->nombre : 'la materia') }}
                @if ($s->motivo) · {{ $s->motivo }}@endif @if ($s->docente) · registró {{ $s->docente->name }}@endif</div>
            @if ($s->anulada_en)
                <div class="estado estado-sin_actividades" style="margin-top:.3rem">Anulado el {{ $s->anulada_en->format('Y-m-d') }}: {{ $s->motivo_anulacion }}</div>
            @else
                <details style="margin-top:.4rem"><summary>Anular (si se registró por error)</summary>
                    <form method="POST" action="{{ route('segundas.anular', [$grupo, $s]) }}" class="fila" style="margin-top:.4rem">@csrf
                        <input name="motivo_anulacion" type="text" required minlength="5" placeholder="Motivo de la anulación" aria-label="Motivo de la anulación" style="max-width:24rem">
                        <button class="boton boton-peligro boton-chico">Anular</button></form>
                </details>
            @endif
        </div>
    @empty
        <p class="suave">Sin segundas oportunidades registradas.</p>
    @endforelse
</x-app>
