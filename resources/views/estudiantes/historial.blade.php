@php
    use App\Notas\ClasificacionCierre as C;
    $dictado = $grupo->dictado;
    $clases = [C::YaAprueba->value => ['estado-aprobado', '✓ Ya aprueba'], C::ApruebaPorRedondeo->value => ['estado-incompleto', '⚠ Aprueba solo por redondeo'],
        C::DependeDeLoPendiente->value => ['estado-incompleto', '… Depende de lo pendiente'], C::NoAlcanza->value => ['estado-reprobado', '✗ No alcanza']];
    $iconos = ['nota' => '✎', 'entrega' => '⇪', 'alerta' => '⚑', 'reclamo' => '✉', 'segunda' => '↻', 'devolucion' => '◉', 'vivo' => '●'];
    $tipos = ['nota' => 'Notas', 'entrega' => 'Entregas', 'alerta' => 'Alertas', 'reclamo' => 'Reclamos', 'segunda' => 'Segundas oportunidades', 'devolucion' => 'Devoluciones', 'vivo' => 'En vivo'];
    $s = $simulacion['simulacion'] ?? null;
@endphp
<x-app :titulo="'Historial — '.$estudiante->nombreCompleto()">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('registro', $grupo) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }} — {{ $grupo->nombre }}</a> › Historial</div>
    <div class="fila" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h1 style="margin-bottom:.2rem">{{ $estudiante->nombreCompleto() }}</h1>
            <p class="suave" style="margin-top:0">Documento {{ $estudiante->documento }} · {{ $grupo->nombre }}
                @if ($otrosGrupos->isNotEmpty()) · también en {{ $otrosGrupos->pluck('nombre')->join(', ') }}@endif
                @unless ($activo) · <span class="estado estado-sin_actividades">Retirado del grupo</span>@endunless</p>
        </div>
        <div class="fila no-imprimir">
            <a class="boton boton-sec boton-chico" href="{{ route('estudiantes.edit', [$grupo, $estudiante]) }}">Editar datos</a>
            <a class="boton boton-sec boton-chico" href="{{ route('segundas.create', [$grupo, $estudiante]) }}">Segunda oportunidad</a>
            <button class="boton boton-sec boton-chico" onclick="window.print()">Imprimir</button>
            <a class="boton boton-sec boton-chico" href="{{ route('estudiantes.bitacora', [$grupo, $estudiante]) }}">Bitácora de sus notas (CSV)</a>
        </div>
    </div>
    <p class="suave">Vista del docente: no se proyecta ni se entrega al estudiante (para eso está el informe de cada actividad).</p>

    <section class="tarjeta">
        <h2 style="margin-top:0">Situación en el periodo</h2>
        <div class="tabla-notas"><table>
            <thead><tr>@foreach ($porDivision as $d)<th class="num">{{ $d['division']->nombre }} ({{ $d['division']->porcentaje() }} %)</th>@endforeach
                <th class="num">Acumulado</th><th>Estado</th></tr></thead>
            <tbody><tr>
                @foreach ($porDivision as $d)
                    @php($r = $d['resultado'])
                    <td class="num">@if ($r && $r->definitiva !== null)<strong>{{ $escala->mostrar($r->definitiva) }}</strong>
                        @if ($r->segunda)<span class="etiqueta-tipo" title="{{ \App\Models\SegundaOportunidad::TIPOS[$r->segunda->tipo] }}; original {{ $escala->mostrar($r->original) }}">{{ $r->segunda->letra() }}</span>@endif
                        <div class="suave">{{ $r->estado->etiqueta() }}</div>@else — @endif</td>
                @endforeach
                <td class="num definitiva">{{ $escala->mostrar($consolidado['acumulado']) }}</td>
                <td><span class="estado estado-{{ $consolidado['estado']->value }}">{{ $consolidado['estado']->etiqueta() }}</span></td>
            </tr></tbody>
        </table></div>
        @if ($s)
            <p style="margin-bottom:0"><span class="estado {{ $clases[$s->clasificacion->value][0] }}">{{ $clases[$s->clasificacion->value][1] }}</span>
                @if ($s->alMargen)<span class="estado estado-incompleto">◎ Al margen</span>@endif
                @if ($s->notaNecesaria !== null && $s->clasificacion === C::DependeDeLoPendiente)
                    Necesita <strong>{{ $escala->meta($s->notaNecesaria) }}</strong> en promedio en lo pendiente: {{ implode(', ', $simulacion['pendientes']) }}.
                @elseif ($s->clasificacion === C::NoAlcanza && $s->notaNecesaria !== null)
                    Necesitaría {{ $escala->mostrar($s->notaNecesaria) }} en lo pendiente, más que la nota máxima.
                @endif
                @if ($simulacion['beneficio_vacias'])<span class="redondeo">⚠ Aprueba un corte solo porque una celda vacía no promedia: revise si corresponde supletorio.</span>@endif</p>
        @endif
        <div class="fila indicadores-vivo" style="margin-top:.75rem">
            <span><strong>{{ $resumen['con_nota'] }}</strong> de {{ $resumen['actividades'] }} actividades con nota</span>
            @if ($resumen['ceros'])<span><strong>{{ $resumen['ceros'] }}</strong> en cero</span>@endif
            <span><strong>{{ $resumen['entregas'] }}</strong> entregas</span>
            <span><strong>{{ $resumen['salidas'] }}</strong> salidas de ventana</span>
            <span><strong>{{ $resumen['alertas'] }}</strong> alertas</span>
            <span><strong>{{ $resumen['reclamos'] }}</strong> reclamos</span>
            <span><strong>{{ $resumen['segundas'] }}</strong> segundas oportunidades</span>
        </div>
    </section>

    <h2>Actividad por actividad</h2>
    @foreach ($porDivision as $d)
        @continue($d['actividades']->isEmpty())
        <h3>{{ $d['division']->nombre }}</h3>
        <div class="tabla-notas"><table class="historial-actividades">
            <thead><tr><th>Actividad</th><th class="num">Nota</th><th>Entrega</th><th>Alertas</th><th>Reclamos</th><th>Informe</th></tr></thead>
            <tbody>
                @foreach ($d['actividades'] as $a)
                    @php($act = $a['actividad'])
                    <tr>
                        <td>{{ $act->nombre }} <span class="suave">· {{ $act->componente->nombre }}</span></td>
                        <td class="num">@if ($a['efectiva'] !== null)<strong>{{ $escala->mostrar($a['efectiva']) }}</strong>
                            @if ($a['segunda'])<span class="etiqueta-tipo" title="{{ \App\Models\SegundaOportunidad::TIPOS[$a['segunda']->tipo] }}; original {{ $a['nota'] === null ? 'vacía' : $escala->mostrar($a['nota']) }}">{{ $a['segunda']->letra() }}</span>@endif
                            @elseif ($a['nota'] !== null){{ $escala->mostrar($a['nota']) }}@else<span class="suave">vacía</span>@endif</td>
                        <td>@forelse ($a['entregas'] as $en)
                                <a href="{{ $en->tipo === 'archivo' ? route('calificador.archivos.calificar', $en) : route('calificador.detalle', $en) }}">{{ $en->created_at->format('d/m H:i') }}</a>
                                <span class="suave">@if ($en->duracion_seg !== null)· {{ intdiv($en->duracion_seg, 60) }} min @endif
                                    @if ($en->version)· v. {{ $en->version }} @endif
                                    @if ($en->integrantes->count() > 1)· en grupo de {{ $en->integrantes->count() }}@endif</span>@if (! $loop->last)<br>@endif
                            @empty<span class="suave">—</span>@endforelse</td>
                        <td>@foreach ($a['alertas'] as $al)<span class="alerta-chip">{{ \App\Notas\HistorialEstudiante::ALERTAS[$al->tipo] ?? $al->tipo }}</span>@endforeach</td>
                        <td>@foreach ($a['reclamos'] as $rc)<span @class(['estado', 'estado-aprobado' => $rc->estado === 'aceptado', 'estado-reprobado' => $rc->estado === 'rechazado', 'estado-incompleto' => $rc->estado === 'abierto'])>{{ ucfirst($rc->estado) }}</span>@endforeach</td>
                        <td>@if ($a['devolucion'])@if ($a['devolucion']->abierta_en)<span title="Lo abrió {{ $a['devolucion']->aperturas }} vez/veces">✓ Abierto {{ $a['devolucion']->abierta_en->format('d/m') }}</span>@else<span class="suave">Liberado, sin abrir</span>@endif
                            @elseif ($act->artefacto_id || $a['entregas']->isNotEmpty())<a href="{{ route('calificador.devolucion.ver', [$act, $estudiante]) }}">Ver</a>@else — @endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endforeach

    <h2>Línea de tiempo</h2>
    @if ($linea->isEmpty())
        <div class="tarjeta vacio"><strong>Todavía no hay nada registrado.</strong>Aquí aparecerán sus notas, entregas, alertas y reclamos.</div>
    @else
        <div x-data="{ ver: 'todo' }">
            <div class="fila no-imprimir" role="group" aria-label="Filtrar la línea de tiempo" style="margin-bottom:.5rem">
                <button type="button" class="boton boton-chico" :class="ver === 'todo' ? '' : 'boton-sec'" x-on:click="ver = 'todo'" :aria-pressed="ver === 'todo'">Todo</button>
                @foreach ($linea->pluck('tipo')->unique() as $t)
                    <button type="button" class="boton boton-chico" :class="ver === '{{ $t }}' ? '' : 'boton-sec'" x-on:click="ver = '{{ $t }}'" :aria-pressed="ver === '{{ $t }}'">{{ $tipos[$t] }} ({{ $linea->where('tipo', $t)->count() }})</button>
                @endforeach
            </div>
            <ol class="linea-tiempo">
                @foreach ($linea as $ev)
                    <li x-show="ver === 'todo' || ver === '{{ $ev['tipo'] }}'" class="ev-{{ $ev['tipo'] }}">
                        <span class="icono" aria-hidden="true">{{ $iconos[$ev['tipo']] }}</span>
                        <time datetime="{{ $ev['cuando']->toIso8601String() }}">{{ $ev['cuando']->format('d/m/Y H:i') }}</time>
                        <span class="sr-only">{{ $tipos[$ev['tipo']] }}:</span>
                        <span>{{ $ev['texto'] }}</span>
                        @if ($ev['detalle'])<span class="suave"> — {{ $ev['detalle'] }}</span>@endif
                        @if (! empty($ev['enlace']))<a class="no-imprimir" href="{{ $ev['enlace'] }}">ver</a>@endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</x-app>
