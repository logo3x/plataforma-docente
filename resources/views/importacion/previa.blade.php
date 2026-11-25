<x-app titulo="Vista previa de la lista">
    <div class="migas"><a href="{{ route('dictados.show', $grupo->dictado) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }}</a> › {{ $grupo->nombre }} › Vista previa</div>
    <h1>Vista previa — {{ $grupo->nombre }}</h1>

    <div class="tarjeta">
        <p style="margin-top:0"><strong>{{ $resumen['total'] }}</strong> filas:
            <strong>{{ $resumen['nuevos'] }}</strong> estudiantes nuevos,
            <strong>{{ $resumen['existentes'] }}</strong> ya existentes,
            <strong>{{ $resumen['con_errores'] }}</strong> con errores (no se importarán),
            <strong>{{ $resumen['con_advertencias'] }}</strong> con advertencias.
            @if ($resumen['ausentes']) <strong>{{ $resumen['ausentes'] }}</strong> del grupo ya no aparecen en el archivo. @endif
        </p>
        <form method="POST" action="{{ route('importacion.confirmar', $grupo) }}" class="fila">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button class="boton" type="submit" @disabled($resumen['nuevos'] + $resumen['existentes'] === 0)>
                Confirmar e importar {{ $resumen['nuevos'] + $resumen['existentes'] }} estudiantes
            </button>
            <a class="boton boton-sec" href="{{ route('importacion.create', $grupo) }}">Cancelar</a>
        </form>
    </div>

    <table>
        <caption class="sr-only">Filas del archivo</caption>
        <thead><tr><th class="num">Fila</th><th>Documento</th><th>Apellidos</th><th>Nombres</th><th>Resultado</th><th>Observaciones</th></tr></thead>
        <tbody>
            @foreach ($filas as $f)
                <tr>
                    <td class="num">{{ $f->numero }}</td>
                    <td>{{ $f->documento }}</td>
                    <td>{{ $f->apellidos }}</td>
                    <td>{{ $f->nombres }}</td>
                    <td>
                        @if (! $f->importable()) <span class="estado estado-reprobado">✗ No se importa</span>
                        @elseif ($f->estado === 'nuevo') <span class="estado estado-aprobado">+ Nuevo</span>
                        @else <span class="estado estado-sin_actividades">= Ya existe</span>
                        @endif
                    </td>
                    <td>
                        @foreach ($f->errores as $e)<div class="error">{{ $e }}</div>@endforeach
                        @foreach ($f->advertencias as $a)<div>⚠ {{ $a }}</div>@endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($ausentes)
        <h2>Estaban en el grupo y no aparecen en el archivo</h2>
        <p class="suave">No se borran: conservan sus notas. Revise si cancelaron o cambiaron de grupo.</p>
        <ul>@foreach ($ausentes as $e)<li>{{ $e->documento }} — {{ $e->nombreCompleto() }}</li>@endforeach</ul>
    @endif
</x-app>
