<x-layouts.base titulo="Entrar al examen">
    <main class="proyectar-vivo" style="font-size:1.1rem">
        <h1>Entrar al examen</h1>
        <form method="GET" action="{{ route('vivo.entrar') }}" style="max-width:22rem;margin:0 auto">
            <label for="codigo">Código que proyectó el docente</label>
            <input id="codigo" name="codigo" type="text" autocomplete="off" autocapitalize="characters" maxlength="12" value="{{ $codigo }}" required
                   style="font-size:2rem;text-align:center;letter-spacing:.2em;font-family:ui-monospace,Consolas,monospace">
            @if ($error)<div class="error" role="alert">{{ $error }}</div>@endif
            <p><button class="boton" type="submit">Entrar</button></p>
        </form>
    </main>
</x-layouts.base>
