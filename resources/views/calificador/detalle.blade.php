<x-app titulo="Detalle de la entrega">
    <div class="migas"><a href="{{ route('calificador.show', $entrega->actividad) }}">Calificador — {{ $entrega->actividad->nombre }}</a> › Detalle</div>
    <h1>@foreach ($entrega->integrantes as $i)@if ($i->grupos->first())<a href="{{ route('estudiantes.historial', [$i->grupos->first(), $i]) }}" title="Historial del semestre">{{ $i->nombreCompleto() }}</a>@else{{ $i->nombreCompleto() }}@endif @if (! $loop->last) · @endif @endforeach</h1>
    <p class="suave">{{ $entrega->sigla_detectada }} · nota {{ $nota === null ? 'pendiente' : $escala->mostrar($nota) }}
        @if ($entrega->duracion_seg !== null) · duración {{ intdiv($entrega->duracion_seg, 60) }} min @endif</p>

    @include('calificador._items', ['respuestas' => $entrega->respuestas])
</x-app>
