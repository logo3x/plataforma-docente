<?php

namespace App\Evaluacion;

use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\Artefacto;
use App\Models\ConfigMotorIa;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\User;
use InvalidArgumentException;

/**
 * Genera con IA la versión B o el segundo banco (2A, 2B) de un examen ya aprobado (5.4.2): los
 * mismos temas, tipos, pesos y dificultad, con otros datos y contextos. Queda como BORRADOR que el
 * docente revisa ítem por ítem junto al original antes de agregarlo al examen (1.2).
 */
final class GeneradorVariante
{
    public const TAREA = 'variante';

    public function __construct(
        private readonly ConstructorPrompt $prompts,
        private readonly GestorIA $ia,
        private readonly ImportadorBanco $importador,
    ) {}

    /** Versiones que aún se le pueden agregar al examen. */
    public static function posibles(Artefacto $a): array
    {
        $tiene = $a->versiones();
        $posibles = array_values(array_diff(['B', '2A'], $tiene));
        if (in_array('2A', $tiene, true) && ! in_array('2B', $tiene, true) && in_array('B', $tiene, true)) {
            $posibles[] = '2B';
        }

        return $posibles;
    }

    /** @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool} */
    public function preparar(User $docente, Artefacto $a, Dictado $dictado, string $version, ?string $indicaciones): array
    {
        if (! in_array($version, self::posibles($a), true)) {
            throw new InvalidArgumentException("No se puede generar la versión {$version} de este examen.");
        }
        $origen = ImportadorBanco::origenDe($a, $version);
        $items = $a->items()->with('pregunta')->where('banco', $origen)->orderBy('orden')->get()->map(fn ($ap) => ImportadorBanco::aBanco($ap))->values();

        return $this->prompts->construir($docente, $a->materia, $dictado, self::TAREA, [
            'version' => $version,
            'origen' => $origen,
            'proposito' => str_starts_with($version, '2')
                ? 'un segundo banco para aplicar en otra jornada: conocer los enunciados de la primera no debe servir'
                : 'una segunda versión para el mismo salón: dos estudiantes vecinos no deben poder copiarse',
            'cantidad' => (string) $items->count(),
            'indicaciones' => filled($indicaciones) ? trim($indicaciones) : '—',
            'items' => json_encode($items->map(fn ($it, $i) => ['n' => $i + 1] + $it)->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], collect());
    }

    /** @return array{generacion: GeneracionIa, errores: array<int, string>} */
    public function generar(User $docente, Artefacto $a, string $version, string $sistema, string $usuario, array $meta, ?ConfigMotorIa $motor = null): array
    {
        @set_time_limit(config('ia.tiempo_maximo'));
        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor, [
            'plantillas' => $meta['plantillas'] ?? null, 'materia_id' => $a->materia_id, 'tarea' => self::TAREA,
        ]);
        $g = $this->ia->ultima;

        return ['generacion' => $g, 'errores' => $this->guardar($g, $g->respuesta, $a, $version)];
    }

    public function corregir(User $docente, GeneracionIa $g, ?ConfigMotorIa $motor = null): array
    {
        [$a, $version] = $this->destino($g);
        $errores = $this->errores($g);
        if ($errores === []) {
            return ['generacion' => $g, 'errores' => []];
        }
        @set_time_limit(config('ia.tiempo_maximo'));
        $usuario = "Esta versión de examen en JSON tiene errores. Corríjalos sin cambiar lo que está bien y devuelva SOLO el JSON completo corregido.\n\n"
            ."Errores:\n- ".implode("\n- ", $errores)."\n\nJSON:\n".$g->borrador;
        $this->ia->generar($docente, new SolicitudIA('Usted corrige ítems de examen en formato JSON «plataforma-banco». Responda solo con JSON válido.', $usuario, soloJson: true, maxTokens: 16000),
            $motor ?? $g->motor, ['plantillas' => $g->plantillas, 'materia_id' => $g->materia_id, 'tarea' => self::TAREA.'-correccion']);
        $nueva = $this->ia->ultima;
        $nueva->update(['tarea' => self::TAREA]);
        $errores = $this->guardar($nueva, $nueva->respuesta, $a, $version);
        $g->update(['estado' => 'descartado']);

        return ['generacion' => $nueva, 'errores' => $errores];
    }

    public function editar(GeneracionIa $g, string $json): array
    {
        [$a, $version] = $this->destino($g);

        return $this->guardar($g, $json, $a, $version);
    }

