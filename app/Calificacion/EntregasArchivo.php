<?php

namespace App\Calificacion;

use App\Archivos\ExtractorTexto;
use App\Evaluacion\Rubricas;
use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\Estudiante;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Trabajos entregados como archivo (5.1, 5.7 formato de entrega): un PDF consolidado o un
 * archivo libre. Se reconoce al estudiante por el documento en el nombre del archivo, se califica
 * con la rúbrica de la actividad o a mano, y la nota sigue el mismo camino que la del examen
 * (paso al registro, nota grupal con tope por tamaño, devolución).
 */
final class EntregasArchivo
{
    public const MAX_KB = 20480;

    public const FORMATOS = ['pdf' => 'PDF consolidado', 'libre' => 'Archivo libre'];

    public function __construct(private readonly Rubricas $rubricas, private readonly ExtractorTexto $extractor) {}

    /**
     * @return array{estado: string, mensaje: string, entrega: ?Entrega} estado: recibida | sin_identificar | duplicada | rechazada
     */
    public function recibir(Actividad $actividad, UploadedFile $archivo): array
    {
        return $this->recibirContenido($actividad, $archivo->getClientOriginalName(), (string) file_get_contents($archivo->getRealPath()),
            $archivo->getMimeType());
    }

    /**
     * El mismo recibo, con el contenido ya en memoria. Si la identidad ya se conoce —por ejemplo, la entrega
     * viene de Classroom con su autor—, se pasa en $integrantes y no se adivina por el nombre del archivo.
     *
     * @return array{estado: string, mensaje: string, entrega: ?Entrega}
     */
    public function recibirContenido(Actividad $actividad, string $nombre, string $contenido, ?string $mime = null, ?Collection $integrantes = null, array $extra = []): array
    {
        $sha = hash('sha256', $contenido);
        $esPdf = str_starts_with($contenido, '%PDF-');

        if ($actividad->formato_entrega === 'pdf' && ! $esPdf) {
            return ['estado' => 'rechazada', 'entrega' => null,
                'mensaje' => "{$nombre}: no es un PDF. Esta actividad pide el trabajo consolidado en un solo PDF, no capturas ni archivos sueltos."];
        }
        if ($actividad->entregas()->where('sha256', $sha)->exists()) {
            return ['estado' => 'duplicada', 'entrega' => null, 'mensaje' => "{$nombre}: ya se había cargado."];
        }

        $integrantes ??= $this->reconocer($actividad, $nombre);
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) ?: ($esPdf ? 'pdf' : 'bin');
        $ruta = "entregas/{$actividad->id}/{$sha}.".(preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin');
        Storage::disk('local')->put($ruta, $contenido);
        // 6.11.1: el texto del archivo, para compararlo con las demás entregas; si no se puede leer, solo se compara por huella
        $texto = null;
        $tipo = $esPdf ? 'pdf' : $ext;
        if (in_array($tipo, ExtractorTexto::EXTENSIONES, true)) {
            try {
                $texto = mb_substr($this->extractor->extraer(Storage::disk('local')->path($ruta), $tipo), 0, 200000) ?: null;
            } catch (\Throwable) {
                $texto = null;
            }
        }

        $entrega = DB::transaction(function () use ($actividad, $nombre, $sha, $ruta, $contenido, $esPdf, $mime, $integrantes, $extra, $texto) {
            $e = $actividad->entregas()->create([
                'tipo' => 'archivo', 'subida_por_id' => $integrantes->first()?->id, 'ruta' => $ruta, 'sha256' => $sha, 'cifrada' => false,
                'archivo_nombre' => mb_substr($nombre, 0, 250), 'archivo_mime' => $esPdf ? 'application/pdf' : ($mime ?: 'application/octet-stream'),
                'archivo_bytes' => strlen($contenido), 'paginas' => $esPdf ? $this->paginas($contenido) : null,
                'estado_codigo' => 'pendiente', 'procesada_en' => now(), 'texto_extraido' => $texto,
            ] + $extra);
            $e->integrantes()->attach($integrantes->pluck('id')->all());

            return $e;
        });

        // 6.1.4: el mismo archivo ya está en otra actividad del periodo: probablemente se subió a la equivocada
        $otra = Entrega::query()->where('sha256', $sha)->whereKeyNot($entrega->id)
            ->whereHas('actividad.componente.division', fn ($q) => $q->where('dictado_id', $actividad->componente->division->dictado_id))
            ->with('actividad')->first();
        if ($otra) {
            foreach ($integrantes->isEmpty() ? [null] : $integrantes as $i) {
                Alerta::query()->create(['actividad_id' => $actividad->id, 'entrega_id' => $entrega->id, 'estudiante_id' => $i?->id, 'tipo' => 'otra_actividad',
                    'datos' => ['actividad' => $otra->actividad->nombre, 'actividad_id' => $otra->actividad_id]]);
            }
            $aviso = " Atención: este mismo archivo ya está en «{$otra->actividad->nombre}». ¿Se subió a la actividad equivocada?";
        }

        return $integrantes->isEmpty()
            ? ['estado' => 'sin_identificar', 'entrega' => $entrega, 'mensaje' => "{$nombre}: no trae el documento de ningún estudiante en el nombre. Asígnele los integrantes al calificarlo.".($aviso ?? '')]
            : ['estado' => 'recibida', 'entrega' => $entrega, 'mensaje' => "{$nombre}: de ".$integrantes->map->nombreCompleto()->join(' · ').'.'.($aviso ?? '')];
    }

