<x-app titulo="Mis materias">
    <div class="fila" style="justify-content: space-between">
        <h1>Mis materias</h1>
        <div class="fila">
            <a class="boton boton-sec" href="{{ route('restauracion.create') }}">Restaurar un respaldo</a>
            <a class="boton" href="{{ route('materias.create') }}">Nueva materia</a>
        </div>
    </div>

    @forelse ($materias as $materia)
        <section class="tarjeta" aria-labelledby="materia-{{ $materia->id }}">
            <h2 id="materia-{{ $materia->id }}" style="margin-top:0">
                {{ $materia->nombre }} @if ($materia->codigo)<span class="suave">· {{ $materia->codigo }}</span>@endif
            </h2>
            <p style="margin:.25rem 0">
                <a href="{{ route('artefactos.index', $materia) }}">Actividades evaluables y calificador</a> ·
                <a href="{{ route('historial.index', $materia) }}">Historial</a> ·
                <a href="{{ route('generacion.create', $materia) }}">Generar con IA</a> ·
                <a href="{{ route('rubricas.index', $materia) }}">Rúbricas</a> ·
                <a href="{{ route('temario.show', $materia) }}">Temario</a> ·
                <a href="{{ route('unidades.index', $materia) }}">Unidades didácticas</a> ·
                <a href="{{ route('apuntes.index', $materia) }}">Textos y apuntes</a> ·
                <a href="{{ route('ejemplos.index', $materia) }}">Ejemplos prácticos</a> ·
                <a href="{{ route('debates.index', $materia) }}">Preguntas de debate</a> ·
                <a href="{{ route('vocabularios.index', $materia) }}">Vocabulario</a> ·
                <a href="{{ route('proyectos.index', $materia) }}">Ideas de proyectos</a> ·
                <a href="{{ route('reclamos.materia', $materia) }}">Reclamos</a> ·
                <a href="{{ route('contenido.index', $materia) }}">Contenido</a> ·
                <a href="{{ route('materias.estilo', $materia) }}">Estilo para la IA</a>
            </p>
            <ul>
                @foreach ($materia->dictados as $dictado)
                    <li>
                        <a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->periodo }}</a>
                        — {{ $dictado->estudiantes_count }} estudiantes
                        @foreach ($dictado->grupos as $grupo)
                            · <a href="{{ route('registro', $grupo) }}">Notas {{ $grupo->nombre }}</a>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="tarjeta vacio">
            <strong>Aún no tiene materias.</strong>
            Cree la primera: en un solo paso define el periodo, los cortes con sus pesos y los grupos;
            después carga la lista de cada grupo con la plantilla de secretaría.
            <p><a class="boton" href="{{ route('materias.create') }}">Crear materia</a></p>
        </div>
    @endforelse
</x-app>
