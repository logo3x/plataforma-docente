<?php

namespace App\Material;

use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Material;
use App\Models\TemarioItem;
use App\Models\User;
use App\Temario\ServicioTemario;
use InvalidArgumentException;

/**
 * Generador «Ejemplos prácticos» del catálogo (7.4): ejercicios resueltos paso a paso de un tema.
 * La IA entrega un BORRADOR que el docente revisa, verifica (el código, en el navegador), edita y
 * aprueba; los ejemplos a mano siempre están disponibles (1.2).
 */
final class GeneradorEjemplos
{
    public function __construct(
        private readonly ConstructorPrompt $prompts,
        private readonly GestorIA $ia,
        private readonly EjemplosPracticos $ejemplos,
    ) {}

    /**
     * @param  array{temas:array<int,int>, tema:?string, cantidad:int, dificultad:string, contexto:?string, grupo:?string, contenidos:array<int,int>}  $op
     * @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool}
     */
    public function preparar(User $docente, Materia $materia, Dictado $dictado, array $op): array
    {
        $op += ['temas' => [], 'tema' => null, 'contexto' => null, 'grupo' => null, 'contenidos' => [], 'dificultad' => 'mixta'];
        $temas = TemarioItem::query()->where('materia_id', $materia->id)->where('tipo', 'tema')->whereIn('id', $op['temas'] ?: [])->orderBy('orden')->get();
        $tema = trim($temas->map(fn ($t) => $t->titulo.($t->descripcion ? " ({$t->descripcion})" : ''))->join('; ')
            .($temas->isNotEmpty() && filled($op['tema']) ? '. ' : '').($op['tema'] ?? ''));
        if ($tema === '') {
            throw new InvalidArgumentException('Elija temas del temario o escriba el tema de los ejemplos.');
        }

        $grupo = filled($op['grupo']) ? $dictado->grupos()->where('nombre', $op['grupo'])->first() : null;
        $alcance = app(ServicioTemario::class)->alcance($materia, $grupo);
        $contenidos = Contenido::query()->where('materia_id', $materia->id)->whereIn('id', $op['contenidos'] ?: [])->get();

        // Memoria (7.3): no repetir los ejemplos que ya existen en la materia
        $previos = Material::query()->where('materia_id', $materia->id)->where('tipo', EjemplosPracticos::TIPO)->latest()->limit(10)->get()
            ->flatMap(fn (Material $m) => collect($m->contenido['ejemplos'] ?? [])->pluck('titulo'))->take(40)->map(fn ($t) => "- {$t}")->join("\n");

        return $this->prompts->construir($docente, $materia, $dictado, 'ejemplos', [
            'tema' => $tema,
            'cantidad' => (string) max(1, min(EjemplosPracticos::MAX_EJEMPLOS, (int) $op['cantidad'])),
            'dificultad' => ['mixta' => 'mixta: de menor a mayor', 'baja' => 'baja', 'media' => 'media', 'alta' => 'alta'][$op['dificultad']] ?? 'mixta: de menor a mayor',
            'contexto' => filled($op['contexto']) ? trim($op['contexto']) : 'situaciones cotidianas o del entorno profesional del programa',
            'grupo' => $op['grupo'] ?: 'todos los grupos del periodo',
            'reglas_codigo' => $materia->codigoVerificable()
                ? "- Si el ejemplo es de programación, escriba el código en JavaScript. En «codigo» ponga el programa final completo y en «comprobacion»\n"
                  ."  una expresión de JavaScript que dé exactamente true cuando ese código es correcto (por ejemplo: sumar([1,2,3]) === 6).\n"
                  .'  La comprobación se ejecutará de verdad: no use DOM, red ni archivos.'
                : '- Esta materia no verifica código en el navegador: deje «codigo» y «comprobacion» vacíos; si hace falta código, póngalo en el paso.',
            'memoria' => $previos ? "- Ya existen estos ejemplos en la materia; no los repita:\n{$previos}" : '',
        ], $contenidos, $alcance ? ['alcance_grupo' => $alcance] : []);
    }

