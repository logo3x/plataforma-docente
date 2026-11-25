@php
    $pct = fn (?float $f) => $f === null ? '—' : round($f * 100).' %';
    $maxRango = max(1, max(array_column($resumen['rangos'], 'cantidad') ?: [0]));
    $tipos = \App\Evaluacion\GeneradorBanco::TIPOS;
    $params = array_filter(['grupo' => $grupo?->id]);
@endphp
<x-app :titulo="'Estadísticas — '.$actividad->nombre">
    @unless ($proyectar)
        <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Estadísticas</div>
    @endunless
    <h1>Estadísticas — {{ $actividad->nombre }} <span class="suave">· {{ $grupo?->nombre ?? 'todos los grupos' }}</span></h1>

    @unless ($proyectar)
        <div class="fila" style="margin-bottom:1rem">
            <form method="GET" class="fila">
                <label for="grupo" style="margin:0">Grupo</label>
                <select id="grupo" name="grupo" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    @foreach ($grupos as $g)<option value="{{ $g->id }}" @selected($grupo?->id === $g->id)>{{ $g->nombre }}</option>@endforeach
                </select>
            </form>
            <a class="boton boton-sec boton-chico" href="{{ route('calificador.estadisticas', [$actividad, ...$params, 'proyectar' => 1]) }}">Vista para proyectar</a>
            <a class="boton boton-sec boton-chico" href="{{ route('calificador.estadisticas.csv', [$actividad, ...$params]) }}">Exportar CSV</a>
        </div>
        <div class="aviso aviso-info" role="note">Estas cifras describen lo que pasó; no deciden por usted. Nada se anula ni se reajusta solo:
            anular un ítem es una acción suya, con motivo, que queda en la bitácora.</div>
    @else
        <p class="suave">Resumen anónimo del grupo. <a href="{{ route('calificador.estadisticas', [$actividad, ...$params]) }}">Salir de la vista para proyectar</a></p>
    @endunless

    @if ($resumen['n'] === 0)
        <div class="tarjeta vacio"><strong>Aún no hay entregas con nota completa.</strong>Las estadísticas aparecen cuando el calificador termina de calificar.</div>
    @else
        <div class="rejilla" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
            @foreach ([['Entregas', $resumen['n']], ['Promedio', $escala->mostrar($resumen['promedio'])], ['Más alta', $escala->mostrar($resumen['maxima'])],
                ['Más baja', $escala->mostrar($resumen['minima'])], ['Aprobaron', $resumen['aprobados'].' de '.$resumen['n']]] as [$etiqueta, $valor])
                <div class="tarjeta" style="text-align:center"><div class="suave">{{ $etiqueta }}</div><div style="font-size:1.6rem;font-weight:700">{{ $valor }}</div></div>
            @endforeach
        </div>

        <section class="tarjeta">
            <h2 style="margin-top:0">Distribución de notas</h2>
            <table class="barras" aria-label="Distribución de notas por rango">
                @foreach ($resumen['rangos'] as $r)
                    <tr>
                        <th scope="row" style="background:none;color:var(--texto);font-weight:400;white-space:nowrap">{{ $r['desde'] }} – {{ $r['hasta'] }}</th>
                        <td style="width:100%"><div class="barra-dato" style="width: {{ round(100 * $r['cantidad'] / $maxRango) }}%"></div></td>
                        <td class="num">{{ $r['cantidad'] }}</td>
                    </tr>
                @endforeach
            </table>
        </section>

        <h2>Análisis por ítem</h2>
        <p class="suave">Acierto: puntaje medio sobre el máximo del ítem. Discriminación: acierto del 27 % con mejor nota menos el del 27 % con peor nota
            (de −1 a 1; por debajo de {{ \App\Calificacion\EstadisticasActividad::DISCRIMINACION_MINIMA }} el ítem no separa a quien sabe de quien no).
            Se calcula con {{ \App\Calificacion\EstadisticasActividad::MINIMO_PARA_DISCRIMINAR }} entregas o más, y los ítems se marcan para revisión
            desde {{ \App\Calificacion\EstadisticasActividad::MINIMO_PARA_MARCAR }} respuestas.</p>

        @foreach ($items as $i)
            @php($ap = $i['item'])
            <section class="tarjeta" id="item-{{ $ap->id }}">
                <div class="fila" style="justify-content:space-between;align-items:flex-start">
                    <div style="flex:1">
                        <strong>{{ $i['numero'] }}. {{ $i['texto'] }}</strong>
                        <div class="suave">{{ $tipos[$i['tipo']] ?? $i['tipo'] }} · {{ $i['respuestas'] }} {{ $i['respuestas'] === 1 ? 'respuesta' : 'respuestas' }} @if ($i['tema'])· tema: {{ $i['tema'] }}@endif</div>
                    </div>
                    <div style="text-align:right;min-width:12rem">
                        <div>Acierto <strong>{{ $pct($i['acierto']) }}</strong></div>
                        <div class="barra-avance" style="margin:.2rem 0"><div style="width: {{ round(($i['acierto'] ?? 0) * 100) }}%"></div></div>
                        <div class="suave">Discriminación {{ $i['discriminacion'] === null ? '—' : number_format($i['discriminacion'], 2) }}</div>
                    </div>
                </div>

                @if ($ap->anulada_en)
                    <div class="aviso aviso-info" style="margin:.5rem 0">Anulado ({{ $ap->regla_anulacion === 'dar_por_buena' ? 'se da por bueno a todos' : 'retirado y reescalado' }}): {{ $ap->motivo_anulacion }}</div>
                @endif
                @foreach ($i['marcas'] as $m)<span class="estado estado-incompleto" style="margin:.4rem .3rem 0 0">◎ {{ $m }}</span>@endforeach

                @if ($i['opciones'])
                    <table class="barras" style="margin-top:.6rem" aria-label="Respuestas elegidas en el ítem {{ $i['numero'] }}">
                        @php($maxOp = max(1, max(array_column($i['opciones'], 'veces'))))
                        @foreach ($i['opciones'] as $k => $o)
                            @continue($k === '—' && $o['veces'] === 0)
                            <tr>
                                <th scope="row" style="background:none;color:var(--texto);font-weight:{{ $o['correcta'] ? 700 : 400 }};white-space:nowrap">
                                    {{ in_array($k, ['true', 'false', '—'], true) ? '' : $k.')' }} {{ \Illuminate\Support\Str::limit($o['texto'], 50) }} @if ($o['correcta'])✓ correcta @endif</th>
                                <td style="width:100%"><div class="barra-dato {{ $o['correcta'] ? 'barra-correcta' : '' }}" style="width: {{ round(100 * $o['veces'] / $maxOp) }}%"></div></td>
                                <td class="num">{{ $o['veces'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @unless ($proyectar)
                    <details style="margin-top:.6rem">
                        <summary>Acciones del docente</summary>
                        <div class="fila" style="margin-top:.5rem;align-items:flex-end">
                            <form method="POST" action="{{ route('calificador.items.tema', $ap) }}" class="fila">
                                @csrf <input type="hidden" name="actividad_id" value="{{ $actividad->id }}">
                                <label for="tema-{{ $ap->id }}" style="margin:0">Tema</label>
                                <select id="tema-{{ $ap->id }}" name="temario_item_id">
                                    <option value="">— sin tema —</option>
                                    @foreach ($temas as $t)<option value="{{ $t->id }}" @selected($ap->pregunta->temario_item_id === $t->id)>{{ $t->titulo }}</option>@endforeach
                                </select>
                                <button class="boton boton-sec boton-chico">Guardar</button>
                            </form>
                        </div>
                        @if ($ap->anulada_en)
                            <form method="POST" action="{{ route('calificador.items.restaurar', $ap) }}" style="margin-top:.5rem">
                                @csrf <input type="hidden" name="actividad_id" value="{{ $actividad->id }}">
                                <button class="boton boton-sec boton-chico" onclick="return confirm('¿Revertir la anulación? Las notas se recalculan.')">Revertir la anulación</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('calificador.items.anular', $ap) }}" class="tarjeta" style="box-shadow:none;margin-top:.5rem">
                                @csrf <input type="hidden" name="actividad_id" value="{{ $actividad->id }}">
                                <strong>Anular este ítem para todo el grupo</strong>
                                <p class="suave" style="margin:.25rem 0">Por ejemplo, un enunciado ambiguo que nadie respondió como se esperaba. Las notas se recalculan
                                    y las que ya estaban en el registro se actualizan, con el motivo en la bitácora.</p>
                                <label style="font-weight:400"><input type="radio" name="regla" value="dar_por_buena" checked> Darlo por bueno a todos</label>
                                <label style="font-weight:400"><input type="radio" name="regla" value="retirar_y_reescalar"> Retirarlo y reescalar los demás ítems al valor total</label>
                                <label for="motivo-{{ $ap->id }}">Motivo (lo verá el estudiante en su detalle)</label>
                                <input id="motivo-{{ $ap->id }}" name="motivo" type="text" required minlength="5" maxlength="300" placeholder="Enunciado ambiguo: admite dos respuestas">
                                <button class="boton boton-peligro boton-chico" style="margin-top:.5rem" onclick="return confirm('¿Anular el ítem {{ $i['numero'] }}?')">Anular ítem</button>
                            </form>
                        @endif
                    </details>
                @endunless
            </section>
        @endforeach
    @endif
</x-app>
