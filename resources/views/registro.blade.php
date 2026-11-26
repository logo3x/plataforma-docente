<x-app :titulo="'Notas '.$grupo->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('dictados.show', $grupo->dictado) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }}</a> ›
        Notas {{ $grupo->nombre }}</div>
    <h1>{{ $grupo->dictado->materia->nombre }} — {{ $grupo->nombre }}</h1>
    @php($enDos = app(\App\Estudiantes\EnDosGrupos::class)->cuantos($grupo))
    @if ($enDos)
        <div class="aviso aviso-info" role="note">⚠ {{ $enDos === 1 ? 'Un estudiante de este grupo también está' : "{$enDos} estudiantes de este grupo también están" }} en otro grupo del periodo.
            <a href="{{ route('dos-grupos.index', $grupo->dictado) }}">Decidir en cuál queda</a></div>
    @endif
    <details class="tarjeta" style="padding:.6rem 1rem">
        <summary><strong>Temario de {{ $grupo->nombre }}</strong> — marque lo que ya vio el grupo</summary>
        <div style="margin-top:.5rem"><livewire:lista-chequeo :grupo="$grupo" /></div>
    </details>
    <livewire:registro-notas :grupo="$grupo" />
</x-app>
