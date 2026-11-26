<?php

namespace App\Classroom;

use App\Models\CuentaGoogle;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth 2.0 con Google para Classroom (decisión pendiente 4). La aplicación pide solo lo que usa: ver los
 * cursos y sus listas (con el correo, para cruzar por correo institucional), leer y calificar las tareas de los
 * estudiantes, y leer los archivos que adjuntan. Los tokens quedan cifrados; el docente puede revocar el acceso.
 */
final class ConexionGoogle
{
    public const ALCANCES = [
        'openid', 'email',
        'https://www.googleapis.com/auth/classroom.courses.readonly',
        'https://www.googleapis.com/auth/classroom.rosters.readonly',
        'https://www.googleapis.com/auth/classroom.profile.emails',
        'https://www.googleapis.com/auth/classroom.coursework.students',
        'https://www.googleapis.com/auth/drive.readonly',
    ];

    public static function configurada(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function urlAutorizacion(string $estado): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => $this->redireccion(), 'response_type' => 'code',
            'scope' => implode(' ', self::ALCANCES), 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $estado,
        ]);
    }

    public function conectar(User $docente, string $codigo): CuentaGoogle
    {
        $r = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $codigo, 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redireccion(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('Google no aceptó la autorización: '.($r->json('error_description') ?? $r->json('error') ?? $r->status()).'.');
        }
        $email = Http::withToken($r->json('access_token'))->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        return CuentaGoogle::query()->updateOrCreate(['user_id' => $docente->id], [
            'email' => $email, 'access_token' => $r->json('access_token'),
            'refresh_token' => $r->json('refresh_token') ?? CuentaGoogle::query()->where('user_id', $docente->id)->first()?->refresh_token,
            'expira_en' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60),
        ]);
    }

    /** Token vigente; si venció, se renueva con el de actualización. */
    public function token(User $docente): string
    {
        $c = CuentaGoogle::query()->where('user_id', $docente->id)->first();
        if ($c === null) {
            throw new RuntimeException('Conecte primero su cuenta de Google en la página de Classroom.');
        }
        if ($c->expira_en && $c->expira_en->isFuture()) {
            return $c->access_token;
        }
        if (! $c->refresh_token) {
            throw new RuntimeException('La conexión con Google venció: vuelva a conectar su cuenta.');
        }
        $r = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $c->refresh_token, 'grant_type' => 'refresh_token',
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('Google ya no acepta la conexión (¿se revocó el acceso?): vuelva a conectar su cuenta.');
        }
        $c->update(['access_token' => $r->json('access_token'), 'expira_en' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60)]);

        return $c->access_token;
    }

    /** Revoca el acceso en Google y borra los tokens. */
    public function desconectar(User $docente): void
    {
        $c = CuentaGoogle::query()->where('user_id', $docente->id)->first();
        if ($c) {
            Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $c->refresh_token ?: $c->access_token]);
            $c->delete();
        }
    }

    private function redireccion(): string
    {
        return config('services.google.redirect') ?: route('classroom.callback');
    }
}
