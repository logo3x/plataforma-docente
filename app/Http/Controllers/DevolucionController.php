<?php

namespace App\Http\Controllers;

use App\Calificacion\InformeEstudiante;
use App\Models\Actividad;
use App\Models\Devolucion;
use App\Models\Estudiante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Informe individual (6.5) y devolución al estudiante (6.9): liberación explícita, archivo por
 * estudiante, enlace personal firmado y registro de quién recibió.
 */
class DevolucionController extends Controller
{
    public function __construct(private readonly InformeEstudiante $informe) {}

    public function index(Actividad $actividad): View
    {
        $this->autorizar($actividad);
        $actividad->load('componente.division.dictado.materia', 'artefacto');
        $devoluciones = $actividad->devoluciones()->get()->keyBy('estudiante_id');

        $filas = $this->informe->destinatarios($actividad)->map(fn (Estudiante $e) => [
            'estudiante' => $e,
            'presento' => $this->informe->presento($actividad, $e),
            'devolucion' => $devoluciones->get($e->id),
        ]);

        return view('devolucion.index', [
            'actividad' => $actividad,
            'filas' => $filas,
            'pendientes' => $filas->where('presento', false)->count(),
            'recibidas' => $filas->filter(fn ($f) => $f['devolucion']?->recibida())->count(),
        ]);
    }

    /** Vista previa del docente: siempre disponible, incluso antes de liberar. */
    public function ver(Actividad $actividad, Estudiante $estudiante): Response
    {
        $this->autorizar($actividad);
        abort_unless($this->informe->destinatarios($actividad)->contains('id', $estudiante->id), 404);

        return response($this->html($actividad, $estudiante));
    }

    /** 6.5.2: el informe trae las respuestas correctas; se libera solo cuando el docente lo decide. */
    public function liberar(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $dias = (int) $request->validate(['dias' => ['required', 'integer', 'between:1,180']])['dias'];

        $actividad->update(['devolucion_liberada_en' => now()]);
        foreach ($this->informe->destinatarios($actividad) as $e) {
            Devolucion::query()->updateOrCreate(
                ['actividad_id' => $actividad->id, 'estudiante_id' => $e->id],
                ['enlace_vence_en' => now()->addDays($dias), 'revocada_en' => null],
            );
        }

        return back()->with('estado', "Devolución liberada. Los enlaces personales valen {$dias} días.");
    }

    public function revocar(Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $actividad->update(['devolucion_liberada_en' => null]);
        $actividad->devoluciones()->whereNull('revocada_en')->update(['revocada_en' => now()]);

        return back()->with('estado', 'Devolución retirada: los enlaces dejan de funcionar. Los archivos ya enviados no se pueden retirar.');
    }

    public function configurar(Request $request, Actividad $actividad): RedirectResponse
    {
        $this->autorizar($actividad);
        $actividad->update(['informe_muestra_original' => $request->boolean('informe_muestra_original')]);

        return back()->with('estado', 'Preferencia del informe guardada.');
    }

    /** Un HTML autocontenido por estudiante, en un ZIP (canal «archivo», 6.9.3). */
    public function zip(Actividad $actividad): BinaryFileResponse|RedirectResponse
    {
        $this->autorizar($actividad);
        if (! $actividad->devolucion_liberada_en) {
            return back()->with('error_devolucion', 'Primero libere la devolución.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'informes').'.zip';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($this->informe->destinatarios($actividad) as $e) {
            if ($this->informe->presento($actividad, $e)) {
                $zip->addFromString(Str::slug("{$e->documento} {$e->apellidos} {$e->nombres}").'.html', $this->html($actividad, $e));
            }
        }
        $zip->close();

        return response()->download($ruta, Str::slug("informes {$actividad->nombre}").'.zip')->deleteFileAfterSend();
    }

    /** Enlaces personales firmados, para enviarlos por correo o Classroom (6.9.3). */
    public function enlaces(Actividad $actividad): StreamedResponse|RedirectResponse
    {
        $this->autorizar($actividad);
        if (! $actividad->devolucion_liberada_en) {
            return back()->with('error_devolucion', 'Primero libere la devolución.');
        }
        $devoluciones = $actividad->devoluciones()->whereNull('revocada_en')->with('estudiante')->get()
            ->sortBy(fn ($d) => $d->estudiante->apellidos);

        return response()->streamDownload(function () use ($devoluciones) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\u{FEFF}");
            fputcsv($f, ['Documento', 'Apellidos', 'Nombres', 'Correo', 'Enlace', 'Vence'], ';');
            foreach ($devoluciones as $d) {
                $d->estudiante->makeVisible('email');
                fputcsv($f, [$d->estudiante->documento, $d->estudiante->apellidos, $d->estudiante->nombres, $d->estudiante->email,
                    $this->enlace($d), $d->enlace_vence_en->format('Y-m-d')], ';');
            }
            fclose($f);
        }, Str::slug("enlaces {$actividad->nombre}").'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** El docente registra que entregó el informe por otro canal (6.9.5). */
    public function marcar(Devolucion $devolucion): RedirectResponse
    {
        $this->autorizar($devolucion->actividad);
        $devolucion->update(['entregada_en' => $devolucion->entregada_en ? null : now()]);

        return back();
    }

    /** Página del estudiante: solo su informe, por enlace firmado y sin cuenta (6.9.3, 6.9.4). */
    public function publico(Devolucion $devolucion): Response
    {
        $actividad = $devolucion->actividad;
        $vigente = $actividad->devolucion_liberada_en !== null && $devolucion->revocada_en === null
            && ($devolucion->enlace_vence_en === null || $devolucion->enlace_vence_en->isFuture());
        abort_unless($vigente, 410, 'Este enlace ya no está disponible. Pida uno nuevo a su docente.');

        $devolucion->update(['abierta_en' => $devolucion->abierta_en ?? now(), 'aperturas' => $devolucion->aperturas + 1]);

        return response($this->html($actividad, $devolucion->estudiante))->header('X-Robots-Tag', 'noindex');
    }

    public function enlace(Devolucion $d): string
    {
        return URL::temporarySignedRoute('informe.publico', $d->enlace_vence_en, ['devolucion' => $d->id]);
    }

    private function html(Actividad $actividad, Estudiante $estudiante): string
    {
        return view('informe.estudiante', $this->informe->datos($actividad, $estudiante) + [
            'css' => file_get_contents(public_path('css/app.css')),
        ])->render();
    }

    private function autorizar(Actividad $actividad): void
    {
        Gate::authorize('gestionar', $actividad->componente->division->dictado);
    }
}
