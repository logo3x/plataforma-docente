<?php

namespace App\Evaluacion;

use App\Calificacion\CalificadorEntrega;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Artefacto;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\Pregunta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Recepción de entregas (6.1): lee el sobre, descifra, valida integridad, identifica al
 * estudiante y al examen, genera las alertas (6.3) y califica la parte del servidor.
 *
 * El formato «unipaz-entrega» v1 está en docs/formato-entrega.md. Un formato anterior se
 * soporta agregando un adaptador en leerSobre().
 */
final class ProcesadorEntregas
{
    public function __construct(
        private readonly ClavesDictado $claves,
        private readonly CalificadorEntrega $calificador,
    ) {}

    /**
     * @return array{estado: string, mensaje: string, entrega: ?Entrega}
     *                                                                   estado: procesada | duplicada | rechazada
     */
    public function recibir(Actividad $actividad, string $contenido, string $nombreArchivo): array
    {
        $dictado = $actividad->componente->division->dictado;
        $artefacto = $actividad->artefacto;
        $sha = hash('sha256', $contenido);

        if ($actividad->entregas()->where('sha256', $sha)->exists()) {
            return ['estado' => 'duplicada', 'mensaje' => "{$nombreArchivo}: ya se había cargado.", 'entrega' => null];
        }

        try {
            $sobre = $this->leerSobre($contenido);
        } catch (RuntimeException $e) {
            return ['estado' => 'rechazada', 'mensaje' => "{$nombreArchivo}: {$e->getMessage()}", 'entrega' => null];
        }

        // Identidad del examen por sigla e identificador (6.1.3, 6.1.4)
        if ($sobre['artefacto'] !== $artefacto->uuid) {
            $otro = Artefacto::query()->where('uuid', $sobre['artefacto'])->orWhere('sigla', $sobre['sigla'])->first();
            $donde = $otro?->actividades()->first();

            return ['estado' => 'rechazada', 'entrega' => null, 'mensaje' => $otro
                ? "{$nombreArchivo}: es del examen {$otro->sigla}".($donde ? " (actividad «{$donde->nombre}»)" : '').', no de esta actividad. Súbalo en la actividad que corresponde.'
                : "{$nombreArchivo}: el examen {$sobre['sigla']} no está cargado en la plataforma (examen no reconocido)."];
        }

        $alertas = [];
        try {
            if ($sobre['cifrado'] !== null) {
                $carga = json_decode($this->claves->descifrar($dictado, $sobre['cifrado']), true, flags: JSON_THROW_ON_ERROR);
            } else {
                $carga = $sobre['datos_planos'] ?? throw new RuntimeException('El archivo no trae datos.');
                $alertas[] = ['sin_cifrar', ['detalle' => 'El navegador del aula no permitió cifrar; la entrega llegó en claro.']];
            }
        } catch (\Throwable $e) {
            return ['estado' => 'rechazada', 'mensaje' => "{$nombreArchivo}: {$e->getMessage()}", 'entrega' => null];
        }

        // La identidad visible debe coincidir con la cifrada (6.3, «archivo manipulado»)
        if ((string) $sobre['documento'] !== (string) ($carga['documento'] ?? '') || ($carga['sigla'] ?? null) !== $artefacto->sigla) {
            $alertas[] = ['manipulado', ['visible' => $sobre['documento'], 'cifrado' => $carga['documento'] ?? null]];
        }

        $estudiante = Estudiante::query()->where('dictado_id', $dictado->id)->where('documento', (string) ($carga['documento'] ?? ''))->first();
        if ($estudiante === null) {
            return ['estado' => 'rechazada', 'entrega' => null,
                'mensaje' => "{$nombreArchivo}: el documento {$carga['documento']} ({$carga['nombre']}) no está en la lista del periodo."];
        }

        $gruposActividad = $actividad->grupos()->pluck('grupos.id');
        if ($gruposActividad->isNotEmpty() && $estudiante->grupos()->whereIn('grupos.id', $gruposActividad)->doesntExist()) {
            $alertas[] = ['grupo_no_cuadra', ['grupos' => $estudiante->grupos()->pluck('nombre')->all()]];
        }

        $entrega = DB::transaction(function () use ($actividad, $estudiante, $carga, $contenido, $sha, $sobre, $alertas, $artefacto) {
            $ruta = "entregas/{$actividad->id}/{$sha}.json";
            Storage::disk('local')->put($ruta, $contenido);

            $inicio = isset($carga['inicio']) ? strtotime($carga['inicio']) : null;
            $fin = isset($carga['fin']) ? strtotime($carga['fin']) : null;

            $entrega = $actividad->entregas()->create([
                'subida_por_id' => $estudiante->id,
                'sigla_detectada' => $sobre['sigla'],
                'artefacto_uuid_detectado' => $sobre['artefacto'],
                'ruta' => $ruta,
                'sha256' => $sha,
                'cifrada' => $sobre['cifrado'] !== null,
                'sesion' => $carga['sesion'] ?? [],
                'duracion_seg' => $inicio && $fin ? max(0, $fin - $inicio) : null,
                'cierre' => $carga['cierre'] ?? null,
            ]);

            // Integrantes: quien entrega más los declarados que existan en el dictado (6.8.9)
            $documentos = collect($carga['integrantes'] ?? [])->map(fn ($d) => (string) $d)->unique();
            $integrantes = Estudiante::query()->where('dictado_id', $actividad->componente->division->dictado_id)
                ->whereIn('documento', $documentos)->pluck('id', 'documento');
            $entrega->integrantes()->attach($integrantes->values()->push($estudiante->id)->unique()->all());
            foreach ($documentos->diff($integrantes->keys()) as $desconocido) {
                $alertas[] = ['integrante_desconocido', ['documento' => $desconocido]];
            }

            foreach ([...$alertas, ...$this->alertasDeSesion($entrega, $artefacto->configuracion)] as [$tipo, $datos]) {
                Alerta::query()->create(['actividad_id' => $actividad->id, 'entrega_id' => $entrega->id,
                    'estudiante_id' => $estudiante->id, 'tipo' => $tipo, 'datos' => $datos]);
            }

            $this->calificador->calificarServidor($entrega, $carga['respuestas'] ?? []);

            return $entrega;
        });

        $this->detectarTextoIdentico($actividad);

        return ['estado' => 'procesada', 'mensaje' => "{$nombreArchivo}: {$estudiante->nombreCompleto()}.", 'entrega' => $entrega];
    }

