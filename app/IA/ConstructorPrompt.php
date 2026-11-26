<?php

namespace App\IA;

use App\Calificacion\BitacoraAula;
use App\Models\Contenido;
use App\Models\Dictado;
use App\Models\Materia;
use App\Models\PlantillaPrompt;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Arma el prompt con las tres capas de la sección 8: contexto general (1), estilo por materia (2)
 * e instrucción de la tarea (3), más el material seleccionado del repositorio (7.4).
 * Los campos se llenan con lo que la aplicación ya sabe (8.4.2).
 */
final class ConstructorPrompt
{
    /** Plantilla vigente de una capa: la última versión del docente o la de fábrica. */
    public function plantilla(User $docente, string $clave): array
    {
        $p = PlantillaPrompt::query()->where('user_id', $docente->id)->whereNull('materia_id')
            ->where('clave', $clave)->where('activa', true)->orderByDesc('version')->first();

        return $p ? ['cuerpo' => $p->cuerpo, 'version' => $p->version, 'id' => $p->id]
            : ['cuerpo' => config("ia.plantillas.{$clave}"), 'version' => 0, 'id' => null];
    }

    public function guardarPlantilla(User $docente, string $clave, string $cuerpo): PlantillaPrompt
    {
        $capa = ['contexto' => 'contexto', 'materia' => 'materia'][$clave] ?? 'tarea';
        $version = (int) PlantillaPrompt::query()->where('user_id', $docente->id)->whereNull('materia_id')->where('clave', $clave)->max('version') + 1;

        return PlantillaPrompt::query()->create([
            'user_id' => $docente->id, 'materia_id' => null, 'capa' => $capa, 'clave' => $clave,
            'nombre' => ucfirst($clave)." v{$version}", 'cuerpo' => $cuerpo, 'version' => $version,
        ]);
    }

    /** @return array<string, string> */
    public function perfil(User $docente): array
    {
        return array_merge(config('ia.perfil_por_defecto'), array_filter($docente->perfil_ia ?? []));
    }

    /**
     * @param  array<string, string>  $tarea  variables de la capa 3
     * @param  Collection<int, Contenido>  $contenidos
     * @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool}
     */
    public function construir(User $docente, Materia $materia, Dictado $dictado, string $claveTarea, array $tarea, Collection $contenidos, array $capa2 = []): array
    {
        $escala = $dictado->escala();
        $estilo = $materia->estilo ?? [];
        $comunes = [
            'docente' => $docente->name,
            'nota_minima' => $escala->mostrar($escala->minimo),
            'nota_maxima' => $escala->mostrar($escala->maximo),
            'nota_aprobatoria' => $escala->mostrar($escala->aprobatoria),
            'pesos_cortes' => $dictado->divisiones()->whereNull('parent_id')->orderBy('orden')->get()
                ->map(fn ($d) => "{$d->nombre} = {$d->porcentaje()} %")->join(', '),
        ];

        $p1 = $this->plantilla($docente, 'contexto');
        $p2 = $this->plantilla($docente, 'materia');
        $p3 = $this->plantilla($docente, $claveTarea);

        $capa1 = $this->llenar($p1['cuerpo'], $this->perfil($docente) + $comunes);
        $capa2 = $this->llenar($p2['cuerpo'], array_merge([
            'materia' => $materia->nombre,
            'codigo_materia' => $materia->codigo ?? '—',
            'unidades' => $estilo['unidades'] ?? '—',
            'alcance_grupo' => $estilo['alcance_grupo'] ?? '—',
            'herramientas' => $estilo['herramientas'] ?? '—',
            'modalidad' => ['integrada' => 'integrada (el código corre en el navegador)', 'anexo' => 'anexo externo', 'ninguna' => 'sin parte práctica de código'][$materia->modalidad_practica] ?? '—',
            'tono' => $estilo['tono'] ?? 'directo y profesional',
            'tipo_verificacion' => $estilo['tipo_verificacion'] ?? '—',
            'glosario' => $estilo['glosario'] ?? '—',
        ], $capa2));
        $capa3 = $this->llenar($p3['cuerpo'], $tarea + $comunes);

        [$material, $recortado, $meta] = $this->material($contenidos);

        // Anexo B: lo aprendido en el aula con esta materia, para no repetir los mismos tropiezos
        $lecciones = app(BitacoraAula::class)->leccionesParaIa($materia);

        return [
            'sistema' => "Usted es el asistente de un docente universitario para crear material de clase y evaluaciones.\n\n"
                ."## Contexto general\n{$capa1}\n\n## Estilo de la materia\n{$capa2}"
                .($lecciones !== '' ? "\n\n## Lecciones aprendidas en el aula con esta materia (téngalas en cuenta)\n{$lecciones}" : ''),
            'usuario' => "{$capa3}\n\n## Material de referencia del docente\n".($material ?: '(No se seleccionó material: use solo el tema indicado.)'),
            'plantillas' => [
                'contexto' => $p1['version'], 'materia' => $p2['version'], $claveTarea => $p3['version'],
            ],
            'contenidos' => $meta,
            'recortado' => $recortado,
        ];
    }

    /** Reemplaza {{campo}}. Un campo obligatorio sin valor detiene el armado (8.4.1). */
    public function llenar(string $plantilla, array $valores): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($valores) {
            if (! array_key_exists($m[1], $valores) || $valores[$m[1]] === null) {
                throw new InvalidArgumentException("Falta el valor de «{$m[1]}» para armar el prompt.");
            }

            return (string) $valores[$m[1]];
        }, $plantilla);
    }

    /**
     * Material seleccionado, recortado a la ventana configurada. Se avisa si se recorta (8.4).
     *
     * @return array{0: string, 1: bool, 2: array<int, array{id: int, titulo: string, sha256: string, caracteres: int}>}
     */
    private function material(Collection $contenidos): array
    {
        $limite = config('ia.max_contexto_caracteres');
        $usado = 0;
        $partes = [];
        $meta = [];
        $recortado = false;

        foreach ($contenidos as $c) {
            $texto = (string) $c->texto_extraido;
            $disponible = $limite - $usado;
            if ($disponible <= 200) {
                $recortado = true;
                break;
            }
            if (mb_strlen($texto) > $disponible) {
                $texto = mb_substr($texto, 0, $disponible).'…';
                $recortado = true;
            }
            $usado += mb_strlen($texto);
            $partes[] = "### {$c->titulo}\n{$texto}";
            $meta[] = ['id' => $c->id, 'titulo' => $c->titulo, 'sha256' => $c->sha256, 'caracteres' => mb_strlen($texto)];
        }

        return [implode("\n\n", $partes), $recortado, $meta];
    }
}
