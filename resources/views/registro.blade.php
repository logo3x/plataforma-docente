<x-app :titulo="'Notas '.$grupo->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('dictados.show', $grupo->dictado) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }}</a> ›
        Notas {{ $grupo->nombre }}</div>
    <h1>{{ $grupo->dictado->materia->nombre }} — {{ $grupo->nombre }}</h1>
    <livewire:registro-notas :grupo="$grupo" />
</x-app>
