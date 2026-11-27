@switch($s['tipo'])
    @case('portada')
        <h2 class="d-titulo">{{ $s['titulo'] }}</h2>
        <div class="d-linea" aria-hidden="true"></div>
        @foreach ($s['puntos'] as $p)<p class="d-sub">{{ $p }}</p>@endforeach
        @break
    @case('seccion')
        <h2 class="d-titulo">{{ $s['titulo'] }}</h2>
        @foreach ($s['puntos'] as $p)<p class="d-sub">{{ $p }}</p>@endforeach
        @break
    @case('pregunta')
        <p class="d-etiqueta">Pregunta al grupo</p>
        <h2 class="d-titulo">{{ $s['titulo'] }}</h2>
        @if ($s['puntos'])<ul class="d-pistas">@foreach ($s['puntos'] as $p)<li>{{ $p }}</li>@endforeach</ul>@endif
        @break
    @case('codigo')
        <h2 class="d-titulo">{{ $s['titulo'] }}</h2>
        <div class="d-linea" aria-hidden="true"></div>
        <pre class="d-codigo">{{ $s['codigo'] }}</pre>
        @if ($s['puntos'])<p class="d-sub">{{ implode(' · ', $s['puntos']) }}</p>@endif
        @break
    @default
        <h2 class="d-titulo">{{ $s['titulo'] }}</h2>
        <div class="d-linea" aria-hidden="true"></div>
        <ul @class(['d-vinetas', 'd-cierre' => $s['tipo'] === 'cierre'])>@foreach ($s['puntos'] as $p)<li>{{ $p }}</li>@endforeach</ul>
@endswitch
@if ($s['tipo'] !== 'portada')<p class="d-pie">{{ $materia }} · {{ $n }} / {{ $total }}</p>@endif
