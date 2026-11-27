<?php

namespace Tests\Feature;

use App\Http\Middleware\PoliticaSeguridad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;

/** Las páginas de la plataforma no se conectan fuera de ella: segunda barrera para el código de los estudiantes. */
class PoliticaSeguridadTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    #[Test]
    public function las_paginas_solo_se_conectan_con_la_plataforma(): void
    {
        $this->crearEscenario(estudiantes: 0);
        $this->get(route('login'))->assertHeader('Content-Security-Policy', PoliticaSeguridad::POLITICA);
        $this->actingAs($this->docente)->get(route('inicio'))->assertOk()->assertHeader('Content-Security-Policy', PoliticaSeguridad::POLITICA);
        $this->assertStringContainsString("connect-src 'self'", PoliticaSeguridad::POLITICA);

        // El examen en vivo lleva su propia política, más estricta; no se le suma esta
        $this->assertNotSame(PoliticaSeguridad::POLITICA, $this->get('/v')->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function los_workers_que_ejecutan_codigo_ajeno_borran_la_red_de_toda_la_cadena_de_prototipos(): void
    {
        foreach (['public/js/ejecutor.js', 'resources/examen/examen.js'] as $f) {
            $js = file_get_contents(base_path($f));
            $this->assertStringContainsString("'fetch', 'XMLHttpRequest'", str_replace("'fetch','XMLHttpRequest'", "'fetch', 'XMLHttpRequest'", $js), $f);
            $this->assertMatchesRegularExpression('/Object\.getPrototypeOf\(o\)/', $js, $f);
            $this->assertStringContainsString("'Worker'", $js, $f);
        }
    }
}
