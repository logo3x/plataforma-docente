<?php

namespace Tests\Feature;

use App\Material\Publicador;
use App\Material\TextoInformativo;
use App\Models\Material;
use App\Models\Publicacion;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaEscenario;
use Tests\TestCase;
use ZipArchive;

/** Publicación institucional del material: HTML, SCORM 1.2 y Word, con su ficha (7.8, decisión pendiente 2). */
class PublicacionTest extends TestCase
{
    use CreaEscenario, RefreshDatabase;

    private Material $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenario(estudiantes: 0);
        $this->actingAs($this->docente);
        $this->m = Material::query()->create(['materia_id' => $this->materia->id, 'tipo' => 'apuntes', 'titulo' => 'Normalizar sin miedo',
            'contenido' => app(TextoInformativo::class)->normalizar(['formato' => 'plataforma-apunte', 'version' => 1, 'titulo' => 'Normalizar sin miedo',
                'proposito' => 'Entender por qué se divide una tabla.',
                'secciones' => [['titulo' => 'Primera forma normal', 'texto' => "Cada celda guarda un solo valor:\n- nada de listas en una celda", 'idea_clave' => 'Un valor por celda.',
                    'codigo' => "CREATE TABLE repuesto (\n  id INT PRIMARY KEY\n);"]],
                'resumen' => ['La normalización evita datos repetidos.'], 'preguntas' => [['pregunta' => '¿Qué problema resuelve?', 'respuesta' => 'RESPUESTA-SECRETA']],
                'fuentes' => []])]);
    }

    private function ficha(array $cambios = []): array
    {
        return array_replace(app(Publicador::class)->fichaInicial($this->m), ['palabras_clave' => 'normalización, bases de datos', 'tiempo' => '75'], $cambios);
    }

    private function publicar(string $formato, array $ficha = [])
    {
        return $this->post(route('publicacion.store', $this->m), ['formato' => $formato, 'ficha' => $this->ficha($ficha)]);
    }

    #[Test]
    public function el_html_es_autocontenido_sin_respuestas_ni_nada_de_la_plataforma(): void
    {
        $this->get(route('apuntes.show', $this->m))->assertSee(route('publicacion.edit', $this->m), false);
        $this->get(route('publicacion.edit', $this->m))->assertOk()->assertSee('Ficha del recurso')->assertSee('value="Normalizar sin miedo"', false)
            ->assertSee(config('publicacion.institucion'))->assertSee('CC BY-NC-SA 4.0');

        $r = $this->publicar('html')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="normalizar-sin-miedo.html"');
        $html = $r->getContent();
        $this->assertStringContainsString('<h2>Primera forma normal</h2>', $html);
        $this->assertStringContainsString('CREATE TABLE repuesto', $html);
        $this->assertStringContainsString('<meta name="DC.title" content="Normalizar sin miedo">', $html);
        $this->assertStringContainsString('<meta name="DC.subject" content="normalización, bases de datos">', $html);
        $this->assertStringNotContainsString('RESPUESTA-SECRETA', $html, 'Sale de la versión para estudiantes');
        foreach (['Mis materias', 'Guardar en el repositorio', 'Versión para estudiantes', '<nav', '<form', 'livewire', route('inicio')] as $nada) {
            $this->assertStringNotContainsString($nada, $html);
        }
        $this->assertDoesNotMatchRegularExpression('#<script[^>]+src=|<link[^>]+rel="stylesheet"|<img[^>]+src="http#', $html, 'Nada externo: funciona sin internet');
        $this->assertStringNotContainsString('API.LMSInitialize', $html, 'El HTML suelto no habla SCORM');
        $this->assertSame(['html', 1], [Publicacion::query()->sole()->formato, Publicacion::query()->sole()->version]);
    }

    #[Test]
    public function el_paquete_scorm_trae_manifiesto_valido_con_lom_y_la_pagina(): void
    {
        $zipBytes = $this->publicar('scorm')->assertOk()->assertHeader('Content-Type', 'application/zip')->getContent();
        $ruta = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($ruta, $zipBytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $this->assertEqualsCanonicalizing(['imsmanifest.xml', 'index.html'], [$zip->getNameIndex(0), $zip->getNameIndex(1)]);

        $xml = $zip->getFromName('imsmanifest.xml');
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'XML bien formado');
        $sx = simplexml_load_string($xml);
        $sx->registerXPathNamespace('c', 'http://www.imsproject.org/xsd/imscp_rootv1p1p2');
        $sx->registerXPathNamespace('m', 'http://www.imsglobal.org/xsd/imsmd_rootv1p2p1');
        $sx->registerXPathNamespace('a', 'http://www.adlnet.org/xsd/adlcp_rootv1p2');
        $this->assertSame('1.2', (string) $sx->xpath('//c:metadata/c:schemaversion')[0]);
        $this->assertSame('Normalizar sin miedo', (string) $sx->xpath('//m:general/m:title/m:langstring')[0]);
        $this->assertSame(['normalización', 'bases de datos'], array_map('strval', $sx->xpath('//m:general/m:keyword/m:langstring')));
        $this->assertSame('01:15:00', (string) $sx->xpath('//m:typicallearningtime/m:datetime')[0]);
        $this->assertSame('sco', (string) $sx->xpath('//c:resource/@a:scormtype')[0]);
        $this->assertSame('index.html', (string) $sx->xpath('//c:resource/@href')[0]);

        $pagina = $zip->getFromName('index.html');
        $this->assertStringContainsString("api.LMSSetValue('cmi.core.lesson_status', 'completed')", $pagina);
        $this->assertStringContainsString('Marcar como leído', $pagina);
        $zip->close();
        @unlink($ruta);
    }

    #[Test]
    public function el_word_es_un_docx_con_titulos_codigo_y_ficha(): void
    {
        $bytes = $this->publicar('docx', ['titulo' => 'Normalización & <formas>'])->assertOk()->getContent();
        $ruta = tempnam(sys_get_temp_dir(), 'd');
        file_put_contents($ruta, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $doc = $zip->getFromName('word/document.xml');
        $this->assertTrue((new DOMDocument)->loadXML($doc));
        $this->assertTrue((new DOMDocument)->loadXML($zip->getFromName('docProps/core.xml')));
        $this->assertStringContainsString('<w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t xml:space="preserve">Primera forma normal</w:t>', $doc);
        $this->assertStringContainsString('<w:pStyle w:val="Codigo"/></w:pPr><w:r><w:t xml:space="preserve">CREATE TABLE repuesto (</w:t>', $doc);
        $this->assertStringContainsString('Licencia: CC BY-NC-SA 4.0', $doc);
        $this->assertStringContainsString('<dc:title>Normalización &amp; &lt;formas&gt;</dc:title>', $zip->getFromName('docProps/core.xml'));
        $this->assertStringNotContainsString('RESPUESTA-SECRETA', $doc);
        $zip->close();
        @unlink($ruta);
    }

    #[Test]
    public function exige_la_ficha_recuerda_la_ultima_y_solo_el_docente_publica(): void
    {
        $this->publicar('html', ['descripcion' => ''])->assertSessionHasErrors('descripcion');
        $this->assertSame(0, Publicacion::query()->count());

        $this->publicar('scorm', ['programa' => 'Ingeniería de Sistemas', 'version' => '2.0']);
        $this->get(route('publicacion.edit', $this->m))->assertSee('value="Ingeniería de Sistemas"', false)->assertSee('Versión 1 · Paquete SCORM 1.2');

        $this->actingAs(User::factory()->create());
        $this->get(route('publicacion.edit', $this->m))->assertForbidden();
        $this->post(route('publicacion.store', $this->m), ['formato' => 'html', 'ficha' => $this->ficha()])->assertForbidden();
    }
}
