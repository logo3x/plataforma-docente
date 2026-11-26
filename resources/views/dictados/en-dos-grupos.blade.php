@php($escala = $dictado->escala())
<x-app titulo="Estudiantes en dos grupos">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }}</a> › Estudiantes en dos grupos</div>
    <h1>Estudiantes en dos grupos</h1>
    <p class="suave">Aparecen en la lista de más de un grupo del periodo: la secretaría los matriculó en dos jornadas, o presentaron algo en la otra.
        Decida en qué grupo queda cada uno. En los demás se marca retirado, sin borrar nada, y sus notas de actividades propias del otro grupo pasan
        a la actividad equivalente del grupo elegido (el mismo examen, o el mismo nombre en el mismo componente), con constancia en la bitácora.
        Nada se sobrescribe.</p>
    @error('decision')<div class="aviso aviso-error" role="alert">{{ $message }}</div>@enderror

    @forelse ($casos as $c)
        @php($e = $c['estudiante'])
        <section class="tarjeta caso-dos-grupos">
            <h2 style="margin-top:0">{{ $e->nombreCompleto() }} <span class="suave">· {{ $e->documento }}</span></h2>
            <div class="rejilla">
                @foreach ($c['grupos'] as $g)
                    @php($plan = $c['planes'][$g['grupo']->id])
                    <div @class(['opcion-grupo', 'sugerida' => $c['sugerido']?->id === $g['grupo']->id])>
                        <h3 style="margin-top:0">{{ $g['grupo']->nombre }} @if ($c['sugerido']?->id === $g['grupo']->id)<span class="etiqueta-tipo">sugerido: tiene más registros</span>@endif</h3>
                        <p>{{ $g['notas']->count() }} nota(s) en actividades de este grupo · {{ $g['entregas'] }} entrega(s)
                            @if ($g['ultima'])<br><span class="suave">Último registro: {{ \Illuminate\Support\Carbon::parse($g['ultima'])->format('d/m/Y') }}</span>@endif</p>
                        @if ($g['notas']->isNotEmpty())
                            <ul class="suave">@foreach ($g['notas'] as $n)<li>{{ $n->actividad->nombre }}: {{ $escala->mostrar((string) $n->valor) }}</li>@endforeach</ul>
                        @endif
                        <p style="margin-bottom:.3rem"><strong>Si queda aquí:</strong></p>
                        <ul>
                            <li>Retirado de {{ $plan['retirar']->pluck('nombre')->join(', ') }}.</li>
                            @foreach ($plan['trasladar'] as $t)<li>«{{ $t['origen']->nombre }}» ({{ $t['grupo']->nombre }}) → «{{ $t['destino']->nombre }}»: {{ $escala->mostrar((string) $t['nota']->valor) }}</li>@endforeach
                            @foreach ($plan['conflictos'] as $t)<li class="error">«{{ $t['origen']->nombre }}» tiene {{ $escala->mostrar((string) $t['nota']->valor) }} y «{{ $t['destino']->nombre }}» ya tiene {{ $escala->mostrar((string) $t['ya']) }}: no se toca, decida en el registro.</li>@endforeach
                            @foreach ($plan['sin_equivalente'] as $t)<li class="error">«{{ $t['origen']->nombre }}» ({{ $escala->mostrar((string) $t['nota']->valor) }}) no tiene equivalente en {{ $g['grupo']->nombre }}: regístrela a mano.</li>@endforeach
                            @if (! $plan['trasladar'] && ! $plan['conflictos'] && ! $plan['sin_equivalente'])<li class="suave">No hay notas que trasladar.</li>@endif
                        </ul>
                        <form method="POST" action="{{ route('dos-grupos.resolver', [$dictado, $e]) }}">@csrf
                            <input type="hidden" name="decision" value="{{ $g['grupo']->id }}">
                            <button @class(['boton', 'boton-sec' => $c['sugerido']?->id !== $g['grupo']->id]) type="submit">Queda en {{ $g['grupo']->nombre }}</button></form>
                    </div>
                @endforeach
            </div>
            <form method="POST" action="{{ route('dos-grupos.resolver', [$dictado, $e]) }}" class="fila" style="margin-top:.75rem">@csrf
                <input type="hidden" name="decision" value="ambos">
                <button class="boton boton-sec boton-chico" type="submit">Mantener en todos sus grupos</button>
                <span class="suave">Si de verdad cursa en ambos; no se le volverá a avisar.</span></form>
        </section>
    @empty
        <div class="tarjeta vacio"><strong>Nadie aparece en dos grupos.</strong>Si una lista nueva trae a alguien que ya está en otro grupo, aparecerá aquí.</div>
    @endforelse
</x-app>
