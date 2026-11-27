<?php

namespace Tests\Unit;

use App\Evaluacion\GeneradorExamen;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** La política de seguridad del examen solo deja conectar con el origen de la sonda (4.5.3). */
class OrigenSondaTest extends TestCase
{
    #[Test]
    public function toma_solo_el_origen_https_y_rechaza_lo_demas(): void
    {
        $this->assertSame('https://www.google.com', GeneradorExamen::origen('https://www.google.com/generate_204'));
        $this->assertSame('https://sonda.ejemplo.edu.co:8443', GeneradorExamen::origen('https://Sonda.Ejemplo.edu.co:8443/ping?x=1'));
        $this->assertSame('', GeneradorExamen::origen('http://www.google.com/'));
        $this->assertSame('', GeneradorExamen::origen('https://x.com; script-src *'));      // nada se cuela en la política
        $this->assertSame('', GeneradorExamen::origen(null));
    }
}
