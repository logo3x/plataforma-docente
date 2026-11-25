@props(['titulo' => null])
<x-layouts.base :titulo="$titulo">
    <main class="acceso">
        <div class="tarjeta">
            <h1>{{ $titulo }}</h1>
            <p class="suave">Plataforma docente — Instituto Universitario de la Paz</p>
            {{ $slot }}
        </div>
    </main>
</x-layouts.base>
