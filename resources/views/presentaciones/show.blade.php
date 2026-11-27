@php($todas = array_merge([['tipo' => 'portada', 'titulo' => $c['titulo'], 'puntos' => array_values(array_filter([$c['subtitulo'], $m->materia->nombre])), 'codigo' => '', 'notas' => $c['proposito'], 'tema' => '']], $c['diapositivas']))
<x-app :titulo="$m->titulo.' — Presentación'">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('presentaciones.index', $m->materia) }}">Presentaciones — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
    @include('ia._generacion-funciono')
    <div class="fila no-imprimir" style="justify-content:space-between">
        <h1 style="margin:0">{{ $m->titulo }}</h1>
        <div class="fila">
            <button class="boton" type="button" x-data x-on:click="$dispatch('proyectar', { desde: 0 })">▶ Proyectar</button>
            <a class="boton boton-sec" href="{{ route('presentaciones.pptx', $m) }}">Descargar PowerPoint</a>
            <a class="boton boton-sec" href="{{ route('presentaciones.edit', $m) }}">Editar</a>
            <a class="boton boton-sec" href="{{ route('publicacion.edit', $m) }}">Publicar</a>
            <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
        </div>
    </div>
    <p class="suave no-imprimir">{{ count($todas) }} diapositivas · {{ $m->generacion_ia_id ? 'generada con IA ('.$m->generacion?->motor?->nombre.') y revisada por el docente' : 'hecha por el docente' }}.
        Al proyectar: flechas o clic para avanzar, <kbd>N</kbd> muestra u oculta sus notas, <kbd>Esc</kbd> para salir.</p>
    @if (! $estudiante && $advertencias)
        <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <div class="diapositivas">
        @foreach ($todas as $i => $s)
            <div class="fila-diapositiva">
                <article @class(['diapositiva', 'diapositiva-'.$s['tipo']]) aria-label="Diapositiva {{ $i + 1 }}">
                    @include('presentaciones._diapositiva', ['s' => $s, 'n' => $i + 1, 'total' => count($todas), 'materia' => $m->materia->nombre])
                </article>
                @unless ($estudiante)
                    <aside class="notas-orador"><strong>Notas</strong>
                        <p>{{ $s['notas'] ?: '—' }}</p>
                        <button type="button" class="enlace no-imprimir" x-data x-on:click="$dispatch('proyectar', { desde: {{ $i }} })">Proyectar desde aquí</button></aside>
                @endunless
            </div>
        @endforeach
    </div>

    {{-- Proyección: pantalla completa, una diapositiva a la vez; las notas solo si el docente las pide (N) --}}
    <div class="proyeccion no-imprimir" x-data="{ abierta: false, i: 0, total: {{ count($todas) }}, notas: false,
            abrir(desde) { this.i = desde; this.abierta = true; this.$nextTick(() => this.$refs.p.requestFullscreen?.().catch(() => {})); },
            cerrar() { this.abierta = false; if (document.fullscreenElement) document.exitFullscreen(); },
            ir(p) { this.i = Math.max(0, Math.min(this.total - 1, this.i + p)); } }"
         x-on:proyectar.window="abrir($event.detail.desde)"
         x-on:keydown.window="if (!abierta) return; if (['ArrowRight','PageDown',' '].includes($event.key)) { $event.preventDefault(); ir(1) } else if (['ArrowLeft','PageUp'].includes($event.key)) ir(-1); else if ($event.key === 'Escape') cerrar(); else if ($event.key.toLowerCase() === 'n') notas = !notas"
         x-on:fullscreenchange.document="if (!document.fullscreenElement) abierta = false">
        <div x-ref="p" x-show="abierta" x-cloak class="proyeccion-lienzo" x-on:click="ir(1)" role="dialog" aria-label="Proyección">
            @foreach ($todas as $i => $s)
                <article x-show="i === {{ $i }}" @class(['diapositiva', 'diapositiva-grande', 'diapositiva-'.$s['tipo']])>
                    @include('presentaciones._diapositiva', ['s' => $s, 'n' => $i + 1, 'total' => count($todas), 'materia' => $m->materia->nombre])
                </article>
            @endforeach
            @unless ($estudiante)
                <div class="proyeccion-notas" x-show="notas" x-on:click.stop>
                    @foreach ($todas as $i => $s)<p x-show="i === {{ $i }}">{{ $s['notas'] ?: 'Sin notas.' }}</p>@endforeach
                </div>
            @endunless
        </div>
    </div>
</x-app>
