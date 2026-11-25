<x-app titulo="Importación terminada">
    <div class="migas"><a href="{{ route('dictados.show', $grupo->dictado) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }}</a> › {{ $grupo->nombre }}</div>
    <h1>Importación terminada — {{ $grupo->nombre }}</h1>
    <div class="aviso aviso-ok" role="status">{{ $creados }} estudiantes creados y {{ $actualizados }} actualizados.</div>

    @if ($omitidos)
        <h2>No se importaron</h2>
        <ul>@foreach ($omitidos as $f)<li>Fila {{ $f->numero }} ({{ $f->documento ?: 'sin documento' }}): {{ implode(' ', $f->errores) }}</li>@endforeach</ul>
    @endif
    @if ($revisar)
        <h2>Pendientes de revisión</h2>
        <ul>@foreach ($revisar as $f)<li>Fila {{ $f->numero }} — {{ $f->apellidos }} {{ $f->nombres }}: {{ implode(' ', $f->advertencias) }}</li>@endforeach</ul>
    @endif

    <p><a class="boton" href="{{ route('registro', $grupo) }}">Ir al registro de notas</a></p>
</x-app>
