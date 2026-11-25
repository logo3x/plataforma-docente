<?php

namespace Database\Seeders;

use App\Dictados\AltaMateria;
use App\Models\User;
use App\Notas\RegistroNotas;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración: un docente, una materia con dos grupos y notas del corte 1.
 * Solo para desarrollo:  php artisan db:seed
 * Usuario: demo@ejemplo.edu.co / demo-plataforma-2026
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Los datos de demostración no se cargan en producción.');

            return;
        }

        $docente = User::query()->firstOrCreate(
            ['email' => 'demo@ejemplo.edu.co'],
            ['name' => 'Docente de demostración', 'password' => 'demo-plataforma-2026'],
        );

        $dictado = app(AltaMateria::class)->crear($docente, [
            'nombre' => 'Estructura de Datos', 'codigo' => 'DEMO-ED', 'nivel' => 'universidad',
            'periodo' => '2026B', 'grupos' => ['Diurno', 'Nocturno'],
        ]);

        $nombres = [
            ['ARIAS BELTRAN', 'CAMILA'], ['BOHORQUEZ DIAZ', 'JUAN DAVID'], ['CASTRO PINEDA', 'LAURA'],
            ['DUARTE MEJIA', 'ANDRES'], ['ESPINOSA ROJAS', 'VALENTINA'], ['FLOREZ GIL', 'SANTIAGO'],
            ['GARCIA LOZANO', 'MARIANA'], ['HERRERA CASTILLO', 'DANIEL'],
        ];
        $notas = [['4.5', '4', '3.8'], ['2.5', null, '2'], ['5', '4.5', '4.8'], ['3', '3', '2.9'],
            ['0', '3.5', '3.2'], ['3.8', '4.2', '4'], ['2', '2.5', '1.5'], ['4', '3.6', null]];

        $corte1 = $dictado->divisiones()->orderBy('orden')->first();
        [$trabajos, $parcial] = $corte1->componentes()->orderBy('orden')->get()->all();
        $diurno = $dictado->grupos()->where('nombre', 'Diurno')->first();

        $quiz = $trabajos->actividades()->create(['nombre' => 'Quiz 1', 'valor_max' => '5', 'orden' => 1]);
        $taller = $trabajos->actividades()->create(['nombre' => 'Taller 1', 'valor_max' => '5', 'orden' => 2]);
        $p1 = $parcial->actividades()->create(['nombre' => 'Parcial 1', 'valor_max' => '5', 'orden' => 1]);
        foreach ([$quiz, $taller, $p1] as $a) {
            $a->grupos()->attach($diurno->id);
        }

        $registro = app(RegistroNotas::class);
        foreach ($nombres as $i => [$apellidos, $nombre]) {
            $e = $dictado->estudiantes()->create(['documento' => (string) (1098000 + $i), 'apellidos' => $apellidos, 'nombres' => $nombre]);
            $diurno->estudiantes()->attach($e->id);
            foreach ([$quiz, $taller, $p1] as $j => $a) {
                if ($notas[$i][$j] !== null) {
                    $registro->asignar($a, $e, $notas[$i][$j], 'digitada', 'Datos de demostración');
                }
            }
        }

        $this->command?->info('Demo lista: demo@ejemplo.edu.co / demo-plataforma-2026');
    }
}
