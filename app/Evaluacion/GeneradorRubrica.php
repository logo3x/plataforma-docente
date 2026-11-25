<?php

namespace App\Evaluacion;

use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Pregunta;
use App\Models\Rubrica;
use App\Models\TemarioItem;
use App\Models\User;
use App\Temario\ServicioTemario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generador «Rúbrica» del catálogo (7.4): criterios y niveles para una pregunta abierta o una
 * actividad. Igual que el banco, la IA entrega un BORRADOR que el docente revisa, edita y aprueba;
 * la rúbrica a mano siempre está disponible (1.2).
 */
final class GeneradorRubrica
{
    public function __construct(
        private readonly ConstructorPrompt $prompts,
        private readonly GestorIA $ia,
        private readonly Rubricas $rubricas,
    ) {}

    /**
     * @param  array{proposito:?string, pregunta_id:?int, tema:?string, temas:array<int,int>, grupo:?string, criterios:int, niveles:?string, contenidos:array<int,int>}  $op
     * @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool, pregunta_id: ?int}
     */
    public function preparar(User $docente, Materia $materia, Dictado $dictado, array $op): array
    {
        $op += ['proposito' => null, 'pregunta_id' => null, 'tema' => null, 'temas' => [], 'grupo' => null, 'niveles' => null, 'contenidos' => []];

        $pregunta = $op['pregunta_id']
            ? Pregunta::query()->where('materia_id', $materia->id)->where('tipo', 'abierta')->findOrFail($op['pregunta_id'])
            : null;
        $actividad = $pregunta ? $this->describirPregunta($pregunta) : trim((string) $op['proposito']);
        if ($actividad === '') {
            throw new InvalidArgumentException('Elija una pregunta abierta del banco o describa la actividad que se va a calificar.');
        }

        $temas = TemarioItem::query()->where('materia_id', $materia->id)->where('tipo', 'tema')->whereIn('id', $op['temas'] ?: [])->orderBy('orden')->pluck('titulo');
        if ($pregunta?->tema) {
            $temas->prepend($pregunta->tema->titulo);
        }
        $tema = trim($temas->unique()->join('; ').($temas->isNotEmpty() && filled($op['tema']) ? '. ' : '').($op['tema'] ?? '')) ?: '—';

        $niveles = collect(preg_split('/\s*[,;\n]\s*/', (string) $op['niveles']))->filter()->values();
        $niveles = $niveles->count() >= 2 ? $niveles : collect(Rubricas::NIVELES_POR_DEFECTO);

        $grupo = filled($op['grupo']) ? $dictado->grupos()->where('nombre', $op['grupo'])->first() : null;
        $alcance = app(ServicioTemario::class)->alcance($materia, $grupo);
        $contenidos = Contenido::query()->where('materia_id', $materia->id)->whereIn('id', $op['contenidos'] ?: [])->get();

        // Memoria de lo ya creado (7.3, regla 2 del catálogo): coherencia con las rúbricas de la materia
        $previas = $materia->rubricas()->latest()->limit(15)->get()
            ->map(fn (Rubrica $r) => "- «{$r->titulo}»: ".collect($r->criterios)->pluck('nombre')->join(', '))->join("\n");

        $prompt = $this->prompts->construir($docente, $materia, $dictado, 'rubrica', [
            'actividad' => $actividad,
            'tema' => $tema,
            'grupo' => $op['grupo'] ?: 'todos los grupos del periodo',
            'criterios' => (string) max(1, min(Rubricas::MAX_CRITERIOS, (int) $op['criterios'])),
            'niveles' => $niveles->join(', '),
            'cantidad_niveles' => (string) $niveles->count(),
            'memoria' => $previas ? "- Rúbricas que ya existen en la materia; mantenga un estilo coherente y no repita criterios que no apliquen:\n{$previas}" : '',
        ], $contenidos, $alcance ? ['alcance_grupo' => $alcance] : []);

        return $prompt + ['pregunta_id' => $pregunta?->id];
    }

