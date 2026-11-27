{{-- Rúbrica como documento (7.8): criterios por niveles, para compartir con los estudiantes o adjuntar a la actividad. --}}
<h1>{{ $r->titulo }}</h1>
@if (filled($r->proposito))<p>{{ $r->proposito }}</p>@endif
<table>
    <thead><tr><th>Criterio</th><th class="num">Peso</th>@foreach ($r->criterios[0]['niveles'] ?? [] as $n)<th>{{ $n['nombre'] }} ({{ $n['porcentaje'] }} %)</th>@endforeach</tr></thead>
    <tbody>
        @foreach ($r->criterios as $c)
            <tr>
                <td><strong>{{ $c['nombre'] }}</strong>@if (filled($c['descripcion'] ?? null))<br>{{ $c['descripcion'] }}@endif</td>
                <td class="num">{{ $c['peso'] }} %</td>
                @foreach ($c['niveles'] as $n)<td>{{ $n['descripcion'] }}@if (($r->criterios[0]['niveles'][$loop->index]['nombre'] ?? null) !== $n['nombre'])<br><em>{{ $n['nombre'] }} ({{ $n['porcentaje'] }} %)</em>@endif</td>@endforeach
            </tr>
        @endforeach
    </tbody>
</table>
<p>El puntaje de cada criterio es su peso por el porcentaje del nivel alcanzado.</p>
