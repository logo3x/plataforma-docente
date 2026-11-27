<?php

namespace App\Material;

use App\Http\Controllers\ApunteController;
use App\Http\Controllers\DebateController;
use App\Http\Controllers\EjemploController;
use App\Http\Controllers\PresentacionController;
use App\Http\Controllers\ProyectoController;
use App\Http\Controllers\VocabularioController;
use App\Models\Material;
use App\Models\Publicacion;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Publicación institucional del material (7.8, decisión pendiente 2). Se publica la misma «versión para
 * estudiantes» que ve el docente —sin menús, botones ni avisos internos—, con su ficha de metadatos, en:
 *  - HTML autocontenido: un solo archivo con los estilos dentro;
 *  - SCORM 1.2: paquete para Moodle y plataformas similares, con metadatos LOM en el manifiesto;
 *  - Word (.docx): para seguir editándolo fuera de la plataforma.
 * Ninguno lleva respuestas ni soluciones: sale de la versión para estudiantes.
 */
final class Publicador
{
    public const FORMATOS = ['html' => 'HTML autocontenido', 'scorm' => 'Paquete SCORM 1.2', 'docx' => 'Documento de Word'];

    /** tipo del material => controlador que dibuja su versión para estudiantes */
    private const CONTROLADORES = ['apuntes' => ApunteController::class, 'ejemplos' => EjemploController::class, 'debate' => DebateController::class,
        'vocabulario' => VocabularioController::class, 'proyectos' => ProyectoController::class, 'presentacion' => PresentacionController::class];

    /** La ficha propuesta: la de la última publicación del material, o lo que se sabe de él y del docente. */
    public function fichaInicial(Material $m): array
    {
        $previa = Publicacion::query()->where('material_id', $m->id)->latest('id')->first()?->ficha;
        if ($previa) {
            return $previa;
        }
        $c = $m->contenido;

        return [
            'titulo' => $m->titulo, 'descripcion' => Str::limit((string) ($c['proposito'] ?? $c['descripcion'] ?? $c['problema'] ?? ''), 500, ''),
            'autor' => $m->materia->docente->name, 'institucion' => config('publicacion.institucion'), 'programa' => '',
            'materia' => $m->materia->nombre, 'palabras_clave' => collect($c['temas'] ?? [$c['tema'] ?? null])->filter()->join(', '),
            'nivel' => 'Pregrado', 'resultados' => '', 'tiempo' => '', 'idioma' => 'es', 'licencia' => config('publicacion.licencia'), 'version' => '1.0',
        ];
    }

    /** @return array<string, string> errores por campo */
    public function validarFicha(array $f): array
    {
        $e = [];
        foreach (config('publicacion.campos') as $k => $c) {
            if (($c['obligatorio'] ?? false) && trim((string) ($f[$k] ?? '')) === '') {
                $e[$k] = "Falta «{$c['etiqueta']}».";
            }
        }

        return $e;
    }

    /** @return array{nombre: string, contenido: string, mime: string} */
    public function publicar(Material $m, string $formato, array $ficha): array
    {
        if ($e = $this->validarFicha($ficha)) {
            throw new InvalidArgumentException(implode(' ', $e));
        }
        $cuerpo = $this->contenido($m);
        $base = Str::slug(Str::limit($ficha['titulo'], 60, ''), '-') ?: 'material';

        return match ($formato) {
            'html' => ['nombre' => "{$base}.html", 'contenido' => $this->html($cuerpo, $ficha, false), 'mime' => 'text/html; charset=UTF-8'],
            'scorm' => ['nombre' => "{$base}-scorm.zip", 'contenido' => $this->scorm($cuerpo, $ficha, "material-{$m->id}"), 'mime' => 'application/zip'],
            'docx' => ['nombre' => "{$base}.docx", 'contenido' => $this->docx($cuerpo, $ficha), 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            default => throw new InvalidArgumentException('Formato no disponible.'),
        };
    }

    /** El contenido de la versión para estudiantes, limpio: solo el artículo, sin lo que es de la plataforma. */
    public function contenido(Material $m): string
    {
        $controlador = self::CONTROLADORES[$m->tipo] ?? throw new InvalidArgumentException('Este tipo de material no se publica.');
        $html = app($controlador)->show(Request::create('/', 'GET', ['version' => 'estudiante']), $m)->render();

        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($doc);
        $main = $xp->query('//main')->item(0) ?? throw new RuntimeException('No se encontró el contenido del material.');
        // Fuera lo que es de la plataforma: navegación, botones, formularios, avisos y lo marcado como no imprimible
        foreach ($xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " no-imprimir ") or contains(concat(" ", normalize-space(@class), " "), " migas ")
            or contains(concat(" ", normalize-space(@class), " "), " aviso ") or self::form or self::button or self::script or self::nav]', $main) as $n) {
            $n->parentNode?->removeChild($n);
        }
        foreach ($xp->query('.//a[@href]', $main) as $a) {
            /** @var DOMElement $a */
            if (! preg_match('#^https?://#', $a->getAttribute('href')) || str_starts_with($a->getAttribute('href'), url('/'))) {
                $a->parentNode->replaceChild($doc->createTextNode($a->textContent), $a);    // enlaces internos de la plataforma: solo texto
            }
        }
        foreach ($xp->query('.//@*[starts-with(name(), "x-") or starts-with(name(), "wire:") or starts-with(name(), ":") or starts-with(name(), "@")]', $main) as $at) {
            $at->ownerElement->removeAttribute($at->nodeName);
        }
        $salida = '';
        foreach ($main->childNodes as $hijo) {
            $salida .= $doc->saveHTML($hijo);
        }

