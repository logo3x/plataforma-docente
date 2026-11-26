<x-app :titulo="'Comparar entregas — '.$actividad->nombre">
    @php($nombres = fn ($e) => $e->integrantes->map->nombreCompleto()->join(' · '))
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> ›
        <a href="{{ route('calificador.similitud', $actividad) }}">Similitud</a> › Lado a lado</div>
    <h1>{{ $nombres($a) }} <span class="suave">y</span> {{ $nombres($b) }}
        @if ($puntaje !== null)<span class="suave">· {{ (int) round($puntaje * 100) }} %</span>@endif</h1>
    <p class="suave">Lo resaltado aparece en las dos entregas. Fíjese también en lo raro que coincide: el mismo error, el mismo espaciado irregular,
        el mismo nombre de variable poco común.@if ($b->actividad_id !== $actividad->id) La segunda entrega es del periodo {{ $b->actividad->componente->division->dictado->periodo }}.@endif</p>

    @foreach ($comparaciones as $c)
        <section class="tarjeta">
            <h2 style="margin-top:0">Pregunta {{ $c['numero'] }} @if ($c['puntaje'] !== null)<span class="estado estado-incompleto">{{ (int) round($c['puntaje'] * 100) }} %</span>@else<span class="suave">· sin coincidencia relevante</span>@endif</h2>
            <p class="suave">{{ \Illuminate\Support\Str::limit($c['enunciado'], 200) }}</p>
            <div class="lado-a-lado">
                @foreach ([$a, $b] as $i => $e)
                    <div>
                        <p style="margin:.2rem 0"><strong>{{ $nombres($e) }}</strong></p>
                        {{-- Cada tramo se escapa con e(); solo se agrega <mark> alrededor de lo que coincide --}}
                        <pre @class(['comparacion', 'comparacion-codigo' => $c['codigo']])>{!! collect($c['lados'][$i])->map(fn ($t) => $t['coincide'] ? '<mark>'.e($t['texto']).'</mark>' : e($t['texto']))->join('') !!}</pre>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <form method="POST" action="{{ route('calificador.similitud.revisar', [$actividad, $a, $b]) }}" class="tarjeta">
        @csrf
        <h2 style="margin-top:0">Su conclusión <span class="suave">(queda registrada; no cambia ninguna nota)</span></h2>
        <fieldset style="border:0;padding:0">
            <legend class="sr-only">Conclusión</legend>
            @foreach (\App\Models\RevisionSimilitud::CONCLUSIONES as $k => $v)
                <label style="font-weight:400;display:block"><input type="radio" name="conclusion" value="{{ $k }}" @checked(($revision?->conclusion ?? 'posible_copia') === $k) required> {{ $v }}</label>
            @endforeach
        </fieldset>
        <div class="campo"><label for="nota">Nota para usted</label><textarea id="nota" name="nota" rows="2" maxlength="1000" style="font-family:inherit">{{ $revision?->nota }}</textarea></div>
        <button class="boton">Guardar conclusión</button>
    </form>
</x-app>
