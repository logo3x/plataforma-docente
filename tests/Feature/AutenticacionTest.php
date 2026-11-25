<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function un_visitante_es_enviado_al_login(): void
    {
        $this->get('/')->assertRedirect('/inicio');
        $this->get('/inicio')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
    }

    #[Test]
    public function el_docente_inicia_sesion_y_ve_sus_materias(): void
    {
        $u = User::factory()->create(['password' => 'clave-segura-2026']);

        $this->post('/login', ['email' => $u->email, 'password' => 'clave-segura-2026'])->assertRedirect('/inicio');
        $this->get('/inicio')->assertOk()->assertSee('Aún no tiene materias');
    }

    #[Test]
    public function una_clave_incorrecta_no_entra_y_hay_limite_de_intentos(): void
    {
        $u = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $u->email, 'password' => 'mala'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $u->email, 'password' => 'mala'])->assertStatus(429);
        $this->assertGuest();
    }

    #[Test]
    public function no_hay_registro_publico(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    #[Test]
    public function con_segundo_factor_activo_pide_el_codigo(): void
    {
        $u = User::factory()->create(['password' => 'clave-segura-2026']);
        $u->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['codigo-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', ['email' => $u->email, 'password' => 'clave-segura-2026'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Segundo factor');
    }

    #[Test]
    public function el_comando_crea_un_docente_con_clave_robusta(): void
    {
        $this->artisan('docente:crear', ['email' => 'docente@unipaz.edu.co', '--nombre' => 'Docente Prueba'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')
            ->assertFailed();

        $this->artisan('docente:crear', ['email' => 'docente@unipaz.edu.co', '--nombre' => 'Docente Prueba'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'unipaz-segura-2026')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'docente@unipaz.edu.co']);
    }

    #[Test]
    public function la_pagina_de_seguridad_ofrece_activar_el_segundo_factor(): void
    {
        $this->actingAs(User::factory()->create())->get('/seguridad')->assertOk()->assertSee('Activar segundo factor');
    }
}
