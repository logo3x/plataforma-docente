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
 * Flujo común de los generadores de material (7.4): prompt de tres capas con los temas, el alcance
 * del grupo, el material y la memoria de lo ya creado → BORRADOR → corrección (a mano o con la IA)
 * → aprobación del docente. Nada se guarda como material sin su aprobación (1.2).
 */
class GeneradorMaterial
{
    public function __construct(
        protected readonly ConstructorPrompt $prompts,
        protected readonly GestorIA $ia,
        public readonly TipoMaterial $tipo,
    ) {}

    /** @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool} */
    public function preparar(User $docente, Materia $materia, Dictado $dictado, array $op): array
    {
        $op += ['temas' => [], 'tema' => null, 'grupo' => null, 'contenidos' => []];
        $temas = TemarioItem::query()->where('materia_id', $materia->id)->where('tipo', 'tema')->whereIn('id', $op['temas'] ?: [])->orderBy('orden')->get();
        $tema = trim($temas->map(fn ($t) => $t->titulo.($t->descripcion ? " ({$t->descripcion})" : ''))->join('; ')
            .($temas->isNotEmpty() && filled($op['tema']) ? '. ' : '').($op['tema'] ?? ''));
        if ($tema === '') {
            throw new InvalidArgumentException('Elija temas del temario o escriba el tema.');
        }

        $grupo = filled($op['grupo']) ? $dictado->grupos()->where('nombre', $op['grupo'])->first() : null;
        $alcance = app(ServicioTemario::class)->alcance($materia, $grupo);
        $contenidos = Contenido::query()->where('materia_id', $materia->id)->whereIn('id', $op['contenidos'] ?: [])->get();

        // Memoria (7.3): no repetir lo que ya existe en la materia
        $previos = Material::query()->where('materia_id', $materia->id)->where('tipo', $this->tipo->tipo())->latest()->limit(10)->get()
            ->flatMap(fn (Material $m) => collect($m->contenido[$this->tipo->coleccion()] ?? [])->map(fn ($e) => $this->tipo->nombreElemento($e)))
            ->filter()->take(40)->map(fn ($t) => "- {$t}")->join("\n");

        return $this->prompts->construir($docente, $materia, $dictado, $this->tipo->tipo(), [
            'tema' => $tema,
            'grupo' => $op['grupo'] ?: 'todos los grupos del periodo',
            'memoria' => $previos ? "- Ya existe esto en la materia; no lo repita:\n{$previos}" : '',
        ] + $this->tipo->variables($materia, $op), $contenidos, $alcance ? ['alcance_grupo' => $alcance] : []);
    }

    /** @return array{generacion: GeneracionIa, errores: array<int, string>} */
    public function generar(User $docente, Materia $materia, string $sistema, string $usuario, array $meta, ?ConfigMotorIa $motor = null): array
    {
        @set_time_limit(config('ia.tiempo_maximo'));
        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor, [
            'plantillas' => $meta['plantillas'] ?? null, 'contenidos' => $meta['contenidos'] ?? null, 'materia_id' => $materia->id, 'tarea' => $this->tipo->tipo(),
        ]);
        $g = $this->ia->ultima;

        return ['generacion' => $g, 'errores' => $this->guardar($g, $g->respuesta)];
    }

    /**
     * Pide a la IA que corrija los errores de validación y los problemas que detectó la plataforma
     * (por ejemplo, el código que no pasó su comprobación en el navegador).
     *
     * @param  array<int, string>  $problemas
     */
    public function corregir(User $docente, GeneracionIa $g, ?ConfigMotorIa $motor = null, array $problemas = []): array
    {
        $errores = [...$this->errores($g->borrador), ...$problemas];
        if ($errores === []) {
            return ['generacion' => $g, 'errores' => []];
        }
        @set_time_limit(config('ia.tiempo_maximo'));
        $usuario = "Este JSON tiene problemas. Corríjalos sin cambiar lo que está bien y devuelva SOLO el JSON completo corregido.\n\n"
            ."Problemas:\n- ".implode("\n- ", $errores)."\n\nJSON:\n".$g->borrador;
        $sistema = 'Usted corrige material de clase en formato JSON «'.$this->tipo->formato().'» v1. Responda solo con JSON válido.';

        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor ?? $g->motor, [
            'plantillas' => $g->plantillas, 'contenidos' => $g->contenidos_contexto, 'materia_id' => $g->materia_id, 'tarea' => $this->tipo->tipo().'-correccion',
        ]);
        $nueva = $this->ia->ultima;
        $nueva->update(['tarea' => $this->tipo->tipo()]);
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
        if ($e = $this->tipo->errores($datos)) {
            throw new InvalidArgumentException('El borrador aún tiene observaciones: '.$e[0]);
        }
        $d = $this->tipo->normalizar($datos);
        $m = Material::query()->create(['materia_id' => $g->materia_id, 'tipo' => $this->tipo->tipo(), 'titulo' => $d['titulo'], 'contenido' => $d, 'generacion_ia_id' => $g->id]);
        $g->update(['estado' => 'aprobado', 'resultado_type' => $m->getMorphClass(), 'resultado_id' => $m->id]);

        return $m;
    }

    /** @return array<int, string> */
    public function errores(?string $json): array
    {
        $d = json_decode((string) $json, true);

        return is_array($d) ? $this->tipo->errores($d) : ['El borrador no es JSON válido: '.json_last_error_msg().'.'];
    }

    private function guardar(GeneracionIa $g, string $texto): array
    {
        try {
            $d = ExtractorJson::extraer($texto);
        } catch (InvalidArgumentException $e) {
            $g->update(['borrador' => $texto]);

            return [$e->getMessage()];
        }
        // Lo que la plataforma ya sabe no se deja a la IA
        $d = ['formato' => $this->tipo->formato(), 'version' => 1] + $this->tipo->normalizar($d);
        $g->update(['borrador' => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return $this->tipo->errores($d);
    }
}
