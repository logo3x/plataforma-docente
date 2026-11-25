<?php

namespace App\Notas;

use App\Models\Actividad;
use App\Models\Dictado;
use App\Models\Division;
use App\Models\Estudiante;
use App\Models\SegundaOportunidad;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Supletorios, habilitaciones y recuperaciones (3.6).
 *
 *  - Supletorio y recuperación: sobre una actividad.  Habilitación: sobre un corte o la materia.
 *  - La nota original nunca se borra; la segunda oportunidad es un evento aparte.
 *  - La regla la elige el docente: reemplaza, promedia, o tope (sin superar un máximo,
 *    por defecto la nota aprobatoria).
 *
 * El reglamento que decide quién tiene derecho es de la institución; la aplicación solo registra.
 */
final class SegundasOportunidades
{
    public function __construct(private readonly RegistroNotas $registro) {}

    /** Nota vigente según la regla. $original null = no tenía nota (por ejemplo, no presentó). */
    public static function aplicar(?string $original, SegundaOportunidad $s, Escala $escala): string
    {
        $valor = BigDecimal::of($s->valor);

        $vigente = match ($s->regla) {
            'reemplaza' => $valor,
            'promedia' => $original === null ? $valor
                : BigDecimal::of($original)->plus($valor)->dividedBy(2, Calculo::ESCALA_INTERNA, RoundingMode::HalfEven),
            'tope' => BigDecimal::min($valor, BigDecimal::of($s->tope ?? $escala->aprobatoria)),
            default => throw new InvalidArgumentException("Regla desconocida: {$s->regla}."),
        };

        return Calculo::normalizar($vigente);
    }

    /**
     * Arma el evento sin guardarlo: sirve para simular (3.7.4) y para registrar.
     *
     * @param  array{ambito: string, tipo: string, valor: string, regla: string, tope?: ?string, fecha?: ?string, motivo?: ?string}  $d
     *                                                                                                                                   ambito: «actividad:ID», «division:ID» o «dictado»
     */
    public function construir(Estudiante $e, array $d): SegundaOportunidad
    {
        $dictado = $e->dictado;
        $escala = $dictado->escala();
        $ambito = $this->ambito($dictado, $d['ambito']);

        $permitidos = $ambito instanceof Actividad ? ['supletorio', 'recuperacion'] : ['habilitacion'];
        if (! in_array($d['tipo'], $permitidos, true)) {
            throw new InvalidArgumentException($ambito instanceof Actividad
                ? 'Sobre una actividad se registra un supletorio o una recuperación.'
                : 'Sobre un corte o la materia se registra una habilitación.');
        }
        foreach (['valor' => $d['valor'], 'tope' => $d['tope'] ?? null] as $campo => $v) {
            if ($v !== null && $v !== '' && ! $escala->contiene((string) $v)) {
                throw new InvalidArgumentException("El {$campo} {$v} está fuera de la escala {$escala->mostrar($escala->minimo)} a {$escala->mostrar($escala->maximo)}.");
            }
        }

        return new SegundaOportunidad([
            'estudiante_id' => $e->id,
            'user_id' => auth()->id(),
            'ambito_type' => $ambito->getMorphClass(),
            'ambito_id' => $ambito->getKey(),
            'tipo' => $d['tipo'],
            'valor' => Calculo::paraGuardar((string) $d['valor']),
            'regla' => $d['regla'],
            'tope' => $d['regla'] === 'tope' ? Calculo::paraGuardar((string) (($d['tope'] ?? '') !== '' ? $d['tope'] : $escala->aprobatoria)) : null,
            'fecha' => $d['fecha'] ?? now()->toDateString(),
            'motivo' => $d['motivo'] ?? null,
        ]);
    }

    public function registrar(Estudiante $e, array $d): SegundaOportunidad
    {
        $s = $this->construir($e, $d);

        return DB::transaction(function () use ($s, $e) {
            $s->save();
            if ($s->ambito instanceof Actividad) {
                $this->registro->registrarEvento($s->ambito, $e, $s->tipo, $s->valor,
                    SegundaOportunidad::TIPOS[$s->tipo].' ('.SegundaOportunidad::REGLAS[$s->regla].'). La nota original se conserva.'
                    .($s->motivo ? " {$s->motivo}" : ''));
            }

            return $s;
        });
    }

    public function anular(SegundaOportunidad $s, string $motivo): void
    {
        DB::transaction(function () use ($s, $motivo) {
            $s->update(['anulada_en' => now(), 'motivo_anulacion' => $motivo]);
            if ($s->ambito instanceof Actividad) {
                $this->registro->registrarEvento($s->ambito, $s->estudiante, 'anula_'.$s->tipo, null,
                    'Se anula el '.mb_strtolower(SegundaOportunidad::TIPOS[$s->tipo])." del {$s->fecha->format('Y-m-d')}: {$motivo}");
            }
        });
    }

    /** @return Actividad|Division|Dictado */
    private function ambito(Dictado $dictado, string $clave): Model
    {
        if ($clave === 'dictado') {
            return $dictado;
        }
        [$tipo, $id] = array_pad(explode(':', $clave, 2), 2, null);

        return match ($tipo) {
            'actividad' => Actividad::query()->whereHas('componente.division', fn ($q) => $q->where('dictado_id', $dictado->id))->findOrFail((int) $id),
            'division' => $dictado->divisiones()->whereNull('parent_id')->findOrFail((int) $id),
            default => throw new InvalidArgumentException('Ámbito no válido.'),
        };
    }
}