    /** Estudiantes del periodo cuyo documento aparece en el nombre del archivo («1098765432_informe.pdf»). */
    public function reconocer(Actividad $actividad, string $nombre): Collection
    {
        preg_match_all('/\d{5,}/', $nombre, $m);
        if ($m[0] === []) {
            return collect();
        }

        return $actividad->componente->division->dictado->estudiantes()->whereIn('documento', $m[0])->orderBy('apellidos')->get();
    }

    /** Cambia los integrantes de la entrega; solo estudiantes del periodo. */
    public function integrantes(Entrega $entrega, array $ids): void
    {
        $validos = Estudiante::query()->where('dictado_id', $entrega->actividad->componente->division->dictado_id)->whereIn('id', $ids)->pluck('id');
        DB::transaction(function () use ($entrega, $validos) {
            $entrega->integrantes()->sync($validos->all());
            $entrega->update(['subida_por_id' => $validos->first()]);
        });
    }

    /**
     * Califica la entrega: con la rúbrica de la actividad (un nivel por criterio) o con un puntaje
     * escrito en la escala de la actividad. Con criterios sin evaluar queda pendiente.
     *
     * @param  array<int, int|null>|null  $seleccion
     */
    public function calificar(Entrega $entrega, ?array $seleccion, ?string $puntaje, ?string $comentario): void
    {
        $actividad = $entrega->actividad;
        $maximo = (string) $actividad->valor_max;
        $comentario = filled($comentario) ? trim($comentario) : null;

        if ($actividad->rubrica && $seleccion !== null) {
            $criterios = $actividad->rubrica->criterios;
            $seleccion = array_map(fn ($i) => $i === null || $i === '' ? null : (int) $i, array_replace(array_fill(0, count($criterios), null), $seleccion));
            $valor = $this->rubricas->puntaje($criterios, $seleccion, $maximo);
            $desglose = ['rubrica_id' => $actividad->rubrica->id] + $this->rubricas->desglose($actividad->rubrica->only('titulo', 'criterios'), $seleccion, $maximo);
        } else {
            $valor = null;
            $desglose = null;
            if (filled($puntaje)) {
                try {
                    $v = BigDecimal::of(str_replace(',', '.', trim($puntaje)));
                } catch (MathException) {
                    throw new InvalidArgumentException('El puntaje no es un número.');
                }
                if ($v->isNegative() || $v->isGreaterThan($maximo)) {
                    throw new InvalidArgumentException('El puntaje debe estar entre 0 y '.BigDecimal::of($maximo)->strippedOfTrailingZeros().'.');
                }
                $valor = (string) $v->toScale(4, RoundingMode::HalfUp);
            }
        }

        $entrega->update(['puntaje' => $valor, 'desglose' => $desglose, 'comentario' => $comentario,
            'calificada_en' => $valor === null ? null : now(), 'estado_codigo' => $valor === null ? 'pendiente' : 'calificado']);
    }

    /** Páginas de un PDF, contando sus objetos de página (sin interpretarlo). */
    private function paginas(string $pdf): ?int
    {
        $n = preg_match_all('#/Type\s*/Page(?!s)#', $pdf);

        return $n > 0 ? $n : null;
    }
}
