<x-invitado titulo="Confirme su contraseña">
    <p>Por seguridad, confirme su contraseña antes de continuar.</p>
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div class="campo">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
            @error('password') <div class="error" role="alert">{{ $message }}</div> @enderror
        </div>
        <button class="boton" type="submit">Confirmar</button>
    </form>
</x-invitado>
