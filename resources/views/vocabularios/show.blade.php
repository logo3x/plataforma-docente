<x-app :titulo="$m->titulo.' — Vocabulario'">
    @php($version = in_array(request('version'), ['tarjetas', 'repaso'], true) ? request('version') : 'lista')
    @php($terminos = $c['terminos'])
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('vocabularios.index', $m->materia) }}">Vocabulario — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
    @include('ia._generacion-funciono')
    <div class="fila" style="justify-content:space-between">
        <h1 style="margin-bottom:0">{{ $m->titulo }} <span class="suave">· {{ ['lista' => 'lista', 'tarjetas' => 'tarjetas para repasar', 'repaso' => 'hoja de repaso'][$version] }}</span></h1>
        <div class="fila no-imprimir">
            <a class="boton boton-sec" href="{{ route('vocabularios.edit', $m) }}">Editar</a>
            <a class="boton boton-sec" href="{{ route('publicacion.edit', $m) }}">Publicar</a>
            <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir</button>
        </div>
    </div>
    <nav class="fila no-imprimir" aria-label="Versiones" style="margin:.6rem 0">
        @foreach (['lista' => 'Lista', 'tarjetas' => 'Tarjetas para repasar', 'repaso' => 'Hoja de repaso'] as $v => $etq)
            <a @class(['boton', 'boton-chico', 'boton-sec' => $version !== $v]) @if ($version === $v) aria-current="page" @endif
               href="{{ route('vocabularios.show', $v === 'lista' ? $m : ['material' => $m, 'version' => $v]) }}">{{ $etq }}</a>
        @endforeach
    </nav>
    <p class="suave">{{ $m->materia->nombre }} · {{ count($terminos) }} término(s)
        <span class="no-imprimir">· {{ $m->generacion_ia_id ? 'generados con IA ('.$m->generacion?->motor?->nombre.') y revisados por el docente' : 'escritos a mano' }}</span></p>
    @if ($c['proposito'] && $version !== 'repaso')<p>{{ $c['proposito'] }}</p>@endif

    @if ($version === 'lista')
        @if ($advertencias)
            <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
                <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('vocabularios.glosario', $m) }}" class="tarjeta no-imprimir fila">
            @csrf
            <span style="flex:1">Pase estos términos al <a href="{{ route('materias.estilo', $m->materia) }}">vocabulario propio de la materia</a>:
                la IA los recibirá en todos los prompts y usará las mismas palabras y definiciones.</span>
            <button class="boton boton-sec">Agregar al vocabulario de la materia</button>
        </form>
        <dl class="vocabulario">
            @foreach ($terminos as $t)
                <div class="termino">
                    <dt>{{ $t['termino'] }}@if ($t['ingles']) <span class="suave" lang="en">({{ $t['ingles'] }})</span>@endif</dt>
                    <dd>{{ $t['definicion'] }}
                        @if ($t['ejemplo'])<div class="suave"><em>Ejemplo:</em> {{ $t['ejemplo'] }}</div>@endif
                        @if ($t['relacionados'])<div class="suave">Relacionado con: {{ implode(', ', $t['relacionados']) }}</div>@endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @elseif ($version === 'tarjetas')
        <p class="suave no-imprimir">Haga clic en una tarjeta (o enfóquela y presione Enter) para ver la definición.</p>
        <div class="tarjetas-vocabulario">
            @foreach ($terminos as $t)
                <button type="button" class="tarjeta-voc" x-data="{ volteada: false }" x-on:click="volteada = ! volteada" x-bind:aria-pressed="volteada.toString()">
                    <span class="frente" x-show="! volteada"><strong>{{ $t['termino'] }}</strong>@if ($t['ingles'])<br><span class="suave" lang="en">{{ $t['ingles'] }}</span>@endif</span>
                    <span class="reverso" x-show="volteada" x-cloak>{{ $t['definicion'] }}</span>
                </button>
            @endforeach
        </div>
    @else
        {{-- Hoja de repaso: la definición sin el término (que se tapa si aparece) y el banco de palabras en un orden que no delata la respuesta --}}
        @php($banco = collect($terminos)->pluck('termino')->sortBy(fn ($x) => crc32($x.$m->id))->values())
        <div class="tarjeta"><strong>Banco de palabras:</strong> {{ $banco->join(' · ') }}</div>
        <ol class="repaso-voc">
            @foreach ($terminos as $t)
                <li><span class="linea-respuesta" aria-label="Espacio para escribir el término"></span>
                    {{ preg_replace('/'.preg_quote($t['termino'], '/').'/iu', '_____', $t['definicion']) }}</li>
            @endforeach
        </ol>
    @endif
</x-app>
