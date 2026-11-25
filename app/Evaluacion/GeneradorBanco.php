<?php

namespace App\Evaluacion;

use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\Artefacto;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\Dictado;
use App\Models\GeneracionIa;
use App\Models\Materia;
use App\Models\Pregunta;
use App\Models\Reclamo;
use App\Models\TemarioItem;
use App\Models\User;
use App\Temario\ServicioTemario;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generación del banco con IA (7.4, generadores «Cuestionario» y «Parcial»). La IA produce un
 * BORRADOR: se valida con el mismo importador del banco y el docente lo revisa, corrige o descarta.
 * Nada se aplica solo (principio 1.2).
 */
final class GeneradorBanco
{
    public const TIPOS = ['unica' => 'Selección única', 'vf' => 'Verdadero o falso', 'multiple' => 'Selección múltiple',
        'emparejar' => 'Emparejar', 'ordenar' => 'Ordenar pasos', 'completar' => 'Completar código', 'abierta' => 'Respuesta abierta', 'reto' => 'Reto de código'];

    public function __construct(
        private readonly ConstructorPrompt $prompts,
        private readonly GestorIA $ia,
        private readonly ImportadorBanco $importador,
    ) {}

    /**
     * @param  array{tipo:string, tema:string, grupo:?string, valor:string, cantidades:array<string,int>, obligatorio:?string, excluido:?string, contenidos:array<int,int>}  $op
     * @return array{sistema: string, usuario: string, plantillas: array, contenidos: array, recortado: bool, sigla: string}
     */
    public function preparar(User $docente, Materia $materia, Dictado $dictado, array $op): array
    {
        // Los campos opcionales del formulario pueden no venir
        $op += ['tema' => '', 'grupo' => null, 'obligatorio' => null, 'excluido' => null, 'contenidos' => [], 'temas' => []];
        $cantidades = array_filter($op['cantidades'], fn ($n) => (int) $n > 0);
        if ($cantidades === []) {
            throw new InvalidArgumentException('Indique cuántos ítems de cada tipo quiere.');
        }
        $sigla = $this->sigla($materia, $dictado, $op['tipo']);
        $contenidos = Contenido::query()->where('materia_id', $materia->id)->whereIn('id', $op['contenidos'] ?? [])->get();

        // Memoria de lo ya creado (7.3): no repetir preguntas existentes de la materia
        $previas = Pregunta::query()->where('materia_id', $materia->id)->latest()->limit(40)->get()
            ->map(fn ($p) => '- '.Str::limit($p->enunciado['texto'] ?? '', 140))->join("\n");

        // 6.5.6: los reclamos que prosperaron son errores que la IA no debe repetir
        $errores = Reclamo::query()->where('estado', 'aceptado')->whereNotNull('artefacto_pregunta_id')
            ->whereHas('actividad.componente.division.dictado', fn ($q) => $q->where('materia_id', $materia->id))
            ->with('item.pregunta')->latest()->limit(20)->get()
            ->map(fn ($r) => '- «'.Str::limit($r->item?->pregunta->enunciado['texto'] ?? '', 120).'»: '.Str::limit($r->resolucion, 160))
            ->join("\n");

        // Temas elegidos del temario (7.1) y alcance real del grupo según la lista de chequeo (7.1.5)
        $temas = TemarioItem::query()->where('materia_id', $materia->id)->where('tipo', 'tema')->whereIn('id', $op['temas'] ?? [])->orderBy('orden')->pluck('titulo');
        $tema = trim($temas->join('; ').($temas->isNotEmpty() && filled($op['tema']) ? '. ' : '').($op['tema'] ?? ''));
        if ($tema === '') {
            throw new InvalidArgumentException('Escriba el tema o elija temas del temario.');
        }
        $grupo = filled($op['grupo'] ?? null) ? $dictado->grupos()->where('nombre', $op['grupo'])->first() : null;
        $alcance = app(ServicioTemario::class)->alcance($materia, $grupo);

        $prompt = $this->prompts->construir($docente, $materia, $dictado, 'banco', [
            'tipo' => $op['tipo'],
            'tema' => $tema,
            'grupo' => $op['grupo'] ?: 'todos los grupos del periodo',
            'valor' => $op['valor'],
            'cantidades' => collect($cantidades)->map(fn ($n, $t) => "{$n} de tipo «{$t}» (".self::TIPOS[$t].')')->join('; '),
            'obligatorio' => $op['obligatorio'] ?: '—',
            'excluido' => $op['excluido'] ?: '—',
            'sigla' => $sigla,
            'no_repetir' => trim(($previas ? "- No repita estas preguntas que ya existen en la materia:\n{$previas}" : '')
                .($errores ? "\n- Estos ítems tuvieron reclamos que prosperaron; no repita el error:\n{$errores}" : '')),
        ], $contenidos, $alcance ? ['alcance_grupo' => $alcance] : []);

        return $prompt + ['sigla' => $sigla];
    }

    /**
     * Llama al motor y guarda el borrador. El prompt que llega puede haber sido editado por el docente (8.4.3).
     *
     * @return array{generacion: GeneracionIa, errores: array<int, string>}
     */
    public function generar(User $docente, Materia $materia, string $sistema, string $usuario, array $meta, ?ConfigMotorIa $motor = null): array
    {
        @set_time_limit(config('ia.tiempo_maximo'));

        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor, [
            'plantillas' => $meta['plantillas'] ?? null,
            'contenidos' => $meta['contenidos'] ?? null,
            'materia_id' => $materia->id,
            'tarea' => 'banco',
        ]);
        $generacion = $this->ia->ultima;

