<x-app :titulo="'Resultado del ensayo — '.$actividad->nombre">
    @php($num = fn ($v) => $v === null ? '—' : (string) \Brick\Math\BigDecimal::of($v)->toScale(2, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros())
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> ›
        <a href="{{ route('calificador.ensayo', $actividad) }}">Modo de ensayo</a> › Resultado</div>
    <h1>Resultado del ensayo</h1>
    <div @class(['aviso', 'aviso-ok' => $ensayo->equipo_listo, 'aviso-error' => ! $ensayo->equipo_listo]) role="status">
        @if ($ensayo->equipo_listo)<strong>✓ El ciclo está cerrado:</strong> el equipo cifró la entrega, la plataforma la descifró y la calificó. Ensayo registrado.
        @else<strong>El equipo no está listo:</strong> @if (! $ensayo->cifrada) la entrega llegó sin cifrar. @endif @if (! $ensayo->verificaciones) el examen no alcanzó a verificar el equipo. @endif
            Revise las verificaciones que fallaron antes de aplicar el examen.@endif
    </div>

    <div class="rejilla">
        <section class="tarjeta">
            <h2 style="margin-top:0">Equipo</h2>
            <p><strong>{{ $ensayo->equipo }}</strong></p>
            <ul>@forelse ($ensayo->verificaciones as $v)<li>{{ ! empty($v['ok']) ? '✓' : '✗' }} {{ $v['nombre'] }}@if (! empty($v['detalle'])) <span class="suave">— {{ $v['detalle'] }}</span>@endif</li>@empty<li class="suave">Sin verificaciones registradas.</li>@endforelse</ul>
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">Sesión</h2>
            <p>Salidas de la ventana: <strong>{{ $ensayo->salidas }}</strong>@if ($ensayo->salidas) (en total {{ $ensayo->segundos_fuera }} s)@endif ·
                ejecuciones de código: <strong>{{ $ensayo->ejecuciones }}</strong></p>
            @if ($sesion['salidas'])<ul>@foreach ($sesion['salidas'] as $s)<li>{{ \Illuminate\Support\Carbon::parse($s['inicio'])->format('H:i:s') }} · {{ $s['segundos'] }} s</li>@endforeach</ul>@endif
            <p class="suave">Si probó código varias veces y salió a otra pestaña una sola vez, aquí debe aparecer una salida con su duración (5.4.9).</p>
        </section>
    </div>

    <h2>Calificación de la entrega @if ($version)<span class="etiqueta-tipo">versión {{ $version }}</span>@endif <span class="suave">· parte del servidor: {{ $num($ensayo->puntaje_servidor) }}</span></h2>
    <script src="{{ asset('js/ejecutor.js') }}"></script>
    <script src="{{ asset('js/ejecutor-dom.js') }}"></script>
    <script src="{{ asset('js/ejecutor-sql.js') }}" data-motor="{{ asset('vendor/sql.js') }}"></script>
    @foreach ($items as $it)
        <section class="tarjeta">
            <div class="fila" style="justify-content:space-between"><strong>{{ $it['numero'] }}. {{ \Illuminate\Support\Str::limit($it['enunciado'], 140) }}</strong>
                <span>@isset($it['puntaje']){{ $num($it['puntaje']) }} / {{ $num($it['peso']) }}@else<span class="suave">máximo {{ $num($it['peso']) }}</span>@endisset</span></div>
            @if ($it['tipo'] === 'reto' && isset($it['estatica']))
                <pre class="comparacion comparacion-codigo">{{ $it['codigo'] ?: '(sin código)' }}</pre>
                <p class="suave">Revisión estática en el servidor, sin ejecutar el código:</p>
                <ul>@foreach ($it['estatica'] as $v)<li>{{ $v['superada'] ? '✓' : '✗' }} {{ $v['nombre'] }}@if (! empty($v['nota'])) — {{ $v['nota'] }}@endif</li>@endforeach</ul>
            @elseif ($it['tipo'] === 'reto')
                <pre class="comparacion comparacion-codigo">{{ $it['codigo'] ?: '(sin código)' }}</pre>
                @php($html = ($it['lenguaje'] ?? 'javascript') === 'html')
                @if ($html && $it['codigo'])@include('calificador._vista-html', ['codigo' => $it['codigo']])@endif
                <ul x-data="{ r: null, async correr(prueba, v) {
                        if (@js(($it['lenguaje'] ?? '') === 'sql')) return window.verificarSQL(@js($it['esquema'] ?? ''), @js($it['codigo']), v, 5000);
                        if (! @js($html)) return window.ejecutarJS(@js($it['codigo']), prueba, 3000);
                        const x = await window.ejecutarHTML(@js($it['codigo']), prueba, 3000);
                        return { ok: x.ok, error: x.estado === 'con_salida' ? null : x.mensaje };
                    }, async init() { const out = []; for (const v of @js($it['verificaciones'])) out.push({ ...v, ...(await this.correr(v.prueba, v)) }); this.r = out; } }">
                    <template x-if="r === null"><li class="suave">Ejecutando las verificaciones en este navegador…</li></template>
                    <template x-for="v in r || []"><li x-text="(v.ok ? '✓ ' : '✗ ') + v.nombre + (v.reservada ? ' (reservada)' : '') + (v.error ? ' — ' + v.error : '')"></li></template>
                </ul>
            @elseif ($it['tipo'] === 'abierta')
                <div class="texto-estudiante">{{ is_string($it['respuesta']) ? $it['respuesta'] : '' }}</div>
                <p class="suave">Las abiertas las califica usted en el calificador.</p>
            @endif
        </section>
    @endforeach
    <p><a class="boton boton-sec" href="{{ route('calificador.ensayo', $actividad) }}">Volver al modo de ensayo</a></p>
</x-app>
