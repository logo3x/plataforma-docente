<?php

namespace App\Dictados;

use App\Models\Dictado;
use App\Models\Materia;
use App\Models\User;
use App\Notas\CalculadoraNotas;
use App\Notas\Escala;
use Illuminate\Support\Facades\DB;

/**
 * Alta de materia en un paso (2.4): materia, primer dictado, divisiones, componentes y grupos.
 * Y apertura de un dictado nuevo que hereda la estructura del anterior (2.6.3, 2.8),
 * sin arrastrar nunca estudiantes ni notas.
 */
final class AltaMateria
{
    /**
     * @param  array{nombre:string, codigo:?string, nivel:string, periodo:string, grupos:array<int,string>,
     *               escala_min?:string, escala_max?:string, nota_aprobatoria?:string, decimales?:int,
     *               divisiones?:array}  $datos
     */
    public function crear(User $docente, array $datos): Dictado
    {
        $escala = new Escala(
            $datos['escala_min'] ?? '0',
            $datos['escala_max'] ?? '5',
            $datos['nota_aprobatoria'] ?? '3',
            (int) ($datos['decimales'] ?? 1),
        );
        $divisiones = $datos['divisiones'] ?? PlantillasPeriodo::para($datos['nivel']);
        $this->validarEstructura($divisiones, $escala);

        return DB::transaction(function () use ($docente, $datos, $escala, $divisiones) {
            $materia = Materia::query()->create([
                'user_id' => $docente->id,
                'nombre' => $datos['nombre'],
                'codigo' => $datos['codigo'] ?: null,
                'nivel' => $datos['nivel'],
                'plantilla_evaluacion' => $divisiones,
            ]);

            $dictado = $materia->dictados()->create([
                'periodo' => $datos['periodo'],
                'escala_min' => $escala->minimo,
                'escala_max' => $escala->maximo,
                'nota_aprobatoria' => $escala->aprobatoria,
                'decimales' => $escala->decimales,
            ]);

            $this->crearDivisiones($dictado, $divisiones);
            $this->crearGrupos($dictado, $datos['grupos']);

            return $dictado;
        });
    }

    /**
     * Dictado nuevo de una materia existente: hereda escala y estructura de evaluación del
     * dictado de origen; solo copia nombres de grupos. Estudiantes y notas no viajan (2.8.1).
     *
     * @param  array<int, string>|null  $grupos  null = mismos nombres de grupo que el origen
     */
    public function nuevoDictado(Dictado $origen, string $periodo, ?array $grupos = null): Dictado
    {
        return DB::transaction(function () use ($origen, $periodo, $grupos) {
            $dictado = $origen->materia->dictados()->create([
                'periodo' => $periodo,
                'origen_dictado_id' => $origen->id,
                'escala_min' => $origen->escala_min,
                'escala_max' => $origen->escala_max,
                'nota_aprobatoria' => $origen->nota_aprobatoria,
                'decimales' => $origen->decimales,
                'redondeo' => $origen->redondeo,
            ]);

            $estructura = $origen->divisiones()->whereNull('parent_id')->with('componentes')->get()
                ->map(fn ($d) => [
                    'nombre' => $d->nombre,
                    'peso' => $d->peso,
                    'componentes' => $d->componentes->map(fn ($c) => ['nombre' => $c->nombre, 'peso' => $c->peso])->all(),
                ])->all();

            $this->crearDivisiones($dictado, $estructura);
            $this->crearGrupos($dictado, $grupos ?? $origen->grupos()->pluck('nombre')->all());

            return $dictado;
        });
    }

    private function validarEstructura(array $divisiones, Escala $escala): void
    {
        $calc = new CalculadoraNotas($escala);
        $calc->validarPesos(array_column($divisiones, 'peso'), 'las divisiones del periodo');
        foreach ($divisiones as $d) {
            $calc->validarPesos(array_column($d['componentes'], 'peso'), "los componentes de «{$d['nombre']}»");
        }
    }

    private function crearDivisiones(Dictado $dictado, array $divisiones): void
    {
        foreach (array_values($divisiones) as $i => $d) {
            $division = $dictado->divisiones()->create(['nombre' => $d['nombre'], 'peso' => $d['peso'], 'orden' => $i]);
            foreach (array_values($d['componentes']) as $j => $c) {
                $division->componentes()->create(['nombre' => $c['nombre'], 'peso' => $c['peso'], 'orden' => $j]);
            }
        }
    }

    /** @param array<int, string> $nombres */
    private function crearGrupos(Dictado $dictado, array $nombres): void
    {
        $nombres = array_values(array_unique(array_filter(array_map('trim', $nombres))));
        foreach ($nombres ?: ['Único'] as $nombre) {
            $dictado->grupos()->create(['nombre' => $nombre]);
        }
    }
}
