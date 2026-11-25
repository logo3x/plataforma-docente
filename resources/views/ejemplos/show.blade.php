<x-app :titulo="$m->titulo.' — Ejemplos prácticos'">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('ejemplos.index', $m->materia) }}">Ejemplos prácticos — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
    <div class="fila" style="justify-content:space-between">
        <h1 style="margin-bottom:0">{{ $m->titulo }}@if ($estudiante) <span class="suave">· para practicar</span>@endif</h1>
        <div class="fila no-imprimir">
            <a class="boton boton-sec" href="{{ route('ejemplos.edit', $m) }}">Editar</a>
            @if ($estudiante)
                <a class="boton boton-sec" href="{{ route('ejemplos.show', $m) }}">Ver con soluciones</a>
            @else
                <a class="boton boton-sec" href="{{ route('ejemplos.show', ['material' => $m, 'version' => 'estudiante']) }}">Versión sin soluciones</a>
            @endif
            <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
        </div>
    </div>
    <p class="suave">{{ $m->materia->nombre }} · {{ count($c['ejemplos']) }} ejemplo(s)
        <span class="no-imprimir">· {{ $m->generacion_ia_id ? 'generados con IA ('.$m->generacion?->motor?->nombre.') y revisados por el docente' : 'escritos a mano' }}</span></p>
    @if ($c['proposito'])<p>{{ $c['proposito'] }}</p>@endif

    @if (! $estudiante && $advertencias)
        <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif
    @if (! $estudiante && $verificables)
        <script src="{{ asset('js/ejecutor.js') }}"></script>
        <div class="tarjeta no-imprimir" x-data="{ r: null, async init() { const out = []; for (const v of @js($verificables)) out.push({...v, ...(await window.ejecutarJS(v.codigo, v.comprobacion, 3000))}); this.r = out; } }">
            <span role="status" x-text="r === null ? 'Verificando el código en el navegador…' : (r.every(x => x.ok) ? '✓ El código de los ' + r.length + ' ejemplo(s) con comprobación funciona' : '⚠ Falla el código del ejemplo ' + r.filter(x => !x.ok).map(x => x.indice + 1).join(', ') + ': revíselo antes de entregarlo')"
                  x-bind:class="r === null ? 'suave' : (r.every(x => x.ok) ? 'estado estado-aprobado' : 'estado estado-reprobado')"></span>
        </div>
    @endif

    @foreach ($c['ejemplos'] as $i => $e)
        <section class="tarjeta sesion-unidad">
            <h2>Ejemplo {{ $i + 1 }}. {{ $e['titulo'] }} <span class="suave">· {{ \App\Material\EjemplosPracticos::DIFICULTADES[$e['dificultad']] ?? $e['dificultad'] }}@if ($e['tema']) · {{ $e['tema'] }}@endif</span></h2>
            <p style="white-space:pre-wrap">{{ $e['enunciado'] }}</p>
            @if ($estudiante)
                <div class="espacio-respuesta" aria-hidden="true"></div>
            @else
                <ol class="pasos-ejemplo">
                    @foreach ($e['pasos'] as $p)
                        <li><div style="white-space:pre-wrap">{{ $p['explicacion'] }}</div>@if (trim($p['codigo']) !== '')<pre class="codigo">{{ $p['codigo'] }}</pre>@endif</li>
                    @endforeach
                </ol>
                <p><strong>Respuesta:</strong> <span style="white-space:pre-wrap">{{ $e['respuesta'] }}</span></p>
                @if ($e['errores'])<div><strong>Errores comunes</strong><ul>@foreach ($e['errores'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>@endif
            @endif
            @if ($e['practica'])<p><strong>Para practicar:</strong> <span style="white-space:pre-wrap">{{ $e['practica'] }}</span></p>@endif
        </section>
    @endforeach
</x-app>
