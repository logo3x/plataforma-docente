@php($destinos = \App\Calificacion\BitacoraAula::DESTINOS)
@php($origenes = ['anulacion' => 'Ítem anulado', 'reclamo' => 'Reclamo aceptado', 'ensayo' => 'Ensayo', 'alertas' => 'Alertas'])
<x-app titulo="Bitácora de aula">
    <h1>Bitácora de retroalimentación de aula</h1>
    <p class="suave">Lo que aparece en cada aplicación, en una línea. Cada entrada se convierte, si procede, en <strong>lección aprendida</strong>
        o en <strong>requerimiento</strong>. Las lecciones que marque para la IA entran en los prompts de su materia.</p>

    @if ($sugerencias->isNotEmpty())
        <section class="tarjeta">
            <h2 style="margin-top:0">Para anotar ({{ $sugerencias->count() }})</h2>
            <p class="suave">Lo registró la plataforma en sus actividades. Anótelo para decidir qué hacer con ello, o descártelo.</p>
            <ul class="sugerencias-bitacora">
                @foreach ($sugerencias->take(15) as $s)
                    <li>
                        <span class="etiqueta-tipo">{{ $origenes[$s['origen']] }}</span> <span class="suave">{{ $s['fecha']->format('d/m/Y') }}</span>
                        {{ $s['observacion'] }}
                        <span class="fila" style="display:inline-flex;gap:.4rem">
                            @foreach ([false => 'Anotar', true => 'Descartar'] as $descartar => $texto)
                                <form method="POST" action="{{ route('bitacora.sugerencia') }}">@csrf
                                    <input type="hidden" name="origen" value="{{ $s['origen'] }}"><input type="hidden" name="clave" value="{{ $s['clave'] }}">
                                    <input type="hidden" name="descartar" value="{{ $descartar ? 1 : 0 }}">
                                    <button @class(['boton', 'boton-chico', 'boton-sec' => $descartar]) type="submit">{{ $texto }}</button></form>
                            @endforeach
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <form method="GET" action="{{ route('bitacora.index') }}" class="fila" style="margin:1rem 0">
        <label for="f-materia" style="margin:0">Materia</label>
        <select id="f-materia" name="materia" style="max-width:18rem"><option value="">Todas</option>
            @foreach ($materias as $m)<option value="{{ $m->id }}" @selected(($filtros['materia'] ?? '') == $m->id)>{{ $m->nombre }}</option>@endforeach</select>
        <label for="f-destino" style="margin:0">Destino</label>
        <select id="f-destino" name="destino" style="max-width:14rem"><option value="">Todos menos descartadas</option>
            @foreach ($destinos as $k => $t)<option value="{{ $k }}" @selected(($filtros['destino'] ?? '') === $k)>{{ $t }} ({{ $cuentas[$k] ?? 0 }})</option>@endforeach</select>
        <button class="boton boton-sec boton-chico" type="submit">Filtrar</button>
        <a class="boton boton-sec boton-chico" href="{{ route('bitacora.anexo', $filtros) }}">Descargar como anexo B</a>
    </form>

    @if ($entradas->isEmpty())
        <div class="tarjeta vacio"><strong>La bitácora está vacía.</strong>Anote abajo lo que observó en su última aplicación.</div>
    @else
        <div class="tabla-notas"><table class="bitacora">
            <thead><tr><th>Fecha</th><th>Materia y actividad</th><th>Observación</th><th>Destino</th><th></th></tr></thead>
            <tbody>
                @foreach ($entradas as $e)
                    <tr>
                        <td style="white-space:nowrap">{{ $e->fecha->format('d/m/Y') }}</td>
                        <td>{{ $e->materia?->nombre ?? 'General' }}@if ($e->actividad)<div class="suave">{{ $e->actividad->nombre }}</div>@endif</td>
                        <td>{{ $e->observacion }}@if ($e->leccion)<div class="leccion"><strong>Lección:</strong> {{ $e->leccion }}@if ($e->para_ia) <span class="etiqueta-tipo">la usa la IA</span>@endif</div>@endif</td>
                        <td><span @class(['estado', 'estado-incompleto' => $e->destino === 'pendiente', 'estado-aprobado' => $e->destino === 'leccion',
                            'estado-sin_actividades' => in_array($e->destino, ['requerimiento', 'descartada'], true)])>{{ $destinos[$e->destino] }}</span>
                            @if ($e->referencia)<div class="suave">{{ $e->referencia }}</div>@endif</td>
                        <td>
                            <details class="menu"><summary aria-label="Editar la entrada del {{ $e->fecha->format('d/m/Y') }}">Editar</summary>
                                <div class="contenido">
                                    <form method="POST" action="{{ route('bitacora.update', $e) }}">@csrf @method('PUT')
                                        @include('bitacora._campos', ['e' => $e, 'sufijo' => $e->id])
                                        <p><button class="boton boton-chico" type="submit">Guardar</button></p></form>
                                    <form method="POST" action="{{ route('bitacora.destroy', $e) }}" onsubmit="return confirm('¿Eliminar esta entrada?')">@csrf @method('DELETE')
                                        <button class="boton boton-peligro boton-chico" type="submit">Eliminar</button></form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif

    <section class="tarjeta" id="nueva" style="margin-top:1rem;max-width:48rem">
        <h2 style="margin-top:0">Nueva observación</h2>
        <form method="POST" action="{{ route('bitacora.store') }}">@csrf
            @include('bitacora._campos', ['e' => null, 'sufijo' => 'nueva', 'actividadFija' => $actividad])
            @foreach (['fecha', 'materia_id', 'observacion', 'destino', 'referencia', 'leccion'] as $campo)@error($campo)<div class="error" role="alert">{{ $message }}</div>@enderror @endforeach
            <p><button class="boton" type="submit">Anotar</button></p>
        </form>
    </section>
</x-app>
