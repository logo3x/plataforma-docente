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
                                @if (isset($r->detalle['clave_nueva']))
                                    @php($campo = array_key_first($r->detalle['clave_nueva']))
                                    <div class="suave">Clave corregida: <code>{{ \Illuminate\Support\Str::limit(json_encode($r->detalle['clave_anterior'][$campo] ?? '', JSON_UNESCAPED_UNICODE), 160) }}</code> → <code>{{ \Illuminate\Support\Str::limit(json_encode($r->detalle['clave_nueva'][$campo], JSON_UNESCAPED_UNICODE), 160) }}</code></div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Ítems anulados para todo el grupo</h2>
    @if ($anulaciones->isEmpty())
        <p class="suave">Ninguno. Un ítem se anula desde el reclamo que lo pide o desde las estadísticas de la actividad.</p>
    @else
        <table>
            <thead><tr><th>Fecha</th><th>Examen</th><th>Ítem</th><th>Regla</th><th>Motivo</th></tr></thead>
            <tbody>
                @foreach ($anulaciones as $ap)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($ap->anulada_en)->format('Y-m-d') }}</td>
                        <td>{{ $ap->artefacto->sigla }} · {{ $ap->artefacto->titulo }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($ap->pregunta->enunciado['texto'] ?? '', 80) }}</td>
                        <td>{{ $ap->regla_anulacion === 'dar_por_buena' ? 'Se dio por buena a todos' : 'Se retiró y se reescaló' }}</td>
                        <td>{{ $ap->motivo_anulacion }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-app>
