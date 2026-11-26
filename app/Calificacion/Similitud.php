<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Entrega;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Detección de similitud entre entregas (6.11). Compara, pregunta por pregunta, las respuestas
 * abiertas y el código de los retos por fragmentos de k elementos seguidos:
 *  - en el código se ignoran los comentarios y el espaciado, y todo nombre propio (variables,
 *    funciones) cuenta igual, así que renombrar no esconde la copia (6.11.2);
 *  - en el texto se ignoran mayúsculas, tildes, signos y palabras vacías;
 *  - no cuentan los fragmentos de la plantilla o del enunciado ni los que escribe casi todo el
 *    grupo (la forma esperada de resolver), que no son señal de copia.
 * El puntaje es la parte de la respuesta más corta que aparece en la otra, de modo que copiar
 * solo un trozo también se ve. Señal, no veredicto: no acusa ni toca la nota (6.11.4).
 */
final class Similitud
{
    public const UMBRAL = 0.6;

    public const ALTA = 0.85;

    /** Fragmentos mínimos para comparar: una respuesta muy corta no es señal. */
    private const MINIMO = 6;

    private const K_CODIGO = 6;

    private const K_TEXTO = 4;

    private const RESERVADAS = ['function', 'return', 'if', 'else', 'for', 'while', 'do', 'switch', 'case', 'break', 'continue', 'default', 'const', 'let', 'var',
        'new', 'this', 'class', 'extends', 'super', 'typeof', 'instanceof', 'in', 'of', 'try', 'catch', 'finally', 'throw', 'true', 'false', 'null', 'undefined',
        'async', 'await', 'yield', 'delete', 'void', 'length', 'push', 'pop', 'shift', 'unshift', 'map', 'filter', 'reduce', 'forEach', 'slice', 'splice', 'join',
        'split', 'includes', 'indexOf', 'keys', 'values', 'entries', 'Math', 'Number', 'String', 'Array', 'Object', 'JSON', 'console', 'log', 'parseInt', 'parseFloat'];

    private const VACIAS = ['de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'se', 'del', 'las', 'un', 'por', 'con', 'no', 'una', 'su', 'para', 'es', 'al', 'lo',
        'como', 'mas', 'o', 'pero', 'sus', 'le', 'ya', 'este', 'esta', 'si', 'porque', 'muy', 'sin', 'sobre', 'tambien', 'me', 'hasta', 'donde', 'quien', 'desde',
        'todo', 'nos', 'durante', 'uno', 'ni', 'contra', 'ese', 'eso', 'esa', 'e', 'u', 'son', 'ser', 'cada', 'entre', 'cuando', 'hay', 'fue'];

