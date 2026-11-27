<x-app titulo="Detalle de la entrega">
    <div class="migas"><a href="{{ route('calificador.show', $entrega->actividad) }}">Calificador — {{ $entrega->actividad->nombre }}</a> › Detalle</div>
    <h1>@foreach ($entrega->integrantes as $i)@if ($i->grupos->first())<a href="{{ route('estudiantes.historial', [$i->grupos->first(), $i]) }}" title="Historial del semestre">{{ $i->nombreCompleto() }}</a>@else{{ $i->nombreCompleto() }}@endif @if (! $loop->last) · @endif @endforeach</h1>
    <p class="suave">{{ $entrega->sigla_detectada }} · nota {{ $nota === null ? 'pendiente' : $escala->mostrar($nota) }}
        @if ($entrega->duracion_seg !== null) · duración {{ intdiv($entrega->duracion_seg, 60) }} min @endif</p>
    @if ($partes)<p>{{ $partes }}</p>@endif

    @php($ses = $entrega->sesion ?? [])
    @if ($ses)
        @php($numero = $entrega->actividad->artefacto?->items()->when($entrega->version, fn ($q, $v) => $q->where('banco', $v))->pluck('id')->flip()->map(fn ($i) => $i + 1) ?? collect())
        <details class="tarjeta">
            <summary><strong>Registro de la sesión</strong> <span class="suave">(5.4.9: lo que se le anunció al estudiante en la portada; informa, no sanciona)</span></summary>
            <ul>
                <li>Salidas de la ventana: {{ count($ses['salidas'] ?? []) }}@if (! empty($ses['salidas'])), {{ collect($ses['salidas'])->sum('segundos') }} s en total @endif</li>
                <li>Textos pegados: {{ $ses['pegados'] ?? 0 }} · ejecuciones de código: {{ $ses['ejecuciones'] ?? 0 }} · errores de sintaxis: {{ $ses['errores_sintaxis'] ?? 0 }}</li>
                @if (! empty($ses['reconexiones']))<li>Reconexiones a internet: {{ count($ses['reconexiones']) }}, {{ collect($ses['reconexiones'])->sum('segundos') }} s en pausa</li>@endif
                @if (! empty($ses['restauraciones']))<li>Reabrió el examen {{ $ses['restauraciones'] }} vez/veces</li>@endif
                @if ($entrega->version)<li>Versión {{ $entrega->version }}</li>@endif
                @if (! empty($ses['navegador']))<li class="suave">Navegador: {{ \Illuminate\Support\Str::limit($ses['navegador'], 140) }}</li>@endif
            </ul>
            @if (! empty($ses['tiempos']))
                <p style="margin-bottom:.2rem">Tiempo en cada pregunta:</p>
                <p class="fila" style="gap:.4rem">@foreach (collect($ses['tiempos'])->sortBy(fn ($seg, $item) => $numero[(int) $item] ?? 999) as $item => $seg)<span class="alerta-chip">{{ ($numero[(int) $item] ?? '?') }}: {{ intdiv((int) $seg, 60) }}:{{ str_pad((string) ((int) $seg % 60), 2, '0', STR_PAD_LEFT) }}</span>@endforeach</p>
            @endif
        </details>
    @endif
    @include('calificador._items', ['respuestas' => $entrega->respuestas])
</x-app>
