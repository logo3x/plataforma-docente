<x-app titulo="Revisar el respaldo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('restauracion.create') }}">Restaurar un respaldo</a> › Revisar</div>
    <h1>Revise el respaldo antes de restaurarlo</h1>

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>No se puede restaurar este archivo:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        <p><a class="boton boton-sec" href="{{ route('restauracion.create') }}">Subir otro archivo</a></p>
    @else
        @if ($avisos)
            <div class="aviso aviso-info"><ul style="margin:0">@foreach ($avisos as $a)<li>{{ $a }}</li>@endforeach</ul></div>
        @endif
        <section class="tarjeta">
            <h2 style="margin-top:0">{{ $resumen['materia'] }} · {{ $resumen['periodo'] }}</h2>
            <p class="suave">Respaldo @if ($resumen['generado_en'])generado el {{ \Illuminate\Support\Carbon::parse($resumen['generado_en'])->format('Y-m-d H:i') }}@endif (formato versión {{ $resumen['version'] }}).</p>
            <table>
                <tbody>
                    <tr><th scope="row">Grupos</th><td class="num">{{ $resumen['grupos'] }}</td></tr>
                    <tr><th scope="row">Estudiantes</th><td class="num">{{ $resumen['estudiantes'] }}</td></tr>
                    <tr><th scope="row">Cortes</th><td class="num">{{ $resumen['cortes'] }}</td></tr>
                    <tr><th scope="row">Actividades</th><td class="num">{{ $resumen['actividades'] }}</td></tr>
                    <tr><th scope="row">Notas</th><td class="num">{{ $resumen['notas'] }}</td></tr>
                    <tr><th scope="row">Segundas oportunidades</th><td class="num">{{ $resumen['segundas'] }}</td></tr>
                    <tr><th scope="row">Cambios en la bitácora original</th><td class="num">{{ $resumen['bitacora'] }}</td></tr>
                </tbody>
            </table>
        </section>
        <form method="POST" action="{{ route('restauracion.restaurar') }}" class="tarjeta">
            @csrf
            <input type="hidden" name="sha" value="{{ $sha }}">
            @if ($resumen['periodo_ocupado'])
                <div class="aviso aviso-info">La materia ya tiene el periodo {{ $resumen['periodo'] }}: restáurelo con otro nombre. El existente no se toca.</div>
            @endif
            <div class="campo"><label for="periodo">Nombre del periodo restaurado</label>
                <input id="periodo" name="periodo" type="text" maxlength="20" required value="{{ $resumen['periodo_ocupado'] ? $resumen['periodo'].'-R' : $resumen['periodo'] }}" style="max-width:14rem"></div>
            <p class="suave">Cada nota queda en la bitácora del periodo nuevo como restaurada; la bitácora original se guarda tal cual, para consulta.</p>
            <button class="boton" type="submit">Restaurar como periodo nuevo</button>
            <a href="{{ route('restauracion.create') }}">Cancelar</a>
        </form>
    @endif
</x-app>