    /**
     * Pares de entregas parecidas en la actividad. Con $otrosPeriodos compara además contra las
     * entregas de las demás aplicaciones del mismo examen (6.11.5).
     *
     * @return Collection<int, array{a: Entrega, b: Entrega, puntaje: float, items: array<int, array{item: int, numero: int, tipo: string, puntaje: float}>}>
     */
    public function pares(Actividad $actividad, bool $otrosPeriodos = false): Collection
    {
        $actividades = collect([$actividad->id]);
        if ($otrosPeriodos && $actividad->artefacto_id) {
            $actividades = Actividad::query()->where('artefacto_id', $actividad->artefacto_id)->pluck('id');
        }
        $entregas = Entrega::query()->whereIn('actividad_id', $actividades)
            ->with(['integrantes', 'actividad.componente.division.dictado', 'respuestas.item.pregunta'])->get()->keyBy('id');

        // Por pregunta: los fragmentos de cada entrega
        $porItem = [];
        foreach ($entregas as $e) {
            foreach ($e->respuestas as $r) {
                $texto = RevisionEstatica::texto($r->respuesta['valor'] ?? null);
                if (! in_array($r->item->pregunta->tipo, ['abierta', 'reto'], true) || $texto === '') {
                    continue;
                }
                $porItem[$r->item->pregunta_id]['tipo'] = $r->item->pregunta->tipo;
                $porItem[$r->item->pregunta_id]['pregunta'] = $r->item->pregunta;
                $porItem[$r->item->pregunta_id]['entregas'][$e->id] = $texto;
                $porItem[$r->item->pregunta_id]['numero'][$e->id] = $r->item->orden + 1;
            }
        }

        $pares = [];
        foreach ($porItem as $preguntaId => $datos) {
            $codigo = $datos['tipo'] === 'reto';
            $huellas = array_map(fn ($t) => array_keys($this->fragmentos($t, $codigo)), $datos['entregas']);
            $ignorar = $this->ignorables($datos['pregunta'], $codigo, $huellas);
            $huellas = array_map(fn ($h) => array_values(array_diff($h, $ignorar)), $huellas);

            $ids = array_keys($huellas);
            foreach ($ids as $i => $x) {
                foreach (array_slice($ids, $i + 1) as $y) {
                    // Solo interesan los pares en que al menos una entrega es de esta actividad
                    if ($entregas[$x]->actividad_id !== $actividad->id && $entregas[$y]->actividad_id !== $actividad->id) {
                        continue;
                    }
                    $menor = min(count($huellas[$x]), count($huellas[$y]));
                    if ($menor < self::MINIMO) {
                        continue;
                    }
                    $p = count(array_intersect($huellas[$x], $huellas[$y])) / $menor;
                    if ($p >= self::UMBRAL) {
                        [$a, $b] = $this->ordenar($entregas[$x], $entregas[$y], $actividad);
                        $pares["{$a->id}-{$b->id}"]['a'] = $a;
                        $pares["{$a->id}-{$b->id}"]['b'] = $b;
                        $pares["{$a->id}-{$b->id}"]['items'][] = ['item' => $preguntaId, 'numero' => $datos['numero'][$x], 'tipo' => $datos['tipo'], 'puntaje' => round($p, 2)];
                    }
                }
            }
        }

        return collect($pares)->map(function ($p) {
            usort($p['items'], fn ($m, $n) => $m['numero'] <=> $n['numero']);

            return $p + ['puntaje' => max(array_column($p['items'], 'puntaje'))];
        })->sortByDesc('puntaje')->values();
    }

    /**
     * Las dos respuestas de una pregunta, partidas en tramos marcados donde coinciden, para
     * verlas lado a lado (6.11.3). El texto no se convierte en HTML aquí.
     *
     * @return array{0: array<int, array{texto: string, coincide: bool}>, 1: array<int, array{texto: string, coincide: bool}>}
     */
    public function comparar(string $a, string $b, bool $codigo): array
    {
        $fa = $this->fragmentos($a, $codigo);
        $fb = $this->fragmentos($b, $codigo);
        $comunes = array_keys(array_intersect_key($fa, $fb));

        return [$this->tramos($a, $codigo, array_intersect_key($fa, array_flip($comunes))), $this->tramos($b, $codigo, array_intersect_key($fb, array_flip($comunes)))];
    }

    /**
     * Fragmentos de k elementos normalizados, con dónde empieza y termina cada aparición.
     *
     * @return array<string, array<int, int>> huella => posiciones (en la lista de elementos) donde empieza
     */
    public function fragmentos(string $texto, bool $codigo): array
    {
        $tokens = $this->tokens($texto, $codigo);
        $k = $codigo ? self::K_CODIGO : self::K_TEXTO;
        $f = [];
        for ($i = 0; $i + $k <= count($tokens); $i++) {
            $f[hash('xxh64', implode("\x1f", array_column(array_slice($tokens, $i, $k), 0)))][] = $i;
        }

        return $f;
    }

    /** @return array<int, array{0: string, 1: int, 2: int}> [forma normalizada, desde, hasta] en bytes */
    public function tokens(string $texto, bool $codigo): array
    {
        return $codigo ? $this->tokensCodigo($texto) : $this->tokensTexto($texto);
    }

