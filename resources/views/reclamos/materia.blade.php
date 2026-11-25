@php use App\Models\Reclamo as R; @endphp
<x-app :titulo="'Reclamos — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Registro de reclamos</div>
    <h1>Registro de reclamos — {{ $materia->nombre }}</h1>
    <p class="suave">Todos los periodos. Lo que prosperó señala un error del examen que conviene no repetir: la IA recibe estos casos
        como errores a evitar cuando genera actividades nuevas de esta materia.</p>

    @php($aceptados = $reclamos->where('estado', 'aceptado'))
    <p><strong>{{ $reclamos->count() }}</strong> reclamos · <strong>{{ $aceptados->count() }}</strong> aceptados ·
        <strong>{{ $reclamos->where('estado', 'rechazado')->count() }}</strong> rechazados · <strong>{{ $reclamos->where('estado', 'abierto')->count() }}</strong> abiertos</p>

    @if ($reclamos->isEmpty())
        <div class="tarjeta vacio"><strong>Sin reclamos.</strong>Se registran desde el calificador de cada actividad.</div>
    @else
        <table>
            <thead><tr><th>Fecha</th><th>Periodo › actividad</th><th>Ítem</th><th>Alegato</th><th>Decisión y corrección aplicada</th></tr></thead>
            <tbody>
                @foreach ($reclamos as $r)
                    <tr>
                        <td>{{ $r->created_at->format('Y-m-d') }}</td>
                        <td>{{ $r->actividad->componente->division->dictado->periodo }} ›
                            <a href="{{ route('calificador.reclamos', $r->actividad) }}#reclamo-{{ $r->id }}">{{ $r->actividad->nombre }}</a></td>
                        <td>{{ $r->item ? \Illuminate\Support\Str::limit($r->item->pregunta->enunciado['texto'], 80) : '—' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($r->descripcion, 120) }}</td>
                        <td>
                            @if ($r->estado === 'abierto')<span class="estado estado-incompleto">Abierto</span>
                            @elseif ($r->estado === 'rechazado')<span class="estado estado-sin_actividades">Rechazado</span> {{ $r->resolucion }}
                            @else<span class="estado estado-aprobado">{{ R::ACCIONES[$r->accion] }}</span> {{ $r->resolucion }}
                                @if (isset($r->detalle['clave_nueva']))<div class="suave">Clave corregida: <code>{{ json_encode($r->detalle['clave_anterior']['correcta'] ?? $r->detalle['clave_anterior']['correctas'] ?? $r->detalle['clave_anterior']['huecos'] ?? '', JSON_UNESCAPED_UNICODE) }}</code> → <code>{{ json_encode(collect($r->detalle['clave_nueva'])->first(), JSON_UNESCAPED_UNICODE) }}</code></div>@endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-app>
