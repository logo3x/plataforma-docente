<?php

namespace App\Calificacion;

use App\Notas\Calculo;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Blade;
use ParseError;
use PhpToken;
use Throwable;

/**
 * Retos de PHP y Laravel (4.6): sin ejecución. El código se revisa con criterios explicados en lenguaje
 * natural y el resultado dice qué se comprobó y qué no, para no dar la falsa sensación de que funciona.
 *
 * Criterios de cada verificación (uno por verificación):
 *  - patron:   expresión regular que debe aparecer (o no, con "ausente": true);
 *  - contiene: texto que debe aparecer, sin importar espacios ni saltos de línea (se comparan sin ellos);
 *  - sintaxis: el archivo se analiza sin errores (PHP, o Blade compilado a PHP). Analizar no es ejecutar.
 * Los comentarios se quitan antes de revisar, salvo "comentarios": true: escribir el código esperado en un
 * comentario no cumple el criterio.
 */
final class RevisionEstatica
{
    public const ALCANCE = 'No se ejecutó el código: se revisó que cumpla los criterios listados. Que los cumpla no garantiza que el proyecto funcione.';

    /** Archivos de la respuesta: un texto (un solo archivo) o {nombre: código}. */
    public static function archivos(mixed $respuesta, array $nombres): array
    {
        $nombres = $nombres ?: ['codigo.php'];
        if (is_string($respuesta)) {
            return [$nombres[0] => $respuesta];
        }
        $r = [];
        foreach ($nombres as $n) {
            $r[$n] = is_array($respuesta) && is_string($respuesta[$n] ?? null) ? $respuesta[$n] : '';
        }

        return $r;
    }

    /** Todo el código como un solo texto, con el nombre de cada archivo (similitud, detalle, informe). */
    public static function texto(mixed $respuesta): string
    {
        if (is_string($respuesta)) {
            return $respuesta;
        }
        if (! is_array($respuesta)) {
            return '';
        }

        return collect($respuesta)->filter(fn ($c) => is_string($c))->map(fn ($c, $n) => "// ===== {$n} =====\n{$c}")->join("\n\n");
    }

    public function calificar(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $archivos = self::archivos($respuesta, $clave['archivos'] ?? []);
        $total = BigDecimal::zero();
        $detalle = [];
        foreach ($clave['verificaciones'] as $v) {
            [$ok, $nota] = $this->cumple($v, $archivos);
            $valor = BigDecimal::of($max)->multipliedBy($v['fraccion']);
            if ($ok) {
                $total = $total->plus($valor);
            }
            $detalle[] = ['nombre' => $v['nombre'], 'superada' => $ok, 'valor' => Calculo::normalizar($valor)] + ($nota ? ['nota' => $nota] : []);
        }

        return new ResultadoItem(Calculo::paraGuardar((string) $total), $max, [
            'estatica' => true,
            'verificaciones' => $detalle,
            'alcance' => $clave['alcance'] ?? null,
        ]);
    }

    /** @return array{0: bool, 1: ?string} si cumple, y una nota para el detalle (el error de sintaxis, por ejemplo) */
    public function cumple(array $v, array $archivos): array
    {
        $objetivo = isset($v['archivo']) ? [$v['archivo'] => $archivos[$v['archivo']] ?? ''] : $archivos;

        if (! empty($v['sintaxis'])) {
            foreach ($objetivo as $nombre => $codigo) {
                if (trim($codigo) === '') {
                    return [false, "{$nombre} está vacío."];
                }
                if ($error = $this->errorDeSintaxis($nombre, $codigo)) {
                    return [false, $error];
                }
            }

            return [true, null];
        }

        $texto = collect($objetivo)->map(fn ($c, $n) => empty($v['comentarios']) ? $this->sinComentarios($n, $c) : $c)->join("\n");
        if (isset($v['contiene'])) {
            $norm = fn (string $s) => preg_replace('/\s+/u', '', $s);

            return [str_contains($norm($texto), $norm((string) $v['contiene'])), null];
        }
        $hay = @preg_match(self::regex((string) $v['patron']), $texto) === 1;

        return [empty($v['ausente']) ? $hay : ! $hay, null];
    }

    /** El patrón se escribe sin delimitadores. */
    public static function regex(string $patron): string
    {
        return '~'.preg_replace('/(?<!\\\\)~/', '\\~', $patron).'~u';
    }

    public static function patronValido(string $patron): bool
    {
        return @preg_match(self::regex($patron), '') !== false;
    }

    /** Error de sintaxis con su línea, sin ejecutar nada: tokenizar con TOKEN_PARSE solo analiza. */
    public function errorDeSintaxis(string $nombre, string $codigo): ?string
    {
        $blade = str_ends_with($nombre, '.blade.php');
        try {
            $php = $blade ? Blade::compileString($codigo) : $codigo;
            PhpToken::tokenize($php, TOKEN_PARSE);

            return null;
        } catch (ParseError $e) {
            return $blade
                ? "{$nombre}: la vista no compila a PHP válido ({$this->traducir($e->getMessage())}). Revise las directivas y las expresiones entre {{ }}."
                : "{$nombre}, línea {$e->getLine()}: {$this->traducir($e->getMessage())}.";
        } catch (Throwable $e) {
            return "{$nombre}: no se pudo analizar ({$e->getMessage()}).";
        }
    }

    private function traducir(string $m): string
    {
        return match (true) {
            (bool) preg_match("/Unclosed '(.)' on line (\\d+)/", $m, $x) => "se abrió «{$x[1]}» en la línea {$x[2]} y no se cerró",
            str_contains($m, 'unexpected end of file') => 'el archivo termina con algo sin cerrar (llave, paréntesis o comillas)',
            (bool) preg_match('/unexpected token "([^"]+)", expecting "([^"]+)"/', $m, $x) => "se encontró «{$x[1]}» donde se esperaba «{$x[2]}»",
            (bool) preg_match('/unexpected token "([^"]+)"/', $m, $x) => "sobra o falta algo antes de «{$x[1]}»",
            (bool) preg_match('/unexpected (?:identifier|variable) "([^"]+)"/', $m, $x) => "«{$x[1]}» aparece donde no se esperaba: ¿falta un punto y coma o un operador antes?",
            default => $m,
        }." (original: {$m})";
    }

    private function sinComentarios(string $nombre, string $codigo): string
    {
        if (str_ends_with($nombre, '.blade.php')) {
            return preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s'], '', $codigo);
        }
        if (! str_ends_with($nombre, '.php')) {
            return $codigo;
        }
        try {
            $tokens = PhpToken::tokenize($codigo);
        } catch (Throwable) {
            return $codigo;
        }

        return collect($tokens)->reject(fn (PhpToken $t) => $t->is([T_COMMENT, T_DOC_COMMENT]))->map(fn (PhpToken $t) => $t->text)->join('');
    }
}