        return ['generacion' => $generacion, 'errores' => $this->actualizarBorrador($generacion, $generacion->respuesta, $meta)];
    }

    /** Pide a la IA que corrija su borrador con los errores de validación. */
    public function corregir(User $docente, GeneracionIa $g, ?ConfigMotorIa $motor = null): array
    {
        $errores = $this->errores($g->borrador);
        if ($errores === []) {
            return ['generacion' => $g, 'errores' => []];
        }
        @set_time_limit(config('ia.tiempo_maximo'));

        $usuario = "Este banco JSON tiene errores de validación. Corríjalos y devuelva SOLO el JSON completo corregido.\n\n"
            ."Errores:\n- ".implode("\n- ", $errores)."\n\nJSON:\n".$g->borrador;
        $sistema = 'Usted corrige bancos de preguntas en formato JSON «plataforma-banco» v1. Responda solo con JSON válido.';

        $this->ia->generar($docente, new SolicitudIA($sistema, $usuario, soloJson: true, maxTokens: 16000), $motor ?? $g->motor, [
            'plantillas' => $g->plantillas, 'contenidos' => $g->contenidos_contexto, 'materia_id' => $g->materia_id, 'tarea' => 'banco-correccion',
        ]);
        $nueva = $this->ia->ultima;
        $sigla = json_decode((string) $g->borrador, true)['sigla'] ?? null;
        $errores = $this->actualizarBorrador($nueva, $nueva->respuesta, ['sigla' => $sigla]);
        $g->update(['estado' => 'descartado']);

        return ['generacion' => $nueva, 'errores' => $errores];
    }

    /** Guarda la edición manual del docente: la vía manual nunca se cierra (1.2). */
    public function editar(GeneracionIa $g, string $json): array
    {
        return $this->actualizarBorrador($g, $json, []);
    }

    /** Reparte los pesos proporcionalmente para que sumen el valor (ayuda determinista, no IA). */
    public function reescalar(GeneracionIa $g): array
    {
        $b = json_decode((string) $g->borrador, true);
        if (! is_array($b) || empty($b['items'])) {
            return $this->errores($g->borrador);
        }
        $valor = BigDecimal::of((string) $b['valor']);
        $pesos = array_map(fn ($it) => BigDecimal::of(is_numeric($it['peso'] ?? null) && $it['peso'] > 0 ? (string) $it['peso'] : '1'), $b['items']);
        $suma = array_reduce($pesos, fn ($s, $p) => $s->plus($p), BigDecimal::zero());
        $acumulado = BigDecimal::zero();
        foreach ($b['items'] as $i => &$it) {
            $nuevo = $i === array_key_last($b['items'])
                ? $valor->minus($acumulado)
                : $pesos[$i]->multipliedBy($valor)->dividedBy($suma, 2, RoundingMode::HalfUp);
            $acumulado = $acumulado->plus($nuevo);
            $it['peso'] = (string) $nuevo->strippedOfTrailingZeros();
        }

        return $this->editar($g, json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function aprobar(GeneracionIa $g): Artefacto
    {
        $artefacto = $this->importador->importar($g->materia, json_decode((string) $g->borrador, true) ?? []);
        $g->update(['estado' => 'aprobado', 'resultado_type' => $artefacto->getMorphClass(), 'resultado_id' => $artefacto->id]);

        return $artefacto;
    }

    /** @return array<int, string> */
    public function errores(?string $json): array
    {
        $b = json_decode((string) $json, true);

        return is_array($b) ? $this->importador->validar($b) : ['El borrador no es JSON válido: '.json_last_error_msg().'.'];
    }

    private function actualizarBorrador(GeneracionIa $g, string $texto, array $meta): array
    {
        try {
            $b = ExtractorJson::extraer($texto);
        } catch (InvalidArgumentException $e) {
            $g->update(['borrador' => $texto]);

            return [$e->getMessage()];
        }
        // Lo que la plataforma ya sabe no se deja a la IA
        $b['formato'] = ImportadorBanco::FORMATO;
        $b['version'] = 1;
        if (! empty($meta['sigla'])) {
            $b['sigla'] = $meta['sigla'];
        }
        $g->update(['borrador' => json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return $this->importador->validar($b);
    }

    /** ED-2026B-P1, ED-2026B-P2… única en la plataforma. */
    private function sigla(Materia $materia, Dictado $dictado, string $tipo): string
    {
        $base = Str::upper($materia->codigo ?: collect(explode(' ', Str::ascii($materia->nombre)))->filter(fn ($w) => mb_strlen($w) > 2)->map(fn ($w) => $w[0])->join(''));
        $base = preg_replace('/[^A-Z0-9]/', '', $base) ?: 'ACT';
        $prefijo = "{$base}-{$dictado->periodo}-".['parcial' => 'P', 'quiz' => 'Q', 'taller' => 'T'][$tipo];
        $n = 1;
        while (Artefacto::query()->where('sigla', $prefijo.$n)->exists() || GeneracionIa::query()->where('estado', 'borrador')->where('borrador', 'like', '%"'.$prefijo.$n.'"%')->exists()) {
            $n++;
        }

        return $prefijo.$n;
    }
}