    public function aprobar(GeneracionIa $g): int
    {
        [$a, $version] = $this->destino($g);
        $n = $this->importador->agregarVersion($a, json_decode((string) $g->borrador, true)['items'] ?? [], $version);
        $g->update(['estado' => 'aprobado', 'resultado_type' => $a->getMorphClass(), 'resultado_id' => $a->id]);

        return $n;
    }

    /** @return array<int, string> */
    public function errores(GeneracionIa $g): array
    {
        [$a, $version] = $this->destino($g);
        $d = json_decode((string) $g->borrador, true);

        return is_array($d) ? $this->importador->validarVersion($a, (array) ($d['items'] ?? []), $version) : ['El borrador no es JSON válido: '.json_last_error_msg().'.'];
    }

    /**
     * Lo que conviene revisar aunque no impida agregarla: un ítem que repite las opciones y la
     * respuesta del original, o un enunciado casi igual, no protege contra la copia.
     *
     * @param  array<int, array>  $originales
     * @param  array<int, array>  $nuevos
     * @return array<int, string>
     */
    public static function advertencias(array $originales, array $nuevos, string $origen): array
    {
        $a = [];
        $normal = fn ($t) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $t)));
        foreach ($originales as $i => $o) {
            $n = $nuevos[$i] ?? null;
            if (! is_array($n) || ($n['tipo'] ?? null) !== ($o['tipo'] ?? null)) {
                continue;
            }
            $num = $i + 1;
            // Todo lo que ve el estudiante: un «Complete.» igual no dice nada si la plantilla cambió
            $visible = fn ($x) => $normal(implode(' ', [$x['enunciado'] ?? '', $x['plantilla'] ?? '', $x['plantilla_codigo'] ?? '',
                json_encode([$x['opciones'] ?? null, $x['casos_visibles'] ?? null, $x['pasos'] ?? null, $x['izquierda'] ?? null, $x['derecha'] ?? null], JSON_UNESCAPED_UNICODE)]));
            // Casi igual: entre los dos, a lo sumo una palabra o un número distinto (cambiar los datos sí cuenta)
            $palabras = fn ($t) => array_unique(preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
            [$po, $pn] = [$palabras($visible($o)), $palabras($visible($n))];
            if (count(array_diff($po, $pn)) + count(array_diff($pn, $po)) <= 1) {
                $a[] = "Ítem {$num}: el enunciado es casi igual al de la versión {$origen}.";
            }
            $clave = fn ($x) => json_encode([$x['opciones'] ?? null, $x['correcta'] ?? null, $x['correctas'] ?? null, $x['pares'] ?? null, $x['orden'] ?? null, $x['huecos'] ?? null], JSON_UNESCAPED_UNICODE);
            if (in_array($o['tipo'], ['unica', 'multiple', 'emparejar', 'ordenar', 'completar'], true) && $clave($o) === $clave($n)) {
                $a[] = "Ítem {$num}: tiene las mismas opciones y la misma respuesta que en la versión {$origen}; quien la vio acierta sin pensar.";
            }
        }

        return $a;
    }

    /** @return array{0: Artefacto, 1: string} examen y versión que se está generando */
    public function destino(GeneracionIa $g): array
    {
        $d = json_decode((string) $g->borrador, true) ?? [];
        $a = Artefacto::query()->where('materia_id', $g->materia_id)->findOrFail((int) ($d['artefacto_id'] ?? 0));

        return [$a, (string) ($d['version_examen'] ?? '')];
    }

    private function guardar(GeneracionIa $g, string $texto, Artefacto $a, string $version): array
    {
        try {
            $d = ExtractorJson::extraer($texto);
        } catch (InvalidArgumentException $e) {
            // Aun sin JSON válido el borrador recuerda a qué examen y versión pertenece
            $g->update(['borrador' => json_encode(['artefacto_id' => $a->id, 'version_examen' => $version, 'items' => [], 'texto' => $texto], JSON_UNESCAPED_UNICODE)]);

            return [$e->getMessage()];
        }
        // Lo que la plataforma ya sabe no se deja a la IA: el examen, la versión de cada ítem
        $items = array_values(array_map(fn ($it) => is_array($it) ? ['version' => $version] + array_diff_key($it, ['version' => 1, 'n' => 1]) : $it, (array) ($d['items'] ?? [])));
        $g->update(['borrador' => json_encode(['artefacto_id' => $a->id, 'version_examen' => $version, 'items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return $this->importador->validarVersion($a, $items, $version);
    }
}