    /** @return array<int, array{0: string, 1: int, 2: int}> [forma normalizada, desde, hasta] */
    private function tokensCodigo(string $t): array
    {
        preg_match_all('~(//[^\n]*|/\*.*?\*/)|("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`)|(\b\d+(?:\.\d+)?\b)|([A-Za-z_$][\w$]*)|([^\s\w])~s',
            $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $tokens = [];
        foreach ($m as $x) {
            [$texto, $desde] = $x[0];
            $forma = match (true) {
                $x[1][0] !== null => null,                                                  // comentario: no cuenta
                $x[2][0] !== null => 'S',
                $x[3][0] !== null => 'N',
                $x[4][0] !== null => in_array($texto, self::RESERVADAS, true) ? $texto : 'V',  // renombrar no esconde la copia
                default => $texto,
            };
            if ($forma !== null) {
                $tokens[] = [$forma, $desde, $desde + strlen($texto)];
            }
        }

        return $tokens;
    }

    /** @return array<int, array{0: string, 1: int, 2: int}> */
    private function tokensTexto(string $t): array
    {
        preg_match_all('/\p{L}[\p{L}\p{N}]*|\p{N}+/u', $t, $m, PREG_OFFSET_CAPTURE);
        $tokens = [];
        foreach ($m[0] as [$palabra, $desde]) {
            $forma = Str::lower(Str::ascii($palabra));
            if (! in_array($forma, self::VACIAS, true)) {
                $tokens[] = [$forma, $desde, $desde + strlen($palabra)];
            }
        }

        return $tokens;
    }

    /** Fragmentos que no son señal: los de la plantilla o el enunciado y los que escribe casi todo el grupo. */
    private function ignorables($pregunta, bool $codigo, array $huellas): array
    {
        $base = $codigo ? (string) ($pregunta->enunciado['plantilla_codigo'] ?? '') : (string) ($pregunta->enunciado['texto'] ?? '');
        $ignorar = array_keys($this->fragmentos($base, $codigo));

        $tope = max(3, (int) floor(count($huellas) * 0.4));
        $cuenta = array_count_values(array_merge(...array_values(array_map('array_unique', $huellas)) ?: [[]]));

        return array_merge($ignorar, array_keys(array_filter($cuenta, fn ($n) => $n > $tope)));
    }

    /**
     * Marca solo los elementos que coinciden y el espacio en blanco entre dos seguidos: un
     * comentario o un texto distinto en medio nunca sale como coincidencia.
     *
     * @return array<int, array{texto: string, coincide: bool}>
     */
    private function tramos(string $texto, bool $codigo, array $comunes): array
    {
        $tokens = $this->tokens($texto, $codigo);
        $k = $codigo ? self::K_CODIGO : self::K_TEXTO;
        $marcados = [];
        foreach ($comunes as $inicios) {
            foreach ($inicios as $i) {
                for ($j = $i; $j < $i + $k; $j++) {
                    $marcados[$j] = true;
                }
            }
        }
        ksort($marcados);

        // Rangos de bytes marcados, uniendo elementos seguidos separados solo por espacios
        $rangos = [];
        foreach (array_keys($marcados) as $j) {
            [, $desde, $hasta] = $tokens[$j];
            $ultimo = array_key_last($rangos);
            if ($ultimo !== null && isset($marcados[$j - 1]) && trim(substr($texto, $rangos[$ultimo][1], $desde - $rangos[$ultimo][1])) === '') {
                $rangos[$ultimo][1] = $hasta;
            } else {
                $rangos[] = [$desde, $hasta];
            }
        }

        $tramos = [];
        $pos = 0;
        foreach ($rangos as [$desde, $hasta]) {
            if ($desde > $pos) {
                $tramos[] = ['texto' => substr($texto, $pos, $desde - $pos), 'coincide' => false];
            }
            $tramos[] = ['texto' => substr($texto, $desde, $hasta - $desde), 'coincide' => true];
            $pos = $hasta;
        }
        if ($pos < strlen($texto)) {
            $tramos[] = ['texto' => substr($texto, $pos), 'coincide' => false];
        }

        return $tramos;
    }

    /** La de esta actividad primero; entre dos de la misma, la de menor id. */
    private function ordenar(Entrega $x, Entrega $y, Actividad $actividad): array
    {
        if ($x->actividad_id !== $y->actividad_id) {
            return $x->actividad_id === $actividad->id ? [$x, $y] : [$y, $x];
        }

        return $x->id < $y->id ? [$x, $y] : [$y, $x];
    }
}
