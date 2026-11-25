<x-app :titulo="$dictado->materia->nombre.' '.$dictado->periodo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $dictado->materia->nombre }} › {{ $dictado->periodo }}</div>
    <h1>{{ $dictado->materia->nombre }} <span class="suave">{{ $dictado->periodo }}</span></h1>
    <p class="suave">Escala {{ $dictado->escala()->mostrar($dictado->escala_min) }} a {{ $dictado->escala()->mostrar($dictado->escala_max) }},
        aprueba con {{ $dictado->escala()->mostrar($dictado->nota_aprobatoria) }}.</p>

    <h2>Grupos</h2>
    <div class="rejilla">
        @foreach ($dictado->grupos as $grupo)
            <div class="tarjeta">
                <h3 style="margin-top:0">{{ $grupo->nombre }}</h3>
                <p>{{ $grupo->estudiantes_count }} estudiantes</p>
                <div class="fila">
                    <a class="boton" href="{{ route('registro', $grupo) }}">Registro de notas</a>
                    <a class="boton boton-sec" href="{{ route('importacion.create', $grupo) }}">
                        {{ $grupo->estudiantes_count ? 'Actualizar lista' : 'Cargar lista' }}
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    <h2>Estructura de evaluación</h2>
    <table>
        <thead><tr><th>División</th><th class="num">Peso</th><th>Componentes</th></tr></thead>
        <tbody>
            @foreach ($dictado->divisiones as $d)
                <tr>
                    <td>{{ $d->nombre }}</td>
                    <td class="num">{{ $d->porcentaje() }} %</td>
                    <td>@foreach ($d->componentes as $c){{ $c->nombre }} ({{ $c->porcentaje() }} %)@if (! $loop->last), @endif @endforeach</td>
                </tr>
            @endforeach
        </tbody>
    </table>

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
