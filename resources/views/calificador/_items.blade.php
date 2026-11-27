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

        {{-- Sin desglose (p. ej. una entrega traída de otra herramienta) se muestra el puntaje sin el detalle, en vez de fallar --}}
        @if ($d === [] && ! in_array($p->tipo, ['abierta', 'reto'], true))
            <p class="suave">No hay detalle guardado de esta respuesta; solo el puntaje.</p>
        @else
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
                @php($codigo = \App\Calificacion\RevisionEstatica::texto($r->respuesta['valor'] ?? null))
                <pre class="texto-estudiante" style="font-family:ui-monospace,Consolas,monospace">{{ $codigo }}</pre>
                @if (($p->enunciado['lenguaje'] ?? null) === 'html' && trim($codigo) !== '')@include('calificador._vista-html', ['codigo' => $codigo])@endif
                @if (! empty($d['estatica']))
                    {{-- 4.6: la revisión estática no se presenta como ejecución --}}
                    <p class="aviso aviso-info" style="margin:.5rem 0"><strong>Revisión estática, sin ejecutar el código.</strong>
                        {{ $d['alcance'] ?? \App\Calificacion\RevisionEstatica::ALCANCE }}</p>
                @endif
                @if (! empty($d['verificaciones']))
                    {{-- Decisión 8: en el informe del estudiante, las reservadas completas o solo su cantidad.
                         Las entregas calificadas antes de guardar la marca se reconocen por el nombre en la clave. --}}
                    @php($nombresReservadas = collect($p->clave['verificaciones'] ?? [])->filter(fn ($x) => ! empty($x['reservada']))->pluck('nombre')->all())
                    @php($esReservada = fn ($v) => (bool) ($v['reservada'] ?? in_array($v['nombre'], $nombresReservadas, true)))
                    @php($soloCantidad = ($reservadas ?? 'completas') === 'cantidad')
                    @php($filas = collect($d['verificaciones'])->reject(fn ($v) => $soloCantidad && $esReservada($v)))
                    @php($ocultas = $soloCantidad ? collect($d['verificaciones'])->filter($esReservada) : collect())
                    @php($si = empty($d['estatica']) ? ['✓ Superada', '✗ No superada'] : ['✓ Cumple', '✗ No cumple'])
                    <table><thead><tr><th>{{ empty($d['estatica']) ? 'Verificación' : 'Criterio revisado' }}</th><th>Resultado</th><th class="num">Valor</th></tr></thead><tbody>
                        @foreach ($filas as $v)<tr><td>{{ $v['nombre'] }}@if ($esReservada($v)) <span class="etiqueta-tipo">reservada</span>@endif @if (! empty($v['nota']))<div class="suave">{{ $v['nota'] }}</div>@endif</td>
                            <td>{{ $v['superada'] ? $si[0] : $si[1] }}</td><td class="num">{{ $num($v['valor']) }}</td></tr>@endforeach
                        @if ($ocultas->isNotEmpty())
                            <tr class="fila-reservadas"><td>{{ $ocultas->count() === 1 ? 'Una verificación reservada' : $ocultas->count().' verificaciones reservadas' }}
                                <div class="suave">No se nombran: el examen se sigue usando.</div></td>
                                <td>{{ empty($d['estatica']) ? 'Superó' : 'Cumplió' }} {{ $ocultas->where('superada', true)->count() }} de {{ $ocultas->count() }}</td>
                                <td class="num">{{ $num((string) $ocultas->where('superada', true)->sum(fn ($v) => (float) $v['valor'])) }} de {{ $num((string) $ocultas->sum(fn ($v) => (float) $v['valor'])) }}</td></tr>
                        @endif
                    </tbody></table>
                @else <p class="suave">Pendiente de ejecutar en el calificador.</p>@endif
                @foreach ($d['casos'] ?? [] as $c)
                    <p class="suave" style="margin-bottom:0">Caso <code>{{ $c['entrada'] }}</code>@if ($c['esperada'] !== null) · esperada <code>{{ $c['esperada'] }}</code>@endif @if (($c['retorno'] ?? null) !== null) · obtuvo <code>{{ \Illuminate\Support\Str::limit((string) $c['retorno'], 200) }}</code>@endif @if ($c['error'])· error: {{ $c['error'] }}@endif</p>
                    @if ($c['consola'])<pre class="texto-estudiante" style="background:#111827;color:#e5e7eb;border:0">{{ implode("\n", $c['consola']) }}</pre>@endif
                @endforeach
                @break
        @endswitch
        @endif
        @if ($p->justificacion)<p class="suave">{{ $p->justificacion }}</p>@endif
    </section>
@endforeach
