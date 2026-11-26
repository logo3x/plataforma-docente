@php($t = $actividad->classroom_tarea)
<x-app :titulo="'Classroom — '.$actividad->nombre">
    <div class="migas"><a href="{{ $actividad->artefacto_id ? route('calificador.show', $actividad) : route('calificador.archivos', $actividad) }}">{{ $actividad->nombre }}</a> › Classroom</div>
    <h1>Classroom — {{ $actividad->nombre }}</h1>
    @if ($errors->has('classroom'))<div class="aviso aviso-error" role="alert">{{ $errors->first('classroom') }}</div>@endif
    @if ($error)<div class="aviso aviso-error" role="alert">{{ $error }}</div>@endif

    @if (! $grupo)
        <div class="tarjeta vacio"><strong>El grupo de esta actividad no está vinculado con Classroom.</strong>
            <a href="{{ route('classroom.index') }}">Vincúlelo</a> primero con su curso.</div>
    @else
        <section class="tarjeta">
            <h2 style="margin-top:0">Tarea</h2>
            @if ($t)
                <p>Vinculada con <strong>{{ $t['titulo'] }}</strong> en {{ $grupo->classroom_curso_nombre }} · {{ $t['puntos'] ?? '—' }} puntos
                    @if ($t['enlace'])· <a href="{{ $t['enlace'] }}" target="_blank" rel="noopener">abrir en Classroom</a>@endif</p>
                @unless ($t['propia'])<div class="aviso aviso-info">Esta tarea se creó en Classroom, no desde la plataforma: se pueden <strong>leer</strong> sus entregas,
                    pero Classroom no deja que otra aplicación le ponga notas. Para enviar notas, cree la tarea desde aquí.</div>@endunless
            @endif
            <form method="POST" action="{{ route('classroom.actividad.vincular', $actividad) }}" x-data="{ tarea: @js($t['id'] ?? (count($tareas) ? $tareas[0]['id'] : 'nueva')) }">@csrf
                <label for="tarea">{{ $t ? 'Cambiar la tarea' : 'Tarea de Classroom' }}</label>
                <select id="tarea" name="tarea" x-model="tarea" style="max-width:32rem">
                    <option value="nueva">Crearla en Classroom desde la plataforma (permite enviar las notas)</option>
                    @foreach ($tareas as $x)<option value="{{ $x['id'] }}">{{ $x['titulo'] }}{{ $x['propia'] ? ' · creada desde la plataforma' : '' }}</option>@endforeach
                </select>
                <div x-show="tarea === 'nueva'">
                    <label for="descripcion">Instrucciones para los estudiantes (opcional)</label>
                    <textarea id="descripcion" name="descripcion" rows="2" maxlength="2000"></textarea>
                    <label style="font-weight:normal"><input type="checkbox" name="publicar" value="1"> Publicarla ya (si no, queda como borrador en Classroom)</label>
                </div>
                <p><button class="boton boton-sec" type="submit">Guardar</button></p>
            </form>
        </section>

        @if ($t)
            <section class="tarjeta">
                <h2 style="margin-top:0">Entregas</h2>
                <p class="suave">Trae lo entregado en la tarea y lo procesa como si lo hubiera subido: el examen se identifica por su sigla y su documento,
                    así que un archivo subido a la tarea equivocada se detecta.</p>
                <form method="POST" action="{{ route('classroom.actividad.importar', $actividad) }}">@csrf<button class="boton" type="submit">Traer las entregas de Classroom</button></form>
                @if (session('resultados'))
                    <ul style="margin-top:.75rem">@foreach (session('resultados') as $r)<li><span @class(['estado', 'estado-aprobado' => in_array($r['estado'], ['procesada', 'recibida'], true),
                        'estado-reprobado' => $r['estado'] === 'rechazada', 'estado-sin_actividades' => in_array($r['estado'], ['duplicada', 'sin_identificar'], true)])>{{ $r['estado'] }}</span> {{ $r['mensaje'] }}</li>@endforeach</ul>
                @endif
            </section>

            @if ($plan !== null)
                <section class="tarjeta">
                    <h2 style="margin-top:0">Notas hacia Classroom</h2>
                    <p class="suave">La nota vigente del registro (con supletorios y recuperaciones), convertida a los {{ $t['puntos'] ?? '—' }} puntos de la tarea.</p>
                    <div class="tabla-notas"><table>
                        <thead><tr><th>Estudiante</th><th class="num">Nota</th><th class="num">Puntos a enviar</th><th class="num">En Classroom</th></tr></thead>
                        <tbody>@foreach ($plan as $f)<tr>
                            <td>{{ $f['estudiante']->nombreCompleto() }}@unless ($f['entrega'])<span class="alerta-chip">sin entrega en Classroom</span>@endunless</td>
                            <td class="num">{{ $f['nota'] === null ? '—' : $escala->mostrar($f['nota']) }}</td>
                            <td class="num">{{ $f['puntos'] ?? '—' }}</td><td class="num">{{ $f['actual'] ?? '—' }}</td></tr>@endforeach</tbody>
                    </table></div>
                    @if ($t['propia'])
                        <form method="POST" action="{{ route('classroom.actividad.enviar', $actividad) }}" class="fila" style="margin-top:.75rem">@csrf
                            <button class="boton" type="submit" onclick="return confirm('¿Enviar las notas a Classroom?')">Enviar las notas</button>
                            <label style="font-weight:normal"><input type="checkbox" name="asignar" value="1"> También como asignadas (si no, quedan como borrador y usted las devuelve en Classroom)</label>
                        </form>
                        @if ($envios)<p class="suave">Último envío: {{ $envios->created_at->format('d/m/Y H:i') }}.</p>@endif
                    @endif
                </section>
            @endif
        @endif
    @endif
</x-app>
