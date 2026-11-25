<x-app titulo="Detalle de la entrega">
    <div class="migas"><a href="{{ route('calificador.show', $entrega->actividad) }}">Calificador — {{ $entrega->actividad->nombre }}</a> › Detalle</div>
    <h1>{{ $entrega->integrantes->map->nombreCompleto()->join(' · ') }}</h1>
    <p class="suave">{{ $entrega->sigla_detectada }} · nota {{ $nota === null ? 'pendiente' : $escala->mostrar($nota) }}
        @if ($entrega->duracion_seg !== null) · duración {{ intdiv($entrega->duracion_seg, 60) }} min @endif</p>

    @include('calificador._items', ['respuestas' => $entrega->respuestas])
</x-app>
