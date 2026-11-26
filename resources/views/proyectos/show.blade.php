<x-app :titulo="$m->titulo.' — Ideas de proyectos'">
    @php($IP = \App\Material\IdeasProyecto::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('proyectos.index', $m->materia) }}">Ideas de proyectos — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
    <div class="fila" style="justify-content:space-between">
        <h1 style="margin-bottom:0">{{ $m->titulo }}@if ($estudiante) <span class="suave">· enunciados para estudiantes</span>@endif</h1>
        <div class="fila no-imprimir">
            <a class="boton boton-sec" href="{{ route('proyectos.edit', $m) }}">Editar</a>
            @if ($estudiante)
                <a class="boton boton-sec" href="{{ route('proyectos.show', $m) }}">Guía del docente</a>
            @else
                <a class="boton boton-sec" href="{{ route('proyectos.show', ['material' => $m, 'version' => 'estudiante']) }}">Enunciados para estudiantes</a>
            @endif
            <a class="boton boton-sec" href="{{ route('publicacion.edit', $m) }}">Publicar</a>
            <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir</button>
        </div>
    </div>
    <p class="suave">{{ $m->materia->nombre }} · {{ count($c['proyectos']) }} proyecto(s)
        <span class="no-imprimir">· {{ $m->generacion_ia_id ? 'generadas con IA ('.$m->generacion?->motor?->nombre.') y revisadas por el docente' : 'escritas a mano' }}</span></p>
    @if ($c['proposito'] && ! $estudiante)<p>{{ $c['proposito'] }}</p>@endif

    @if (! $estudiante && $advertencias)
        <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    @foreach ($c['proyectos'] as $i => $p)
        <section class="tarjeta sesion-unidad">
            <div class="fila" style="justify-content:space-between;align-items:flex-start">
                <h2>{{ $i + 1 }}. {{ $p['titulo'] }}</h2>
                @unless ($estudiante)
                    <a class="boton boton-sec boton-chico no-imprimir" href="{{ route('rubricas.index', ['materia' => $m->materia, 'proposito' => 'Proyecto «'.$p['titulo'].'»: '.$p['problema'].' Entregables: '.implode('; ', $p['entregables']).'. Criterios: '.implode('; ', $p['criterios']).'.']) }}#generar">Generar su rúbrica</a>
                @endunless
            </div>
            <p class="suave" style="margin:0 0 .4rem">{{ $p['integrantes'] === '1' ? 'Individual' : $p['integrantes'].' integrantes' }} · {{ $p['semanas'] }} semana(s)
                @unless ($estudiante) · dificultad {{ mb_strtolower($IP::DIFICULTADES[$p['dificultad']] ?? $p['dificultad']) }}@endunless</p>
            @if ($p['resumen'])<p><strong>{{ $p['resumen'] }}</strong></p>@endif
            <dl>
                <dt>El problema</dt><dd>{{ $p['problema'] }}</dd>
                @if ($p['temas'])<dt>Temas que integra</dt><dd>{{ implode(' · ', $p['temas']) }}</dd>@endif
                <dt>Qué se entrega</dt><dd><ul style="margin:0;padding-left:1.1rem">@foreach ($p['entregables'] as $x)<li>{{ $x }}</li>@endforeach</ul></dd>
                @if ($p['hitos'])<dt>Hitos</dt><dd><ul style="margin:0;padding-left:1.1rem">@foreach ($p['hitos'] as $h)<li>Semana {{ $h['semana'] }}: {{ $h['entrega'] }}</li>@endforeach</ul></dd>@endif
                <dt>Cómo se evalúa</dt><dd><ul style="margin:0;padding-left:1.1rem">@foreach ($p['criterios'] as $x)<li>{{ $x }}</li>@endforeach</ul></dd>
                @if ($p['extension'])<dt>Reto adicional</dt><dd>{{ $p['extension'] }}</dd>@endif
                @if (! $estudiante && $p['notas_docente'])<dt>Notas para el docente</dt><dd>{{ $p['notas_docente'] }}</dd>@endif
            </dl>
        </section>
    @endforeach
</x-app>
