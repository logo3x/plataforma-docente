<x-app :titulo="'Similitud — '.$actividad->nombre">
    @php($S = \App\Calificacion\Similitud::class)
    @php($nombres = fn ($e) => $e->integrantes->map->nombreCompleto()->join(' · '))
    <div class="migas"><a href="{{ $actividad->artefacto_id ? route('calificador.show', $actividad) : route('calificador.archivos', $actividad) }}">{{ $actividad->artefacto_id ? 'Calificador' : 'Entregas de archivo' }} — {{ $actividad->nombre }}</a> › Similitud</div>
    <h1>Similitud entre entregas</h1>
    <p class="suave">Pares de entregas con respuestas abiertas, código o archivos entregados muy parecidos, pregunta por pregunta. Quitar comentarios, cambiar el espaciado
        o renombrar variables no esconde una copia; tampoco copiar solo una parte. No cuentan la plantilla, el enunciado ni lo que escribe casi todo el grupo.
        <strong>Es una señal, no un veredicto:</strong> la plataforma no acusa ni cambia notas; usted revisa el par y decide.</p>

    <div class="fila" style="margin-bottom:.8rem">
        <span>{{ $entregas }} entrega(s) comparadas · {{ $pares->count() }} par(es) desde {{ (int) ($S::UMBRAL * 100) }} % de coincidencia</span>
        @if ($reutilizada)
            @if ($otros)
                <a class="boton boton-sec boton-chico" href="{{ route('calificador.similitud', $actividad) }}">Solo esta aplicación</a>
            @else
                <a class="boton boton-sec boton-chico" href="{{ route('calificador.similitud', ['actividad' => $actividad, 'otros' => 1]) }}">Comparar también con otros periodos que usaron este examen</a>
            @endif
        @endif
    </div>

    @forelse ($pares as $p)
        @php($rev = $revisiones->get($p['a']->id.'-'.$p['b']->id))
        @php($otroPeriodo = $p['b']->actividad_id !== $actividad->id)
        <section class="tarjeta fila" style="justify-content:space-between">
            <div style="flex:1">
                <strong>{{ $nombres($p['a']) }}</strong> y <strong>{{ $nombres($p['b']) }}</strong>
                @if ($otroPeriodo)<span class="etiqueta-tipo">de {{ $p['b']->actividad->componente->division->dictado->periodo }}</span>@endif
                <div class="suave">Pregunta(s):
                    @foreach ($p['items'] as $it){{ $it['numero'] }} ({{ $it['tipo'] === 'reto' ? 'código' : ($it['tipo'] === 'archivo' ? 'texto del archivo' : 'texto') }}, {{ (int) round($it['puntaje'] * 100) }} %)@if (! $loop->last), @endif @endforeach</div>
            </div>
            <span @class(['estado', 'estado-reprobado' => $p['puntaje'] >= $S::ALTA, 'estado-incompleto' => $p['puntaje'] < $S::ALTA])>
                {{ $p['puntaje'] >= $S::ALTA ? 'Muy alta' : 'Alta' }} · {{ (int) round($p['puntaje'] * 100) }} %</span>
            @if ($rev)<span class="suave">Revisado: {{ \App\Models\RevisionSimilitud::CONCLUSIONES[$rev->conclusion] }}</span>@endif
            <a class="boton boton-sec boton-chico" href="{{ route('calificador.similitud.ver', [$actividad, $p['a'], $p['b']]) }}">Ver lado a lado</a>
        </section>
    @empty
        <div class="tarjeta vacio"><strong>No hay pares parecidos.</strong>
            {{ $entregas < 2 ? 'Se necesitan al menos dos entregas.' : 'Ninguna respuesta abierta ni código coincide por encima del umbral.' }}</div>
    @endforelse
</x-app>
