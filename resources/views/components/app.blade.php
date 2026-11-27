@props(['titulo' => null])
<x-layouts.base :titulo="$titulo">
    <nav class="barra" aria-label="Principal">
        <a href="{{ route('inicio') }}" class="marca">{{ config('app.name') }}</a>
        <a href="{{ route('inicio') }}">Mis materias</a>
        <a href="{{ route('bitacora.index') }}">Bitácora</a>
        {{-- 3.3.2: lo poco frecuente va en un menú secundario --}}
        <details class="menu menu-barra">
            <summary>Ajustes</summary>
            <div class="contenido">
                <a href="{{ route('ia.motores') }}">Motores de IA</a>
                <a href="{{ route('ia.plantillas') }}">Plantillas de IA</a>
                <a href="{{ route('ia.biblioteca') }}">Biblioteca de lo que funcionó</a>
                <a href="{{ route('generadores.index') }}">Generadores de documentos</a>
                <a href="{{ route('catalogo-errores.index') }}">Errores frecuentes</a>
                <a href="{{ route('alertas.politica') }}">Política de alertas</a>
                <a href="{{ route('classroom.index') }}">Google Classroom</a>
            </div>
        </details>
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
        @if (session('aviso_vistas'))
            <div class="aviso aviso-info" role="note">{{ session('aviso_vistas') }}</div>
        @endif
        {{ $slot }}
    </main>
</x-layouts.base>
