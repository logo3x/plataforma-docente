@props(['titulo' => null])
<x-layouts.base :titulo="$titulo">
    <nav class="barra" aria-label="Principal">
        <a href="{{ route('inicio') }}" class="marca">Plataforma educativa</a>
        <a href="{{ route('inicio') }}">Mis materias</a>
        <a href="{{ route('ia.motores') }}">Motores de IA</a>
        <a href="{{ route('ia.plantillas') }}">Plantillas de IA</a>
        <div class="derecha">
            <a href="{{ route('seguridad') }}">Seguridad</a>
            <span class="sr-only">Sesión de</span><span>{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Salir</button>
            </form>
        </div>
    </nav>
    <main>
        @if (session('estado'))
            <div class="aviso aviso-ok" role="status">{{ session('estado') }}</div>
        @endif
        {{ $slot }}
    </main>
</x-layouts.base>
