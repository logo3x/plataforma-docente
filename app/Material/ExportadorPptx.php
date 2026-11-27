<?php

namespace App\Material;

use ZipArchive;

/**
 * Presentación de clase como PowerPoint editable (7.2.2 y 7.2.3), escrita directamente en PresentationML,
 * sin servicio externo. Diapositivas 16:9 con un diseño por tipo y las notas del orador en su lugar
 * estándar, para que se vean en la vista del presentador de PowerPoint, LibreOffice o Google Presentaciones.
 */
final class ExportadorPptx
{
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    private const ANCHO = 12192000;

    private const ALTO = 6858000;

    private const MARINO = '0B2545';

    private const CIAN = '0E7490';

    private const TEXTO = '1F2937';

    private const SUAVE = '4B5563';

    private int $id = 1;

    public function generar(array $c, string $autor, string $materia): string
    {
        $diapositivas = [['tipo' => 'portada', 'titulo' => $c['titulo'], 'puntos' => array_values(array_filter([$c['subtitulo'] ?? '', "{$materia} · {$autor}"])), 'codigo' => '', 'notas' => $c['proposito'] ?? '']];
        array_push($diapositivas, ...($c['diapositivas'] ?? []));
        $n = count($diapositivas);

        $ruta = tempnam(sys_get_temp_dir(), 'pptx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);

        $tipos = '';
        $relsPres = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster" Target="notesMasters/notesMaster1.xml"/>';
        $ids = '';
        foreach ($diapositivas as $i => $s) {
            $k = $i + 1;
            $zip->addFromString("ppt/slides/slide{$k}.xml", $this->diapositiva($s, $k, $n, $materia));
            $zip->addFromString("ppt/slides/_rels/slide{$k}.xml.rels", $this->rels([
                ['slideLayout', '../slideLayouts/slideLayout1.xml'], ['notesSlide', "../notesSlides/notesSlide{$k}.xml"]]));
            $zip->addFromString("ppt/notesSlides/notesSlide{$k}.xml", $this->notas((string) ($s['notas'] ?? '')));
            $zip->addFromString("ppt/notesSlides/_rels/notesSlide{$k}.xml.rels", $this->rels([['notesMaster', '../notesMasters/notesMaster1.xml'], ['slide', "../slides/slide{$k}.xml"]]));
            $tipos .= "<Override PartName=\"/ppt/slides/slide{$k}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.presentationml.slide+xml\"/>"
                ."<Override PartName=\"/ppt/notesSlides/notesSlide{$k}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml\"/>";
            $relsPres .= '<Relationship Id="rId'.(10 + $k).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$k.'.xml"/>';
            $ids .= '<p:sldId id="'.(255 + $k).'" r:id="rId'.(10 + $k).'"/>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            .'<Override PartName="/ppt/notesMasters/notesMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/>'
            .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .'<Override PartName="/ppt/theme/theme2.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$tipos.'</Types>');
        $zip->addFromString('_rels/.rels', $this->rels([['officeDocument', 'ppt/presentation.xml'], ['core-properties', 'docProps/core.xml', true], ['extended-properties', 'docProps/app.xml']]));
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation '.self::NS.' saveSubsetFonts="1">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:notesMasterIdLst><p:notesMasterId r:id="rId3"/></p:notesMasterIdLst>'
            ."<p:sldIdLst>{$ids}</p:sldIdLst><p:sldSz cx=\"".self::ANCHO.'" cy="'.self::ALTO.'"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relsPres.'</Relationships>');
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster '.self::NS.'>'
            .'<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:effectLst/></p:bgPr></p:bg><p:spTree>'.$this->grupo().'</p:spTree></p:cSld>'
            .$this->mapaColores().'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            .'<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->rels([['slideLayout', '../slideLayouts/slideLayout1.xml'], ['theme', '../theme/theme1.xml']]));
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout '.self::NS.' type="blank" preserve="1">'
            .'<p:cSld name="En blanco"><p:spTree>'.$this->grupo().'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->rels([['slideMaster', '../slideMasters/slideMaster1.xml']]));
        $zip->addFromString('ppt/notesMasters/notesMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notesMaster '.self::NS.'>'
            .'<p:cSld><p:spTree>'.$this->grupo().$this->marcador(2, 'Notas', 'body', 1, 685800, 4400550, 5486400, 3600450).'</p:spTree></p:cSld>'.$this->mapaColores().'</p:notesMaster>');
        $zip->addFromString('ppt/notesMasters/_rels/notesMaster1.xml.rels', $this->rels([['theme', '../theme/theme2.xml']]));
        $zip->addFromString('ppt/theme/theme1.xml', $this->tema('Plataforma'));
        $zip->addFromString('ppt/theme/theme2.xml', $this->tema('Notas'));
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            ."<dc:title>{$x($c['titulo'])}</dc:title><dc:creator>{$x($autor)}</dc:creator><dc:subject>{$x($materia)}</dc:subject><dc:language>es-CO</dc:language>"
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.now()->toIso8601ZuluString().'</dcterms:created></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            .'<Application>'.$x(config('app.name'))."</Application><Slides>{$n}</Slides><Notes>{$n}</Notes></Properties>");
        $zip->close();
        $datos = file_get_contents($ruta);
        @unlink($ruta);

        return $datos;
    }

    private function diapositiva(array $s, int $k, int $n, string $materia): string
    {
        $this->id = 1;
        $W = self::ANCHO;
        $H = self::ALTO;
        $m = 685800;                                        // margen de 0.75"
        $formas = match ($s['tipo']) {
            'portada' => $this->rect(0, 0, $W, $H, self::MARINO)
                .$this->texto($m, 2057400, $W - 2 * $m, 1600200, [[$s['titulo'], 4400, 'FFFFFF', true]], 'b', null, 'l', 'ctrTitle')
                .$this->rect($m, 3771900, 2286000, 76200, '7DD3FC')
                .$this->texto($m, 3962400, $W - 2 * $m, 1371600, array_map(fn ($p) => [$p, 2000, 'E0F2FE', false], $s['puntos'])),
            'seccion' => $this->rect(0, 0, 228600, $H, self::CIAN)
                .$this->texto($m, 2286000, $W - 2 * $m, 1828800, [[$s['titulo'], 4000, self::MARINO, true]], 'ctr', null, 'l', 'title')
                .($s['puntos'] ? $this->texto($m, 4114800, $W - 2 * $m, 1371600, array_map(fn ($p) => [$p, 2000, self::SUAVE, false], $s['puntos'])) : ''),
            'pregunta' => $this->rect(0, 0, $W, 1143000, self::CIAN)
                .$this->texto($m, 228600, $W - 2 * $m, 685800, [['Pregunta al grupo', 2000, 'FFFFFF', true]], 'ctr')
                .$this->texto($m, 1600200, $W - 2 * $m, 2286000, [[$s['titulo'], 3600, self::MARINO, true]], 'ctr', null, 'l', 'title')
                .($s['puntos'] ? $this->texto($m, 4000500, $W - 2 * $m, 1828800, array_map(fn ($p) => [$p, 2000, self::SUAVE, false, '–'], $s['puntos'])) : ''),
            'codigo' => $this->titulo($s['titulo'])
                .$this->texto($m, 1600200, $W - 2 * $m, $H - 1600200 - 685800, array_map(fn ($l) => [$l === '' ? ' ' : $l, 1600, self::TEXTO, false, null, 'Consolas'],
                    explode("\n", $s['codigo'])), 't', 'F3F4F6')
                .($s['puntos'] ? $this->texto($m, $H - 685800, $W - 2 * $m, 457200, [[implode(' · ', $s['puntos']), 1400, self::SUAVE, false]]) : ''),
            'cierre' => $this->titulo($s['titulo'])
                .$this->texto($m, 1600200, $W - 2 * $m, $H - 1600200 - 685800, array_map(fn ($p) => [$p, 2400, self::TEXTO, false, '✓'], $s['puntos'])),
            default => $this->titulo($s['titulo'])
                .$this->texto($m, 1600200, $W - 2 * $m, $H - 1600200 - 685800, array_map(fn ($p) => [$p, count($s['puntos']) > 6 ? 2000 : 2400, self::TEXTO, false, '•'], $s['puntos'])),
        };
        $pie = $s['tipo'] === 'portada' ? '' : $this->texto($m, $H - 457200, $W - 2 * $m, 320040, [[$materia.' · '.$k.' / '.$n, 1100, '9CA3AF', false]], 'ctr', null, 'r');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld '.self::NS.'><p:cSld><p:spTree>'.$this->grupo().$formas.$pie
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    private function titulo(string $t): string
    {
        return $this->texto(685800, 457200, self::ANCHO - 1371600, 914400, [[$t, 3200, self::MARINO, true]], 'b', null, 'l', 'title')
            .$this->rect(685800, 1371600, 1371600, 57150, self::CIAN);
    }

    /** @param  array<int, array{0: string, 1: int, 2: string, 3: bool, 4?: ?string, 5?: string}>  $parrafos  texto, tamaño, color, negrita, viñeta, fuente */
    private function texto(int $x, int $y, int $w, int $h, array $parrafos, string $ancla = 't', ?string $fondo = null, string $alineacion = 'l', ?string $marcador = null): string
    {
        $id = ++$this->id;
        $ps = '';
        foreach ($parrafos as $p) {
            [$t, $tam, $color, $negrita] = $p;
            $vineta = $p[4] ?? null;
            $fuente = $p[5] ?? 'Calibri';
            $pPr = $vineta ? '<a:pPr marL="457200" indent="-342900" algn="'.$alineacion.'"><a:spcBef><a:spcPts val="600"/></a:spcBef><a:buFont typeface="Arial"/><a:buChar char="'.$this->x($vineta).'"/></a:pPr>'
                : '<a:pPr algn="'.$alineacion.'"><a:buNone/></a:pPr>';
            $ps .= '<a:p>'.$pPr.'<a:r><a:rPr lang="es-CO" sz="'.$tam.'"'.($negrita ? ' b="1"' : '').' dirty="0"><a:solidFill><a:srgbClr val="'.$color.'"/></a:solidFill>'
                .'<a:latin typeface="'.$fuente.'"/><a:cs typeface="'.$fuente.'"/></a:rPr><a:t>'.$this->x($t).'</a:t></a:r></a:p>';
        }
        $relleno = $fondo ? '<a:solidFill><a:srgbClr val="'.$fondo.'"/></a:solidFill>' : '<a:noFill/>';

        // El título va como marcador de título: así aparece en el esquema de PowerPoint y lo anuncian los lectores de pantalla
        $nv = $marcador ? '<p:cNvPr id="'.$id.'" name="Título '.$id.'"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="'.$marcador.'"/></p:nvPr>'
            : '<p:cNvPr id="'.$id.'" name="Texto '.$id.'"/><p:cNvSpPr txBox="1"/><p:nvPr/>';

        return '<p:sp><p:nvSpPr>'.$nv.'</p:nvSpPr>'
            ."<p:spPr><a:xfrm><a:off x=\"{$x}\" y=\"{$y}\"/><a:ext cx=\"{$w}\" cy=\"{$h}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom>{$relleno}</p:spPr>"
            .'<p:txBody><a:bodyPr wrap="square" lIns="182880" tIns="91440" rIns="182880" bIns="91440" anchor="'.$ancla.'"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            .($ps ?: '<a:p><a:endParaRPr lang="es-CO"/></a:p>').'</p:txBody></p:sp>';
    }

    private function rect(int $x, int $y, int $w, int $h, string $color): string
    {
        $id = ++$this->id;

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Forma '.$id.'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            ."<p:spPr><a:xfrm><a:off x=\"{$x}\" y=\"{$y}\"/><a:ext cx=\"{$w}\" cy=\"{$h}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom>"
            ."<a:solidFill><a:srgbClr val=\"{$color}\"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr></p:sp>";
    }

    private function notas(string $texto): string
    {
        $parrafos = collect(preg_split('/\R/', $texto))->map(fn ($l) => '<a:p><a:r><a:rPr lang="es-CO" dirty="0"/><a:t>'.$this->x($l).'</a:t></a:r></a:p>')->join('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notes '.self::NS.'><p:cSld><p:spTree>'.$this->grupo()
            .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="Notas"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/>'
            .'<p:txBody><a:bodyPr/><a:lstStyle/>'.($parrafos ?: '<a:p/>').'</p:txBody></p:sp></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
    }

    private function marcador(int $id, string $nombre, string $tipo, int $idx, int $x, int $y, int $w, int $h): string
    {
        return "<p:sp><p:nvSpPr><p:cNvPr id=\"{$id}\" name=\"{$nombre}\"/><p:cNvSpPr><a:spLocks noGrp=\"1\"/></p:cNvSpPr><p:nvPr><p:ph type=\"{$tipo}\" idx=\"{$idx}\"/></p:nvPr></p:nvSpPr>"
            ."<p:spPr><a:xfrm><a:off x=\"{$x}\" y=\"{$y}\"/><a:ext cx=\"{$w}\" cy=\"{$h}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></p:spPr>"
            .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="es-CO"/></a:p></p:txBody></p:sp>';
    }

    private function grupo(): string
    {
        return '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
    }

    private function mapaColores(): string
    {
        return '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>';
    }

    /** @param  array<int, array{0: string, 1: string, 2?: bool}>  $r  tipo, destino, ¿es del paquete (no de officeDocument)? */
    private function rels(array $r): string
    {
        $base = fn ($t, $paquete) => $paquete ? "http://schemas.openxmlformats.org/package/2006/relationships/metadata/{$t}" : "http://schemas.openxmlformats.org/officeDocument/2006/relationships/{$t}";

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .collect($r)->map(fn ($x, $i) => '<Relationship Id="rId'.($i + 1).'" Type="'.$base($x[0], $x[2] ?? false).'" Target="'.$x[1].'"/>')->join('').'</Relationships>';
    }

    private function tema(string $nombre): string
    {
        $c = fn ($n, $v) => "<a:{$n}><a:srgbClr val=\"{$v}\"/></a:{$n}>";
        $relleno = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $linea = '<a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>';
        $efecto = '<a:effectStyle><a:effectLst/></a:effectStyle>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="'.$nombre.'"><a:themeElements>'
            .'<a:clrScheme name="Plataforma"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            .$c('dk2', self::MARINO).$c('lt2', 'F3F4F6').$c('accent1', self::CIAN).$c('accent2', 'B45309').$c('accent3', '166534').$c('accent4', '991B1B')
            .$c('accent5', '7C3AED').$c('accent6', '0369A1').$c('hlink', self::CIAN).$c('folHlink', '6D28D9').'</a:clrScheme>'
            .'<a:fontScheme name="Plataforma"><a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            .'<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            .'<a:fmtScheme name="Plataforma"><a:fillStyleLst>'.str_repeat($relleno, 3).'</a:fillStyleLst><a:lnStyleLst>'.str_repeat($linea, 3).'</a:lnStyleLst>'
            .'<a:effectStyleLst>'.str_repeat($efecto, 3).'</a:effectStyleLst><a:bgFillStyleLst>'.str_repeat($relleno, 3).'</a:bgFillStyleLst></a:fmtScheme>'
            .'</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }

    private function x(string $v): string
    {
        // Fuera los caracteres de control que XML no admite (los traería un texto pegado)
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
