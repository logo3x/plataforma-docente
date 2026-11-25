<?php

namespace App\Planificacion;

use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\TemarioItem;
use App\Models\UnidadDidactica;
use App\Models\User;
use App\Temario\ServicioTemario;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generador «Unidad didáctica» del catálogo (7.4): la secuencia de sesiones de una unidad del
 * temario, a partir del plan curricular (7.1). La IA entrega un BORRADOR que el docente revisa,
 * edita y aprueba; la unidad a mano siempre está disponible (1.2).
 */
final class GeneradorUnidad
{
    public function __construct(
        private readonly ConstructorPrompt $prompts,
        private readonly GestorIA $ia,
        private readonly UnidadesDidacticas $unidades,
    ) {}

    /**
     * @param  array{unidad_id:?int, tema:?string, sesiones:int, duracion:int, grupo:?string, enfasis:?string, contenidos:array<int,int>}  $op
     * @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool, unidad_id: ?int}
     */
    public function preparar(User $docente, Materia $materia, Dictado $dictado, array $op): array
    {
        $op += ['unidad_id' => null, 'tema' => null, 'grupo' => null, 'enfasis' => null, 'contenidos' => []];
        $unidad = $op['unidad_id'] ? $materia->temario()->whereNull('parent_id')->findOrFail($op['unidad_id']) : null;
        if ($unidad === null && blank($op['tema'])) {
            throw new InvalidArgumentException('Elija una unidad del temario o escriba el tema de la unidad.');
        }

        $hijos = $unidad ? $unidad->hijos()->get() : collect();
        $temas = $hijos->where('tipo', 'tema')->map(fn (TemarioItem $t) => '- '.$t->titulo
            .($t->descripcion ? ": {$t->descripcion}" : '')
            .($t->sugerencia ? "\n  Sugerencia aceptada para organizar la clase: ".Str::limit($t->sugerencia, 400) : ''))->join("\n");
        $resultados = $hijos->where('tipo', 'resultado')->map(fn ($r) => "- {$r->titulo}")->join("\n");

        $grupo = filled($op['grupo']) ? $dictado->grupos()->where('nombre', $op['grupo'])->first() : null;
        $alcance = app(ServicioTemario::class)->alcance($materia, $grupo);
        $contenidos = Contenido::query()->where('materia_id', $materia->id)->whereIn('id', $op['contenidos'] ?: [])->get();

        // Memoria (7.3): lo que ya se planeó para la materia y las actividades evaluables existentes
        $previas = $materia->unidadesDidacticas()->latest()->limit(8)->get()
            ->map(fn (UnidadDidactica $u) => "- «{$u->titulo}»: ".collect($u->contenido['sesiones'] ?? [])->pluck('titulo')->join('; '))->join("\n");
        $actividades = $materia->artefactos()->latest()->limit(15)->pluck('titulo')->map(fn ($t) => "- {$t}")->join("\n");

        $prompt = $this->prompts->construir($docente, $materia, $dictado, 'unidad', [
            'unidad' => $unidad ? $unidad->titulo.($unidad->descripcion ? " — {$unidad->descripcion}" : '') : trim((string) $op['tema']),
            'temas' => $temas ?: trim((string) $op['tema']),
            'resultados' => $resultados ?: '(el temario no registra resultados de aprendizaje para esta unidad; proponga los que correspondan)',
            'sesiones' => (string) max(1, min(UnidadesDidacticas::MAX_SESIONES, (int) $op['sesiones'])),
            'duracion' => (string) max(10, min(600, (int) $op['duracion'])),
            'grupo' => $op['grupo'] ?: 'todos los grupos del periodo',
            'enfasis' => filled($op['enfasis']) ? trim($op['enfasis']) : '—',
            'memoria' => trim(($previas ? "- Unidades ya planeadas en la materia; no repita sus actividades:\n{$previas}" : '')
                .($actividades ? "\n- Actividades evaluables que ya existen y puede referenciar en la evaluación:\n{$actividades}" : '')),
        ], $contenidos, $alcance ? ['alcance_grupo' => $alcance] : []);

        return $prompt + ['unidad_id' => $unidad?->id];
    }