        return trim($salida);
    }

    private function html(string $cuerpo, array $ficha, bool $scorm): string
    {
        return view('publicacion.documento', [
            'cuerpo' => $cuerpo, 'ficha' => $ficha, 'campos' => config('publicacion.campos'), 'scorm' => $scorm,
            'css' => file_get_contents(public_path('css/app.css')),
        ])->render();
    }

    private function scorm(string $cuerpo, array $ficha, string $id): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'scorm');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('imsmanifest.xml', $this->manifiesto($ficha, $id));
        $zip->addFromString('index.html', $this->html($cuerpo, $ficha, true));
        $zip->close();
        $datos = file_get_contents($ruta);
        @unlink($ruta);

        return $datos;
    }

    /** Manifiesto SCORM 1.2 (ADL), con los metadatos de la ficha en LOM dentro del propio manifiesto. */
    public function manifiesto(array $f, string $id): string
    {
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $idioma = $x($f['idioma'] ?? 'es');
        $cadena = fn ($v) => "<imsmd:langstring xml:lang=\"{$idioma}\">{$x($v)}</imsmd:langstring>";
        $palabras = collect(explode(',', (string) ($f['palabras_clave'] ?? '')))->map(fn ($p) => trim($p))->filter()
            ->map(fn ($p) => "<imsmd:keyword>{$cadena($p)}</imsmd:keyword>")->join('');
        $minutos = (int) ($f['tiempo'] ?? 0);
        $tiempo = $minutos > 0 ? sprintf('<imsmd:typicallearningtime><imsmd:datetime>%02d:%02d:00</imsmd:datetime></imsmd:typicallearningtime>', intdiv($minutos, 60), $minutos % 60) : '';
        $version = filled($f['version'] ?? null) ? "<imsmd:version>{$cadena($f['version'])}</imsmd:version>" : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="{$x($id)}" version="1.2"
    xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
    xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"
    xmlns:imsmd="http://www.imsglobal.org/xsd/imsmd_rootv1p2p1"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.imsproject.org/xsd/imscp_rootv1p1p2 imscp_rootv1p1p2.xsd http://www.imsglobal.org/xsd/imsmd_rootv1p2p1 imsmd_rootv1p2p1.xsd http://www.adlnet.org/xsd/adlcp_rootv1p2 adlcp_rootv1p2.xsd">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
    <imsmd:lom>
      <imsmd:general>
        <imsmd:title>{$cadena($f['titulo'])}</imsmd:title>
        <imsmd:language>{$idioma}</imsmd:language>
        <imsmd:description>{$cadena($f['descripcion'])}</imsmd:description>
        {$palabras}
      </imsmd:general>
      <imsmd:lifecycle>
        {$version}
        <imsmd:contribute><imsmd:role><imsmd:source>{$cadena('LOMv1.0')}</imsmd:source><imsmd:value>{$cadena('Author')}</imsmd:value></imsmd:role>
          <imsmd:centity><imsmd:vcard>BEGIN:VCARD FN:{$x($f['autor'])} ORG:{$x($f['institucion'])} END:VCARD</imsmd:vcard></imsmd:centity></imsmd:contribute>
      </imsmd:lifecycle>
      <imsmd:educational>
        {$tiempo}
        <imsmd:description>{$cadena(trim(($f['nivel'] ?? '').' · '.($f['materia'] ?? '').(filled($f['resultados'] ?? null) ? ' · '.$f['resultados'] : ''), ' ·'))}</imsmd:description>
      </imsmd:educational>
      <imsmd:rights>
        <imsmd:copyrightandotherrestrictions><imsmd:source>{$cadena('LOMv1.0')}</imsmd:source><imsmd:value>{$cadena('yes')}</imsmd:value></imsmd:copyrightandotherrestrictions>
        <imsmd:description>{$cadena($f['licencia'])}</imsmd:description>
      </imsmd:rights>
    </imsmd:lom>
  </metadata>
  <organizations default="org">
    <organization identifier="org">
      <title>{$x($f['titulo'])}</title>
      <item identifier="item1" identifierref="recurso1" isvisible="true"><title>{$x($f['titulo'])}</title></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="recurso1" type="webcontent" adlcp:scormtype="sco" href="index.html"><file href="index.html"/></resource>
  </resources>
</manifest>
XML;
    }

    /** Word mínimo y limpio: títulos, párrafos, listas y código, con la ficha al final y en las propiedades del documento. */
    private function docx(string $cuerpo, array $ficha): string
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8"><div id="raiz">'.$cuerpo.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $parrafos = [];
        $this->aWord($doc->getElementById('raiz'), $parrafos);
        $parrafos[] = $this->parrafo('Ficha del recurso', 'Heading2');
        foreach (config('publicacion.campos') as $k => $c) {
            if (filled($ficha[$k] ?? null)) {
                $parrafos[] = $this->parrafo("{$c['etiqueta']}: {$ficha[$k]}");
            }
        }
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cuerpoXml = implode('', $parrafos);

        $ruta = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', $this->estilosWord());
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$cuerpoXml.'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1418" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            ."<dc:title>{$x($ficha['titulo'])}</dc:title><dc:creator>{$x($ficha['autor'])}</dc:creator><dc:description>{$x($ficha['descripcion'])}</dc:description>"
            ."<cp:keywords>{$x($ficha['palabras_clave'] ?? '')}</cp:keywords><dc:language>{$x($ficha['idioma'] ?? 'es')}</dc:language>"
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.now()->toIso8601ZuluString().'</dcterms:created></cp:coreProperties>');
        $zip->close();
        $datos = file_get_contents($ruta);
        @unlink($ruta);

        return $datos;
    }

    private function aWord(DOMNode $nodo, array &$p): void
    {
        foreach ($nodo->childNodes as $n) {
            if (! $n instanceof DOMElement) {
                if (trim($n->textContent) !== '' && $nodo->nodeName === 'div') {
                    $p[] = $this->parrafo(trim($n->textContent));
                }

                continue;
            }
            $texto = trim(preg_replace('/\s+/u', ' ', $n->textContent));
            match ($n->nodeName) {
                'h1' => $p[] = $this->parrafo($texto, 'Title'),
                'h2' => $p[] = $this->parrafo($texto, 'Heading1'),
                'h3', 'h4' => $p[] = $this->parrafo($texto, 'Heading2'),
                'p', 'dt', 'dd', 'blockquote' => $texto !== '' ? $p[] = $this->parrafo($texto) : null,
                'pre' => $this->codigo($n->textContent, $p),
                'ul', 'ol' => $this->lista($n, $p),
                'table' => $this->tabla($n, $p),
                default => $this->aWord($n, $p),
            };
        }
    }

    private function codigo(string $texto, array &$p): void
    {
        foreach (explode("\n", rtrim($texto)) as $linea) {
            $p[] = $this->parrafo($linea, 'Codigo');
        }
    }

    private function lista(DOMElement $l, array &$p): void
    {
        $i = 0;
        foreach ($l->childNodes as $li) {
            if ($li instanceof DOMElement && $li->nodeName === 'li') {
                $i++;
                $p[] = $this->parrafo(($l->nodeName === 'ol' ? "{$i}. " : '• ').trim(preg_replace('/\s+/u', ' ', $li->textContent)), 'Lista');
            }
        }
    }

    private function tabla(DOMElement $t, array &$p): void
    {
        foreach ((new DOMXPath($t->ownerDocument))->query('.//tr', $t) as $tr) {
            $p[] = $this->parrafo(collect(iterator_to_array($tr->childNodes))->filter(fn ($c) => $c instanceof DOMElement)
                ->map(fn ($c) => trim(preg_replace('/\s+/u', ' ', $c->textContent)))->join(' | '));
        }
    }

    private function parrafo(string $texto, ?string $estilo = null): string
    {
        $t = htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<w:p>'.($estilo ? "<w:pPr><w:pStyle w:val=\"{$estilo}\"/></w:pPr>" : '')."<w:r><w:t xml:space=\"preserve\">{$t}</w:t></w:r></w:p>";
    }

    private function estilosWord(): string
    {
        $estilo = fn ($id, $nombre, $rpr, $ppr = '') => "<w:style w:type=\"paragraph\" w:styleId=\"{$id}\"><w:name w:val=\"{$nombre}\"/><w:basedOn w:val=\"Normal\"/><w:qFormat/>{$ppr}<w:rPr>{$rpr}</w:rPr></w:style>";

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:lang w:val="es-CO"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            .$estilo('Title', 'Title', '<w:b/><w:color w:val="0B2545"/><w:sz w:val="40"/>')
            .$estilo('Heading1', 'heading 1', '<w:b/><w:color w:val="0B2545"/><w:sz w:val="30"/>', '<w:pPr><w:keepNext/><w:spacing w:before="240"/><w:outlineLvl w:val="0"/></w:pPr>')
            .$estilo('Heading2', 'heading 2', '<w:b/><w:color w:val="0B2545"/><w:sz w:val="26"/>', '<w:pPr><w:keepNext/><w:spacing w:before="200"/><w:outlineLvl w:val="1"/></w:pPr>')
            .$estilo('Lista', 'List Paragraph', '', '<w:pPr><w:ind w:left="360"/><w:spacing w:after="60"/></w:pPr>')
            .$estilo('Codigo', 'Código', '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="19"/>', '<w:pPr><w:spacing w:after="0"/><w:shd w:val="clear" w:color="auto" w:fill="F3F4F6"/></w:pPr>')
            .'</w:styles>';
    }
}
