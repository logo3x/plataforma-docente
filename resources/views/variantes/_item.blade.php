{{-- Un ítem en formato banco, resumido para comparar el original con el nuevo --}}
@php($correctas = array_map('strval', (array) ($it['correctas'] ?? (array_key_exists('correcta', $it) && ! is_bool($it['correcta']) ? [$it['correcta']] : []))))
<p style="margin:.2rem 0"><strong>{{ $it['enunciado'] ?? '' }}</strong></p>
@if (! empty($it['opciones']) && is_array($it['opciones']))
    <ul style="margin:.2rem 0">@foreach ($it['opciones'] as $k => $o)<li @class(['correcta' => in_array((string) $k, $correctas, true)])>{{ $k }}) {{ is_scalar($o) ? $o : json_encode($o, JSON_UNESCAPED_UNICODE) }}@if (in_array((string) $k, $correctas, true)) ✓@endif</li>@endforeach</ul>
@endif
@if (($it['tipo'] ?? '') === 'vf')<p class="correcta" style="margin:.2rem 0">✓ {{ ($it['correcta'] ?? null) === true ? 'Verdadero' : (($it['correcta'] ?? null) === false ? 'Falso' : '?') }}</p>@endif
@if (! empty($it['plantilla']))<pre class="comparacion comparacion-codigo">{{ $it['plantilla'] }}</pre><p class="suave">Huecos: {{ json_encode($it['huecos'] ?? [], JSON_UNESCAPED_UNICODE) }}</p>@endif
@if (! empty($it['pares']))<p class="suave">Pares: {{ json_encode($it['pares'], JSON_UNESCAPED_UNICODE) }}</p>@endif
@if (! empty($it['orden']))<p class="suave">Orden: {{ implode(' → ', array_map(fn ($p) => $it['pasos'][$p] ?? $p, (array) $it['orden'])) }}</p>@endif
@if (! empty($it['referencia']))<p class="suave">Referencia: {{ $it['referencia'] }}</p>@endif
@if (! empty($it['verificaciones']))<ul class="suave" style="margin:.2rem 0">@foreach ($it['verificaciones'] as $v)<li><code>{{ $v['prueba'] ?? '' }}</code> ({{ $v['fraccion'] ?? '?' }}@if (! empty($v['reservada'])), reservada @endif)</li>@endforeach</ul>@endif
@if (! empty($it['justificacion']))<p class="suave" style="margin:.2rem 0">{{ $it['justificacion'] }}</p>@endif
