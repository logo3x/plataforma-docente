<x-app titulo="Seguridad">
    <h1>Seguridad de la cuenta</h1>
    @if (session('status') === 'two-factor-authentication-enabled')
        <div class="aviso aviso-info" role="status">Escanee el código QR y confirme con un código para terminar de activar el segundo factor.</div>
    @elseif (session('status') === 'two-factor-authentication-confirmed')
        <div class="aviso aviso-ok" role="status">Segundo factor activado.</div>
    @elseif (session('status') === 'password-updated')
        <div class="aviso aviso-ok" role="status">Contraseña actualizada.</div>
    @endif

    <section class="tarjeta">
        <h2 style="margin-top:0">Segundo factor de autenticación</h2>
        <p class="suave">La plataforma guarda notas e identificación de estudiantes. Se recomienda activar el segundo factor.</p>

        @if (! $usuario->two_factor_secret)
            <form method="POST" action="{{ route('two-factor.enable') }}">@csrf
                <button class="boton" type="submit">Activar segundo factor</button>
            </form>
        @elseif (! $usuario->two_factor_confirmed_at)
            <div>{!! $usuario->twoFactorQrCodeSvg() !!}</div>
            <form method="POST" action="{{ route('two-factor.confirm') }}" style="max-width:320px">@csrf
                <div class="campo"><label for="code">Código de la aplicación</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required></div>
                @error('code', 'confirmTwoFactorAuthentication') <div class="error" role="alert">{{ $message }}</div> @enderror
                <button class="boton" type="submit">Confirmar</button>
            </form>
        @else
            <p><span class="estado estado-aprobado">✓ Activo</span></p>
            <details><summary>Ver códigos de recuperación</summary>
                <p class="suave">Guárdelos en un lugar seguro. Cada código sirve una sola vez.</p>
                <ul>@foreach ($usuario->recoveryCodes() as $c)<li><code>{{ $c }}</code></li>@endforeach</ul>
                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">@csrf
                    <button class="boton boton-sec boton-chico" type="submit">Generar códigos nuevos</button></form>
            </details>
            <form method="POST" action="{{ route('two-factor.disable') }}" style="margin-top:1rem">@csrf @method('DELETE')
                <button class="boton boton-peligro boton-chico" type="submit">Desactivar segundo factor</button>
            </form>
        @endif
    </section>

    <section class="tarjeta">
        <h2 style="margin-top:0">Cambiar contraseña</h2>
        <form method="POST" action="{{ route('user-password.update') }}" style="max-width:420px">@csrf @method('PUT')
            @foreach (['current_password' => 'Contraseña actual', 'password' => 'Nueva contraseña', 'password_confirmation' => 'Repita la nueva contraseña'] as $campo => $etiqueta)
                <div class="campo"><label for="{{ $campo }}">{{ $etiqueta }}</label>
                    <input id="{{ $campo }}" name="{{ $campo }}" type="password" required autocomplete="{{ $campo === 'current_password' ? 'current-password' : 'new-password' }}">
                    @error($campo, 'updatePassword') <div class="error" role="alert">{{ $message }}</div> @enderror</div>
            @endforeach
            <button class="boton" type="submit">Actualizar contraseña</button>
        </form>
    </section>
</x-app>
