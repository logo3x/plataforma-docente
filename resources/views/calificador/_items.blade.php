{{-- Detalle autoexplicativo por ítem (6.4). Lo comparten la vista del docente y el informe del estudiante. --}}
@php($num = fn ($v) => $v === null ? '—' : (string) \Brick\Math\BigDecimal::of($v)->toScale(2, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros())
@foreach ($respuestas->sortBy(fn ($r) => $r->item->orden) as $r)
    @php($p = $r->item->pregunta)
    @php($d = $r->desglose ?? [])
    @php($anulada = $r->item->anulada_en !== null)
    <section class="tarjeta item-informe">
        <div class="fila" style="justify-content:space-between;align-items:flex-start">
            <h2 style="margin:0;flex:1">{{ $loop->iteration }}. {{ $p->enunciado['texto'] }}</h2>
            <strong class="puntaje-item">
                @if ($anulada && $r->item->regla_anulacion === 'dar_por_buena') {{ $num($r->puntaje_max) }} / {{ $num($r->puntaje_max) }}
                @elseif ($anulada) no cuenta
                @else {{ $num($r->puntaje) }} / {{ $num($r->puntaje_max) }} @endif
            </strong>
        </div>
        @if ($anulada)
            <div class="aviso aviso-info">Ítem anulado para todo el grupo
                ({{ $r->item->regla_anulacion === 'dar_por_buena' ? 'se dio por bueno a todos' : 'se retiró y los demás ítems se reescalaron' }}): {{ $r->item->motivo_anulacion }}</div>
        @endif

        @switch($p->tipo)
            @case('unica')
                <p>Respondió: <strong>{{ isset($d['respuesta']) ? $d['respuesta'].') '.($p->enunciado['opciones'][$d['respuesta']] ?? '') : 'sin responder' }}</strong><br>
                    Correcta: <strong>{{ $d['correcta'] }}) {{ $p->enunciado['opciones'][$d['correcta']] ?? '' }}</strong></p>
                @break
            @case('vf')
                <p>Respondió: <strong>{{ is_bool($d['respuesta'] ?? null) ? ($d['respuesta'] ? 'Verdadero' : 'Falso') : 'sin responder' }}</strong> · Correcta: <strong>{{ $d['correcta'] ? 'Verdadero' : 'Falso' }}</strong></p>
                @break
            @case('multiple')
                <table><thead><tr><th>Opción</th><th>Marcó</th><th>Debía marcarse</th><th></th></tr></thead><tbody>
                    @foreach ($d['decisiones'] ?? [] as $x)<tr><td>{{ $x['opcion'] }}) {{ $p->enunciado['opciones'][$x['opcion']] ?? '' }}</td><td>{{ $x['marcada'] ? 'Sí' : 'No' }}</td><td>{{ $x['debia_marcarse'] ? 'Sí' : 'No' }}</td><td>{{ $x['acierto'] ? '✓' : '✗' }}</td></tr>@endforeach
                </tbody></table>
                @if ($d['sin_responder'] ?? false)<p class="suave">No marcó ninguna opción: cuenta como no respondida.</p>@endif
                @break
            @case('emparejar')
                <table><tbody>@foreach ($d['pares'] ?? [] as $x)<tr><td>{{ $p->enunciado['izquierda'][$x['elemento']] ?? $x['elemento'] }}</td><td>{{ $x['respuesta'] ? ($p->enunciado['derecha'][$x['respuesta']] ?? $x['respuesta']) : '—' }}</td><td>Correcta: {{ $p->enunciado['derecha'][$x['correcta']] ?? $x['correcta'] }}</td><td>{{ $x['acierto'] ? '✓' : '✗' }}</td></tr>@endforeach</tbody></table>
                @break
            @case('ordenar')
                <table><tbody>@foreach ($d['posiciones'] ?? [] as $x)<tr><td>{{ $x['posicion'] }}</td><td>{{ $x['respuesta'] ? ($p->enunciado['pasos'][$x['respuesta']] ?? '') : '—' }}</td><td>Correcto: {{ $p->enunciado['pasos'][$x['correcta']] ?? '' }}</td><td>{{ $x['acierto'] ? '✓' : '✗' }}</td></tr>@endforeach</tbody></table>
                @break
            @case('completar')
                <table><tbody>@foreach ($d['huecos'] ?? [] as $x)<tr><td>Hueco {{ $x['hueco'] }}</td><td><code>{{ $x['respuesta'] ?? '—' }}</code></td><td>Correcto: <code>{{ implode(' o ', (array) $x['correcta']) }}</code></td><td>{{ $x['acierto'] ? '✓' : '✗' }}</td></tr>@endforeach</tbody></table>
                <p class="suave">{{ $d['nota'] ?? '' }}</p>
                @break
            @case('abierta')
                <div class="texto-estudiante">{{ is_string($r->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '' }}</div>
                @include('calificador._rubrica', ['rubrica' => $d['rubrica'] ?? null])
                @if ($r->comentario)<p><strong>Comentario del docente:</strong> {{ $r->comentario }}</p>@endif
                @break
            @case('reto')
                @php($codigo = is_string($r->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '')
                <pre class="texto-estudiante" style="font-family:ui-monospace,Consolas,monospace">{{ $codigo }}</pre>
                @if (($p->enunciado['lenguaje'] ?? null) === 'html' && trim($codigo) !== '')@include('calificador._vista-html', ['codigo' => $codigo])@endif
                @if (! empty($d['verificaciones']))
                    <table><thead><tr><th>Verificación</th><th>Resultado</th><th class="num">Valor</th></tr></thead><tbody>
                        @foreach ($d['verificaciones'] as $v)<tr><td>{{ $v['nombre'] }}</td><td>{{ $v['superada'] ? '✓ Superada' : '✗ No superada' }}</td><td class="num">{{ $num($v['valor']) }}</td></tr>@endforeach
                    </tbody></table>
                @else <p class="suave">Pendiente de ejecutar en el calificador.</p>@endif
                @foreach ($d['casos'] ?? [] as $c)
                    <p class="suave" style="margin-bottom:0">Caso <code>{{ $c['entrada'] }}</code>@if ($c['esperada'] !== null) · esperada <code>{{ $c['esperada'] }}</code>@endif @if (($c['retorno'] ?? null) !== null) · obtuvo <code>{{ \Illuminate\Support\Str::limit((string) $c['retorno'], 200) }}</code>@endif @if ($c['error'])· error: {{ $c['error'] }}@endif</p>
                    @if ($c['consola'])<pre class="texto-estudiante" style="background:#111827;color:#e5e7eb;border:0">{{ implode("\n", $c['consola']) }}</pre>@endif
                @endforeach
                @break
        @endswitch
        @if ($p->justificacion)<p class="suave">{{ $p->justificacion }}</p>@endif
    </section>
@endforeach
