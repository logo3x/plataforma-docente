<x-invitado titulo="Segundo factor">
    <div x-data="{ recuperacion: false }">
        <form method="POST" action="{{ route('two-factor.login') }}">
            @csrf
            <div class="campo" x-show="! recuperacion">
                <label for="code">Código de su aplicación de autenticación</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            </div>
            <div class="campo" x-show="recuperacion" x-cloak>
                <label for="recovery_code">Código de recuperación</label>
                <input id="recovery_code" type="text" name="recovery_code" autocomplete="one-time-code">
            </div>
            @error('code') <div class="error" role="alert">{{ $message }}</div> @enderror
            @error('recovery_code') <div class="error" role="alert">{{ $message }}</div> @enderror
            <div class="fila">
                <button class="boton" type="submit">Verificar</button>
                <button class="enlace" type="button" x-on:click="recuperacion = ! recuperacion"
                        x-text="recuperacion ? 'Usar el código de la aplicación' : 'Usar un código de recuperación'"></button>
            </div>
        </form>
    </div>
</x-invitado>
