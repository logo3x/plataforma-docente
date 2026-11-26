<x-app :titulo="$m->titulo.' — Texto informativo'">
    @php($TI = \App\Material\TextoInformativo::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('apuntes.index', $m->materia) }}">Textos y apuntes — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
    <div class="fila no-imprimir" style="justify-content:flex-end">
        <a class="boton boton-sec" href="{{ route('apuntes.edit', $m) }}">Editar</a>
        @if ($estudiante)
            <a class="boton boton-sec" href="{{ route('apuntes.show', $m) }}">Con respuestas</a>
        @else
            <a class="boton boton-sec" href="{{ route('apuntes.show', ['material' => $m, 'version' => 'estudiante']) }}">Versión para estudiantes</a>
        @endif
        <a class="boton boton-sec" href="{{ route('publicacion.edit', $m) }}">Publicar</a>
        <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
    </div>

    @if (! $estudiante)
        @if ($advertencias)
            <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
                <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('apuntes.repositorio', $m) }}" class="tarjeta no-imprimir fila">
            @csrf
            <span style="flex:1">Guárdelo en el <a href="{{ route('contenido.index', $m->materia) }}">repositorio de contenido</a> como lectura:
                podrá elegirlo como material de referencia en los demás generadores.</span>
            <button class="boton boton-sec">Guardar en el repositorio</button>
        </form>
    @endif

    <article class="apunte">
        <h1>{{ $c['titulo'] }}</h1>
        <p class="suave">{{ $m->materia->nombre }} · unos {{ $minutos }} min de lectura
            <span class="no-imprimir">· {{ $m->generacion_ia_id ? 'generado con IA ('.$m->generacion?->motor?->nombre.') y revisado por el docente' : 'escrito por el docente' }}</span></p>
        @if ($c['proposito'])<p class="apunte-proposito">{{ $c['proposito'] }}</p>@endif

        @foreach ($c['secciones'] as $s)
            <section>
                <h2>{{ $s['titulo'] }}</h2>
                @foreach ($TI::bloques($s['texto']) as $b)
                    @if ($b['tipo'] === 'lista')<ul>@foreach ($b['contenido'] as $li)<li>{{ $li }}</li>@endforeach</ul>
                    @else<p style="white-space:pre-line">{{ $b['contenido'] }}</p>@endif
                @endforeach
                @if ($s['codigo'] !== '')<pre class="codigo">{{ $s['codigo'] }}</pre>@endif
                @if ($s['idea_clave'])<p class="idea-clave"><strong>Idea clave:</strong> {{ $s['idea_clave'] }}</p>@endif
            </section>
        @endforeach

        <section class="tarjeta">
            <h2 style="margin-top:0">Ideas clave</h2>
            <ul>@foreach ($c['resumen'] as $r)<li>{{ $r }}</li>@endforeach</ul>
        </section>

        @if ($c['preguntas'])
            <section>
                <h2>Compruebe lo que entendió</h2>
                <ol>
                    @foreach ($c['preguntas'] as $p)
                        <li>{{ $p['pregunta'] }}@unless ($estudiante)<div class="suave"><em>Respuesta:</em> {{ $p['respuesta'] }}</div>@else<div class="linea-larga" aria-hidden="true"></div>@endunless</li>
                    @endforeach
                </ol>
            </section>
        @endif
        @if ($c['fuentes'])<p class="suave"><strong>Fuentes:</strong> {{ implode(' · ', $c['fuentes']) }}</p>@endif
    </article>
</x-app>
