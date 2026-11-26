<x-layouts.base :titulo="'Código — '.$sesion->actividad->nombre">
    <main class="proyectar-vivo">
        <p>Abra en el navegador</p>
        <p class="url">{{ $url }}</p>
        <p>o entre a <strong>{{ route('vivo.entrar') }}</strong> y escriba el código</p>
        <p class="codigo" aria-label="Código {{ implode(' ', str_split($sesion->codigo)) }}">{{ $sesion->codigo }}</p>
        <p class="suave">{{ $sesion->actividad->nombre }}</p>
    </main>
</x-layouts.base>
