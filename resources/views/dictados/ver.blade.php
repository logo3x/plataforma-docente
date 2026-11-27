<x-app :titulo="$dictado->materia->nombre.' '.$dictado->periodo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $dictado->materia->nombre }} › {{ $dictado->periodo }}</div>
    <h1>{{ $dictado->materia->nombre }} <span class="suave">{{ $dictado->periodo }}</span></h1>
    <p class="suave">Escala {{ $dictado->escala()->mostrar($dictado->escala_min) }} a {{ $dictado->escala()->mostrar($dictado->escala_max) }},
        aprueba con {{ $dictado->escala()->mostrar($dictado->nota_aprobatoria) }} (<a href="{{ route('dictados.estructura', $dictado) }}#escala_min">cambiar</a>).
        <a href="{{ route('historial.index', ['materia' => $dictado->materia, 'dictado' => $dictado->id]) }}">Historial de actividades del periodo</a></p>
    @if ($r = \App\Models\Restauracion::query()->where('dictado_id', $dictado->id)->latest()->first())
        <div class="aviso aviso-info">Periodo restaurado el {{ $r->created_at->format('Y-m-d') }} desde un respaldo de {{ $r->periodo_original }}@if ($r->generado_en) generado el {{ $r->generado_en->format('Y-m-d H:i') }}@endif.
            @if (count($r->bitacora_original)) <a href="{{ route('restauracion.bitacora', $dictado) }}">Ver la bitácora original ({{ count($r->bitacora_original) }} cambios)</a>@endif</div>
    @endif

    <h2>Grupos</h2>
    @php($enDos = app(\App\Estudiantes\EnDosGrupos::class)->cuantosEnDictado($dictado))
    @if ($enDos)
        <div class="aviso aviso-info" role="note">⚠ {{ $enDos === 1 ? 'Un estudiante aparece' : "{$enDos} estudiantes aparecen" }} en dos grupos de este periodo.
            <a href="{{ route('dos-grupos.index', $dictado) }}">Decidir en cuál queda cada uno</a></div>
    @endif
    <div class="rejilla">
        @foreach ($dictado->grupos as $grupo)
            <div class="tarjeta">
                <h3 style="margin-top:0">{{ $grupo->nombre }}</h3>
                <p>{{ $grupo->estudiantes_count }} estudiantes @if ($grupo->classroom_curso_nombre) · <a href="{{ route('classroom.grupo', $grupo) }}">Classroom: {{ $grupo->classroom_curso_nombre }}</a>@endif</p>
                <div class="fila">
                    <a class="boton" href="{{ route('registro', $grupo) }}">Registro de notas</a>
                    <a class="boton boton-sec" href="{{ route('calendario.grupo', $grupo) }}">Calendario</a>
                    <a class="boton boton-sec" href="{{ route('importacion.create', $grupo) }}">
                        {{ $grupo->estudiantes_count ? 'Actualizar lista' : 'Cargar lista' }}
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    <h2>Estructura de evaluación</h2>
    <p><a href="{{ route('dictados.estructura', $dictado) }}">Editar la estructura</a> <span class="suave">— nombres, pesos y fechas, aunque ya haya notas</span></p>
    <table>
        <thead><tr><th>División</th><th class="num">Peso</th><th>Fechas</th><th>Componentes</th></tr></thead>
        <tbody>
            @foreach ($dictado->divisiones as $d)
                <tr>
                    <td>{{ $d->nombre }}</td>
                    <td class="num">{{ $d->porcentaje() }} %</td>
                    <td>@if ($d->fecha_inicio || $d->fecha_fin){{ $d->fecha_inicio?->format('Y-m-d') ?? '…' }} a {{ $d->fecha_fin?->format('Y-m-d') ?? '…' }}@else<span class="suave">—</span>@endif</td>
                    <td>@foreach ($d->componentes as $c){{ $c->nombre }} ({{ $c->porcentaje() }} %)@if (! $loop->last), @endif @endforeach</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Segundas oportunidades</h2>
    <p>Supletorios, recuperaciones y habilitaciones del periodo: <a href="{{ route('segundas.index', $dictado) }}">ver la lista</a>.
        Se registran desde el registro de notas, con el enlace «2ª oportunidad» junto a cada estudiante.</p>

    <h2>Respaldo</h2>
    <p>Descargue un archivo con toda la información del periodo: estructura, estudiantes, notas y bitácora de cambios.
        Es un JSON legible por cualquier herramienta. <a href="{{ route('respaldo', $dictado) }}">Descargar respaldo</a></p>

    <h2>Siguiente periodo</h2>
    <form method="POST" action="{{ route('dictados.store', $dictado) }}" class="tarjeta">
        @csrf
        <p class="suave" style="margin-top:0">Abre un dictado nuevo con la misma estructura y los mismos grupos. No copia estudiantes ni notas.</p>
        <div class="fila">
            <div><label for="periodo">Periodo</label><input id="periodo" name="periodo" type="text" required maxlength="20" placeholder="2027A"></div>
            <button class="boton boton-sec" type="submit" style="align-self:flex-end">Crear dictado</button>
        </div>
        @error('periodo') <div class="error" role="alert">{{ $message }}</div> @enderror
    </form>
</x-app>