    /** @return array{generacion: GeneracionIa, errores: array<int, string>} */
    public function generar(User $docente, Materia $materia, string $sistema, string $usuario, array $meta, ?ConfigMotorIa $motor = null): array
    {
        @set_time_limit(config('ia.tiempo_maximo'));
        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor, [
            'plantillas' => $meta['plantillas'] ?? null, 'contenidos' => $meta['contenidos'] ?? null, 'materia_id' => $materia->id, 'tarea' => 'unidad',
        ]);
        $g = $this->ia->ultima;

        return ['generacion' => $g, 'errores' => $this->guardar($g, $g->respuesta, $meta['unidad_id'] ?? null)];
    }

    public function corregir(User $docente, GeneracionIa $g, ?ConfigMotorIa $motor = null): array
    {
        $errores = $this->errores($g->borrador);
        if ($errores === []) {
            return ['generacion' => $g, 'errores' => []];
        }
        @set_time_limit(config('ia.tiempo_maximo'));
        $usuario = "Esta unidad didáctica en JSON tiene errores. Corríjalos sin cambiar lo que está bien y devuelva SOLO el JSON completo corregido.\n\n"
            ."Errores:\n- ".implode("\n- ", $errores)."\n\nJSON:\n".$g->borrador;
        $sistema = 'Usted corrige unidades didácticas en formato JSON «'.UnidadesDidacticas::FORMATO.'» v1. Responda solo con JSON válido.';

        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor ?? $g->motor, [
            'plantillas' => $g->plantillas, 'contenidos' => $g->contenidos_contexto, 'materia_id' => $g->materia_id, 'tarea' => 'unidad-correccion',
        ]);
        $nueva = $this->ia->ultima;
        $nueva->update(['tarea' => 'unidad']);
        $errores = $this->guardar($nueva, $nueva->respuesta, $this->unidadDe($g));
        $g->update(['estado' => 'descartado']);

        return ['generacion' => $nueva, 'errores' => $errores];
    }

    public function editar(GeneracionIa $g, array $datos, ?int $unidadId): array
    {
        return $this->guardar($g, json_encode($datos, JSON_UNESCAPED_UNICODE), $unidadId);
    }

    public function aprobar(GeneracionIa $g): UnidadDidactica
    {
        $datos = json_decode((string) $g->borrador, true) ?? [];
        if ($e = $this->unidades->errores($datos)) {
            throw new InvalidArgumentException('La unidad aún tiene observaciones: '.$e[0]);
        }
        $u = $this->unidades->normalizar($datos);
        $unidadId = $this->unidadDe($g);
        $unidadId = $unidadId && TemarioItem::query()->where('materia_id', $g->materia_id)->whereKey($unidadId)->exists() ? $unidadId : null;

        $creada = UnidadDidactica::query()->create([
            'materia_id' => $g->materia_id, 'temario_item_id' => $unidadId, 'titulo' => $u['titulo'], 'contenido' => $u, 'generacion_ia_id' => $g->id,
        ]);
        $g->update(['estado' => 'aprobado', 'resultado_type' => $creada->getMorphClass(), 'resultado_id' => $creada->id]);

        return $creada;
    }

    /** @return array<int, string> */
    public function errores(?string $json): array
    {
        $u = json_decode((string) $json, true);

        return is_array($u) ? $this->unidades->errores($u) : ['El borrador no es JSON válido: '.json_last_error_msg().'.'];
    }

    public function unidadDe(GeneracionIa $g): ?int
    {
        $id = json_decode((string) $g->borrador, true)['unidad_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function guardar(GeneracionIa $g, string $texto, ?int $unidadId): array
    {
        try {
            $u = ExtractorJson::extraer($texto);
        } catch (InvalidArgumentException $e) {
            $g->update(['borrador' => $texto]);

            return [$e->getMessage()];
        }
        // Lo que la plataforma ya sabe no se deja a la IA
        $u = ['formato' => UnidadesDidacticas::FORMATO, 'version' => 1] + $this->unidades->normalizar($u) + ['unidad_id' => $unidadId];
        $g->update(['borrador' => json_encode($u, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return $this->unidades->errores($u);
    }
}