    /** @return array{generacion: GeneracionIa, errores: array<int, string>} */
    public function generar(User $docente, Materia $materia, string $sistema, string $usuario, array $meta, ?ConfigMotorIa $motor = null): array
    {
        @set_time_limit(config('ia.tiempo_maximo'));
        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor, [
            'plantillas' => $meta['plantillas'] ?? null, 'contenidos' => $meta['contenidos'] ?? null, 'materia_id' => $materia->id, 'tarea' => 'ejemplos',
        ]);
        $g = $this->ia->ultima;

        return ['generacion' => $g, 'errores' => $this->guardar($g, $g->respuesta)];
    }

    public function corregir(User $docente, GeneracionIa $g, ?ConfigMotorIa $motor = null, array $fallidos = []): array
    {
        $errores = $this->errores($g->borrador);
        foreach ($fallidos as $f) {
            $errores[] = "La comprobación del ejemplo {$f['numero']} no dio true al ejecutar su código".(filled($f['error'] ?? null) ? " (error: {$f['error']})" : '')
                .'. Corrija el código o la comprobación.';
        }
        if ($errores === []) {
            return ['generacion' => $g, 'errores' => []];
        }
        @set_time_limit(config('ia.tiempo_maximo'));
        $usuario = "Estos ejemplos resueltos en JSON tienen problemas. Corríjalos sin cambiar lo que está bien y devuelva SOLO el JSON completo corregido.\n\n"
            ."Problemas:\n- ".implode("\n- ", $errores)."\n\nJSON:\n".$g->borrador;
        $sistema = 'Usted corrige ejemplos prácticos resueltos en formato JSON «'.EjemplosPracticos::FORMATO.'» v1. Responda solo con JSON válido.';

        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor ?? $g->motor, [
            'plantillas' => $g->plantillas, 'contenidos' => $g->contenidos_contexto, 'materia_id' => $g->materia_id, 'tarea' => 'ejemplos-correccion',
        ]);
        $nueva = $this->ia->ultima;
        $nueva->update(['tarea' => 'ejemplos']);
        $errores = $this->guardar($nueva, $nueva->respuesta);
        $g->update(['estado' => 'descartado']);

        return ['generacion' => $nueva, 'errores' => $errores];
    }

    public function editar(GeneracionIa $g, array $datos): array
    {
        return $this->guardar($g, json_encode($datos, JSON_UNESCAPED_UNICODE));
    }

    public function aprobar(GeneracionIa $g): Material
    {
        $datos = json_decode((string) $g->borrador, true) ?? [];
        if ($e = $this->ejemplos->errores($datos)) {
            throw new InvalidArgumentException('Los ejemplos aún tienen observaciones: '.$e[0]);
        }
        $d = $this->ejemplos->normalizar($datos);
        $m = Material::query()->create(['materia_id' => $g->materia_id, 'tipo' => EjemplosPracticos::TIPO, 'titulo' => $d['titulo'], 'contenido' => $d, 'generacion_ia_id' => $g->id]);
        $g->update(['estado' => 'aprobado', 'resultado_type' => $m->getMorphClass(), 'resultado_id' => $m->id]);

        return $m;
    }

    /** @return array<int, string> */
    public function errores(?string $json): array
    {
        $d = json_decode((string) $json, true);

        return is_array($d) ? $this->ejemplos->errores($d) : ['El borrador no es JSON válido: '.json_last_error_msg().'.'];
    }

    private function guardar(GeneracionIa $g, string $texto): array
    {
        try {
            $d = ExtractorJson::extraer($texto);
        } catch (InvalidArgumentException $e) {
            $g->update(['borrador' => $texto]);

            return [$e->getMessage()];
        }
        $d = ['formato' => EjemplosPracticos::FORMATO, 'version' => 1] + $this->ejemplos->normalizar($d);
        $g->update(['borrador' => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return $this->ejemplos->errores($d);
    }
}