    /** @return array{formato:string, sigla:string, artefacto:string, documento:string, cifrado:?array, datos_planos?:array} */
    private function leerSobre(string $contenido): array
    {
        $s = json_decode($contenido, true);
        if (! is_array($s)) {
            throw new RuntimeException('no es un archivo de entrega (no es JSON).');
        }
        // Aquí se agregará el adaptador del formato del calificador HTML anterior.
        if (($s['formato'] ?? null) !== 'unipaz-entrega' || (int) ($s['version'] ?? 0) !== 1) {
            throw new RuntimeException('formato de entrega no reconocido.');
        }
        foreach (['sigla', 'artefacto', 'documento'] as $c) {
            if (blank($s[$c] ?? null)) {
                throw new RuntimeException("al sobre le falta «{$c}».");
            }
        }

        return $s + ['cifrado' => null];
    }

    /** Alertas informativas de la sesión (6.3). Nunca sancionan por sí solas. */
    private function alertasDeSesion(Entrega $e, array $config): array
    {
        $a = [];
        $sesion = $e->sesion ?? [];
        $salidas = $sesion['salidas'] ?? [];
        $total = array_sum(array_column($salidas, 'segundos'));
        if (count($salidas) >= ($config['umbral_salidas'] ?? 3) || $total >= ($config['umbral_salidas_segundos'] ?? 60)) {
            $a[] = ['salidas', ['cantidad' => count($salidas), 'segundos' => $total, 'detalle' => $salidas]];
        }
        if (($sesion['pegados'] ?? 0) > 0) {
            $a[] = ['pegados', ['cantidad' => $sesion['pegados']]];
        }
        if (($m = $config['duracion_minima_minutos'] ?? null) && $e->duracion_seg !== null && $e->duracion_seg < $m * 60) {
            $a[] = ['duracion_anomala', ['segundos' => $e->duracion_seg, 'minimo' => $m * 60]];
        }
        if ($e->cierre === 'automatico') {
            $a[] = ['cierre_automatico', []];
        }
        if (! empty($sesion['reconexiones'])) {
            $a[] = ['reconexion', ['detalle' => $sesion['reconexiones']]];
        }

        return $a;
    }

    /**
     * Texto idéntico entre entregas (6.3) y primera versión de similitud (6.11): compara
     * respuestas abiertas y código, normalizados (sin comentarios, espacios ni mayúsculas).
     * Señala, nunca sanciona.
     */
    private function detectarTextoIdentico(Actividad $actividad): void
    {
        $respuestas = DB::table('respuestas')
            ->join('entregas', 'entregas.id', '=', 'respuestas.entrega_id')
            ->join('artefacto_pregunta', 'artefacto_pregunta.id', '=', 'respuestas.artefacto_pregunta_id')
            ->join('preguntas', 'preguntas.id', '=', 'artefacto_pregunta.pregunta_id')
            ->where('entregas.actividad_id', $actividad->id)
            ->whereIn('preguntas.tipo', ['abierta', 'reto'])
            ->get(['respuestas.entrega_id', 'respuestas.artefacto_pregunta_id', 'respuestas.respuesta', 'preguntas.tipo',
                'artefacto_pregunta.pregunta_id']);

        $grupos = [];
        foreach ($respuestas as $r) {
            $texto = json_decode($r->respuesta, true)['valor'] ?? null;
            if (! is_string($texto)) {
                continue;
            }
            $plantilla = $r->tipo === 'reto' ? (Pregunta::query()->find($r->pregunta_id)?->enunciado['plantilla_codigo'] ?? '') : '';
            $norm = $this->normalizar($texto, $r->tipo === 'reto');
            if (mb_strlen($norm) < 25 || $norm === $this->normalizar($plantilla, true)) {
                continue;   // respuestas muy cortas o la plantilla sin tocar no son señal
            }
            $grupos[$r->artefacto_pregunta_id.'|'.hash('sha256', $norm)][] = $r->entrega_id;
        }

        foreach ($grupos as $clave => $entregas) {
            $entregas = array_values(array_unique($entregas));
            if (count($entregas) < 2) {
                continue;
            }
            [$item] = explode('|', $clave);
            foreach ($entregas as $id) {
                $e = Entrega::query()->find($id);
                Alerta::query()->firstOrCreate(
                    ['actividad_id' => $actividad->id, 'entrega_id' => $id, 'tipo' => 'texto_identico', 'estudiante_id' => $e->subida_por_id],
                    ['datos' => ['item' => (int) $item, 'con' => array_values(array_diff($entregas, [$id]))]],
                );
            }
        }
    }

    private function normalizar(string $t, bool $codigo): string
    {
        if ($codigo) {
            $t = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $t);   // quitar comentarios no esconde una copia (6.11.2)
        }

        return mb_strtolower(preg_replace('/\s+/u', '', $t));
    }
}