    /** @return array{generacion: GeneracionIa, errores: array<int, string>} */
    public function generar(User $docente, Materia $materia, string $sistema, string $usuario, array $meta, ?ConfigMotorIa $motor = null): array
    {
        @set_time_limit(config('ia.tiempo_maximo'));
        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 8000), $motor, [
            'plantillas' => $meta['plantillas'] ?? null,
            'contenidos' => $meta['contenidos'] ?? null,
            'materia_id' => $materia->id,
            'tarea' => 'rubrica',
        ]);
        $g = $this->ia->ultima;

        return ['generacion' => $g, 'errores' => $this->guardar($g, $g->respuesta, $meta['pregunta_id'] ?? null)];
    }

    /** Pide a la IA que corrija su borrador con las observaciones de validación. */
    public function corregir(User $docente, GeneracionIa $g, ?ConfigMotorIa $motor = null): array
    {
        $errores = $this->errores($g->borrador);
        if ($errores === []) {
            return ['generacion' => $g, 'errores' => []];
        }
        @set_time_limit(config('ia.tiempo_maximo'));
        $usuario = "Esta rúbrica JSON tiene errores. Corríjalos sin cambiar lo que está bien y devuelva SOLO el JSON completo corregido.\n\n"
            ."Errores:\n- ".implode("\n- ", $errores)."\n\nJSON:\n".$g->borrador;
        $sistema = 'Usted corrige rúbricas en formato JSON «'.Rubricas::FORMATO.'» v1: los pesos de los criterios suman 100 y el nivel más alto de cada criterio otorga 100. Responda solo con JSON válido.';

        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 8000), $motor ?? $g->motor, [
            'plantillas' => $g->plantillas, 'contenidos' => $g->contenidos_contexto, 'materia_id' => $g->materia_id, 'tarea' => 'rubrica-correccion',
        ]);
        $nueva = $this->ia->ultima;
        $nueva->update(['tarea' => 'rubrica']);
        $errores = $this->guardar($nueva, $nueva->respuesta, $this->preguntaDe($g));
        $g->update(['estado' => 'descartado']);

        return ['generacion' => $nueva, 'errores' => $errores];
    }

    /** Edición del docente en el editor visual; la vía manual nunca se cierra. */
    public function editar(GeneracionIa $g, array $rubrica, ?int $preguntaId): array
    {
        return $this->guardar($g, json_encode($rubrica, JSON_UNESCAPED_UNICODE), $preguntaId);
    }

    public function aprobar(GeneracionIa $g): Rubrica
    {
        $datos = json_decode((string) $g->borrador, true) ?? [];
        if ($e = $this->rubricas->validar($datos)) {
            throw new InvalidArgumentException('La rúbrica aún tiene observaciones: '.$e[0]);
        }
        $r = $this->rubricas->normalizar($datos);

        return DB::transaction(function () use ($g, $r) {
            $rubrica = Rubrica::query()->create($r + ['materia_id' => $g->materia_id, 'generacion_ia_id' => $g->id]);
            if ($id = $this->preguntaDe($g)) {
                Pregunta::query()->where('materia_id', $g->materia_id)->whereKey($id)->update(['rubrica_id' => $rubrica->id]);
            }
            $g->update(['estado' => 'aprobado', 'resultado_type' => $rubrica->getMorphClass(), 'resultado_id' => $rubrica->id]);

            return $rubrica;
        });
    }

    /** @return array<int, string> */
    public function errores(?string $json): array
    {
        $r = json_decode((string) $json, true);

        return is_array($r) ? $this->rubricas->validar($r) : ['El borrador no es JSON válido: '.json_last_error_msg().'.'];
    }

    public function preguntaDe(GeneracionIa $g): ?int
    {
        $id = json_decode((string) $g->borrador, true)['pregunta_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function guardar(GeneracionIa $g, string $texto, ?int $preguntaId): array
    {
        try {
            $r = ExtractorJson::extraer($texto);
        } catch (InvalidArgumentException $e) {
            $g->update(['borrador' => $texto]);

            return [$e->getMessage()];
        }
        // Lo que la plataforma ya sabe no se deja a la IA
        $r = ['formato' => Rubricas::FORMATO, 'version' => 1] + $this->rubricas->normalizar($r) + ['pregunta_id' => $preguntaId];
        $g->update(['borrador' => json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return $this->rubricas->validar($r);
    }

    private function describirPregunta(Pregunta $p): string
    {
        $clave = $p->clave ?? [];

        return 'la respuesta abierta a esta pregunta de evaluación'
            ."\nEnunciado: ".Str::limit($p->enunciado['texto'] ?? '', 2000)
            .(filled($clave['referencia'] ?? null) ? "\nRespuesta de referencia del docente: ".Str::limit($clave['referencia'], 2000) : '')
            .(filled($clave['rubrica'] ?? null) ? "\nCriterios que el docente ya había anotado: ".Str::limit($clave['rubrica'], 1500) : '');
    }
}
