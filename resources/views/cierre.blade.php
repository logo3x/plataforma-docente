@php
    use App\Notas\ClasificacionCierre as C;
    $clases = [
        C::YaAprueba->value => ['estado-aprobado', '✓ Ya aprueba'],
        C::ApruebaPorRedondeo->value => ['estado-incompleto', '⚠ Aprueba solo por redondeo'],
        C::DependeDeLoPendiente->value => ['estado-incompleto', '… Depende de lo pendiente'],
        C::NoAlcanza->value => ['estado-reprobado', '✗ No alcanza'],
    ];
    $porcentaje = fn (string $f) => (string) \Brick\Math\BigDecimal::of($f)->multipliedBy(100)->strippedOfTrailingZeros();
@endphp
<x-app :titulo="'Cierre '.$grupo->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('registro', $grupo) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }} — {{ $grupo->nombre }}</a> › Cierre</div>
    <h1>Cierre del periodo — {{ $grupo->nombre }}</h1>
    <div class="aviso aviso-info" role="note">Esta vista <strong>solo calcula</strong>: no cambia ninguna nota.
        Decidir si se califica, se cita a supletorio o se aplica una habilitación es del docente; todo cambio queda en la bitácora.</div>

    <h2>¿Qué necesita cada estudiante para aprobar?</h2>
    <p class="suave">Acumulado = aportes de las divisiones ya completas. «Necesita» es el promedio mínimo en lo que falta
        para llegar a {{ $escala->mostrar($escala->aprobatoria) }}, redondeado hacia arriba. Un corte «incompleto» tiene notas,
        pero le falta algún componente: cuenta como pendiente hasta completarse. Primero aparecen los que están al margen.</p>
    <div class="tabla-notas">
        <table>
            <thead><tr><th class="estudiante">Estudiante</th><th class="num">Acumulado</th><th>Pendiente</th><th class="num">Necesita</th><th>Situación</th></tr></thead>
            <tbody>
                @foreach ($simulacion as $f)
                    @php($s = $f['simulacion'])
                    <tr>
                        <td class="estudiante"><a href="{{ route('estudiantes.historial', [$grupo, $f['estudiante']]) }}">{{ $f['estudiante']->nombreCompleto() }}</a></td>
                        <td class="num">{{ $escala->mostrar($f['acumulado']) }}</td>
                        <td>@if ($f['pendientes']){{ implode(', ', $f['pendientes']) }} ({{ $porcentaje($f['pendiente']) }} %)@else — @endif</td>
                        <td class="num">
                            @if ($s->notaNecesaria !== null && $s->clasificacion === C::DependeDeLoPendiente)
                                <strong>{{ $escala->meta($s->notaNecesaria) }}</strong>
                                <span class="suave" title="Valor exacto">({{ \Brick\Math\BigDecimal::of($s->notaNecesaria)->toScale(2, \Brick\Math\RoundingMode::Ceiling) }})</span>
                            @elseif ($s->clasificacion === C::NoAlcanza && $s->notaNecesaria !== null)
                                {{ $escala->mostrar($s->notaNecesaria) }} &gt; {{ $escala->mostrar($escala->maximo) }}
                            @else — @endif
                        </td>
                        <td>
                            <span class="estado {{ $clases[$s->clasificacion->value][0] }}">{{ $clases[$s->clasificacion->value][1] }}</span>
                            @if ($s->alMargen) <span class="estado estado-incompleto">◎ Al margen</span> @endif
                            @if ($f['beneficio_vacias'])
                                <div class="redondeo">⚠ Aprueba un corte solo porque una celda vacía no promedia: revise si corresponde supletorio.</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2>Ceros y vacías para revisar</h2>
    <p class="suave">Ordenados por cercanía de la definitiva del estudiante a {{ $escala->mostrar($escala->aprobatoria) }}:
        un cero de alguien en 2.9 pesa más que el de alguien en 0.2. <strong>Cero</strong>: tiene 0 registrado.
        <strong>Vacía</strong>: la actividad ya se aplicó y él no tiene nota (no promedia; ¿entrega mal ubicada o supletorio?).</p>
    @if ($ceros)
        <table>
            <thead><tr><th>Estudiante</th><th>Actividad</th><th>Tipo</th><th class="num">Definitiva de la división</th><th></th></tr></thead>
            <tbody>
                @foreach ($ceros as $c)
                    <tr>
                        <td>{{ $c['estudiante']->nombreCompleto() }}</td>
                        <td>{{ $c['division'] }} › {{ $c['componente'] }} › {{ $c['actividad'] }}</td>
                        <td>@if ($c['tipo'] === 'cero')<span class="estado estado-reprobado">0 Cero</span>@else<span class="estado estado-sin_actividades">∅ Vacía</span>@endif</td>
                        <td class="num">{{ $c['definitiva'] === null ? '—' : $escala->mostrar($c['definitiva']) }}</td>
                        <td><a href="{{ route('registro', [$grupo, 'vista' => $c['division_id']]) }}">Ir al registro</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>No hay ceros ni vacías en actividades aplicadas.</strong></div>
    @endif
</x-app>
