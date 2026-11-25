<?php

namespace App\Dictados;

/**
 * Plantillas SUGERIDAS de divisiones del periodo según el nivel (2.7). El docente las
 * acepta, cambia o descarta; el sistema no impone ninguna.
 */
final class PlantillasPeriodo
{
    private const COMPONENTES = [
        ['nombre' => 'Trabajos y quices', 'peso' => '0.5'],
        ['nombre' => 'Parcial', 'peso' => '0.5'],
    ];

    /**
     * @return array<int, array{nombre:string, peso:string, componentes:array<int, array{nombre:string, peso:string}>}>
     */
    public static function para(string $nivel): array
    {
        return match ($nivel) {
            'universidad' => [
                ['nombre' => 'Corte 1', 'peso' => '0.3', 'componentes' => self::COMPONENTES],
                ['nombre' => 'Corte 2', 'peso' => '0.3', 'componentes' => self::COMPONENTES],
                ['nombre' => 'Corte 3', 'peso' => '0.4', 'componentes' => self::COMPONENTES],
            ],
            'colegio' => array_map(fn (int $i) => [
                'nombre' => "Periodo {$i}",
                'peso' => '0.25',
                'componentes' => [
                    ['nombre' => 'Actividades', 'peso' => '0.6'],
                    ['nombre' => 'Evaluación', 'peso' => '0.4'],
                ],
            ], [1, 2, 3, 4]),
            default => [
                ['nombre' => 'Periodo', 'peso' => '1', 'componentes' => [['nombre' => 'Actividades', 'peso' => '1']]],
            ],
        };
    }
}
