@props(['titulo' => null])
<x-layouts.base :titulo="$titulo">
    <main class="acceso">
        <div class="tarjeta">
            <h1>{{ $titulo }}</h1>
            <p class="suave">{{ config('app.name') }}@if (filled(config('plataforma.institucion'))) — {{ config('plataforma.institucion') }}@endif</p>
            {{ $slot }}
        </div>
    </main>
</x-layouts.base>
