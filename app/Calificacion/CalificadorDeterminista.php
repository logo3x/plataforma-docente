<?php

namespace App\Calificacion;

use App\Notas\Calculo;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Parte del servidor de la calificación híbrida: califica los tipos de pregunta cuya
 * respuesta se compara sin ejecutar nada (5.3). Los retos de código los ejecuta el
 * navegador del docente (ver ResultadosNavegador) y las abiertas las califica el docente (6.7).
 *
 * El crédito parcial se calcula como  máximo × aciertos / decisiones  en una sola división,
 * para no acumular error de redondeo.
 */
final class CalificadorDeterminista
{
    public const TIPOS_SERVIDOR = ['unica', 'vf', 'multiple', 'emparejar', 'ordenar', 'completar'];

    /** Los califica el servidor: los tipos deterministas y los retos de revisión estática (PHP y Laravel). */
    public static function loCalificaElServidor(string $tipo, array $clave): bool
    {
        return in_array($tipo, self::TIPOS_SERVIDOR, true) || ($tipo === 'reto' && ($clave['lenguaje'] ?? null) === 'php');
    }

    /**
     * @param  array<string, mixed>  $clave  solucionario del ítem (Pregunta::clave)
     */
    public function calificar(string $tipo, array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        return match ($tipo) {
            'unica' => $this->unica($clave, $respuesta, $max),
            'vf' => $this->verdaderoFalso($clave, $respuesta, $max),
            'multiple' => $this->multiple($clave, $respuesta, $max),
            'emparejar' => $this->emparejar($clave, $respuesta, $max),
            'ordenar' => $this->ordenar($clave, $respuesta, $max),
            'completar' => $this->completar($clave, $respuesta, $max),
            'abierta' => ResultadoItem::pendiente($max, 'docente'),
            // PHP y Laravel no se ejecutan: el servidor los revisa con criterios estáticos (4.6)
            'reto' => ($clave['lenguaje'] ?? null) === 'php'
                ? (new RevisionEstatica)->calificar($clave, $respuesta, $max)
                : ResultadoItem::pendiente($max, 'navegador'),
            default => throw new InvalidArgumentException("Tipo de pregunta desconocido: {$tipo}."),
        };
    }

    private function unica(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $acierto = $respuesta !== null && (string) $respuesta === (string) $clave['correcta'];

        return new ResultadoItem($acierto ? $max : '0', $max, [
            'respuesta' => $respuesta,
            'correcta' => $clave['correcta'],
        ]);
    }

    private function verdaderoFalso(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $acierto = is_bool($respuesta) && $respuesta === (bool) $clave['correcta'];

        return new ResultadoItem($acierto ? $max : '0', $max, [
            'respuesta' => $respuesta,
            'correcta' => (bool) $clave['correcta'],
        ]);
    }

    /**
     * Cada opción es una decisión (marcar o no marcar). No marcar nada equivale a no
     * responder y no otorga los puntos de las opciones falsas (5.3).
     */
    private function multiple(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $opciones = array_map('strval', $clave['opciones']);
        $correctas = array_map('strval', $clave['correctas']);
        $marcadas = array_map('strval', is_array($respuesta) ? $respuesta : []);

        $decisiones = [];
        $aciertos = 0;
        foreach ($opciones as $op) {
            $debia = in_array($op, $correctas, true);
            $marco = in_array($op, $marcadas, true);
            $ok = $marcadas !== [] && $debia === $marco;
            $aciertos += $ok ? 1 : 0;
            $decisiones[] = ['opcion' => $op, 'marcada' => $marco, 'debia_marcarse' => $debia, 'acierto' => $ok];
        }

        return new ResultadoItem($this->fraccion($max, $aciertos, count($opciones)), $max, [
            'decisiones' => $decisiones,
            'sin_responder' => $marcadas === [],
        ]);
    }

    private function emparejar(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $pares = $clave['pares'];
        $resp = is_array($respuesta) ? $respuesta : [];

        $detalle = [];
        $aciertos = 0;
        foreach ($pares as $izq => $der) {
            $dado = $resp[$izq] ?? null;
            $ok = $dado !== null && (string) $dado === (string) $der;
            $aciertos += $ok ? 1 : 0;
            $detalle[] = ['elemento' => $izq, 'respuesta' => $dado, 'correcta' => $der, 'acierto' => $ok];
        }

        return new ResultadoItem($this->fraccion($max, $aciertos, count($pares)), $max, ['pares' => $detalle]);
    }

    private function ordenar(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $orden = array_values($clave['orden']);
        $resp = is_array($respuesta) ? array_values($respuesta) : [];

        $detalle = [];
        $aciertos = 0;
        foreach ($orden as $pos => $paso) {
            $dado = $resp[$pos] ?? null;
            $ok = $dado !== null && (string) $dado === (string) $paso;
            $aciertos += $ok ? 1 : 0;
            $detalle[] = ['posicion' => $pos + 1, 'respuesta' => $dado, 'correcta' => $paso, 'acierto' => $ok];
        }

        return new ResultadoItem($this->fraccion($max, $aciertos, count($orden)), $max, ['posiciones' => $detalle]);
    }

    /**
     * Huecos de código: se comparan sin distinguir mayúsculas, espacios ni el símbolo
     * inicial de directiva (5.3). El desglose lo dice, para que nadie atribuya una pérdida
     * a la digitación (6.4).
     */
    private function completar(array $clave, mixed $respuesta, string $max): ResultadoItem
    {
        $huecos = array_values($clave['huecos']);
        $resp = is_array($respuesta) ? array_values($respuesta) : [];

        $detalle = [];
        $aciertos = 0;
        foreach ($huecos as $i => $esperado) {
            $aceptadas = array_map([$this, 'normalizarHueco'], (array) $esperado);
            $dado = $resp[$i] ?? null;
            $ok = $dado !== null && in_array($this->normalizarHueco((string) $dado), $aceptadas, true);
            $aciertos += $ok ? 1 : 0;
            $detalle[] = ['hueco' => $i + 1, 'respuesta' => $dado, 'correcta' => $esperado, 'acierto' => $ok];
        }

        return new ResultadoItem($this->fraccion($max, $aciertos, count($huecos)), $max, [
            'huecos' => $detalle,
            'nota' => 'Se compara sin distinguir mayúsculas, espacios ni el símbolo inicial de directiva (@).',
        ]);
    }

    private function normalizarHueco(string $texto): string
    {
        $t = mb_strtolower(preg_replace('/\s+/u', '', $texto) ?? '');

        return ltrim($t, '@');
    }

    private function fraccion(string $max, int $aciertos, int $total): string
    {
        if ($total === 0) {
            return '0';
        }

        return Calculo::normalizar(
            BigDecimal::of($max)->multipliedBy($aciertos)->dividedBy($total, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven)
        );
    }
}
