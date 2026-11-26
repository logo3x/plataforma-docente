@php($num = fn ($v) => $v === null ? '—' : (string) \Brick\Math\BigDecimal::of($v)->toScale(2, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros())
@if (! empty($rubrica['criterios']))
    <table style="margin-top:.5rem"><caption class="suave" style="text-align:left">Calificada con la rúbrica «{{ $rubrica['titulo'] }}»</caption>
        <thead><tr><th>Criterio</th><th>Nivel alcanzado</th><th class="num">Puntos</th></tr></thead><tbody>
        @foreach ($rubrica['criterios'] as $c)
            <tr><td>{{ $c['nombre'] }} <span class="suave">({{ $c['peso'] }} %)</span></td>
                <td>@if ($c['nivel'])<strong>{{ $c['nivel'] }}</strong>@if ($c['descripcion'])<div class="suave">{{ $c['descripcion'] }}</div>@endif @else <span class="suave">sin evaluar</span>@endif</td>
                <td class="num">{{ $num($c['valor']) }} / {{ $num($c['maximo']) }}</td></tr>
        @endforeach
    </tbody></table>
@endif
