<x-invitado titulo="Iniciar sesión">
    @if (session('status'))
        <div class="aviso aviso-ok" role="status">{{ session('status') }}</div>
    @endif
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="campo">
            <label for="email">Correo</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email') <div class="error" role="alert">{{ $message }}</div> @enderror
        </div>
        <div class="campo">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            @error('password') <div class="error" role="alert">{{ $message }}</div> @enderror
        </div>
        <div class="campo fila">
            <input id="remember" type="checkbox" name="remember"> <label for="remember" style="display:inline;font-weight:400">Recordarme en este equipo</label>
        </div>
        <button class="boton" type="submit">Entrar</button>
    </form>
</x-invitado>
