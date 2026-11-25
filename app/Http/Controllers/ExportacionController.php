<?php

namespace App\Http\Controllers;

use App\Dictados\RespaldoDictado;
use App\Models\Dictado;
use App\Models\Grupo;
use App\Notas\ExportadorNotas;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportacionController extends Controller
{
    public function xlsx(Grupo $grupo, ExportadorNotas $exportador): BinaryFileResponse
    {
        Gate::authorize('gestionar', $grupo);
        $ruta = tempnam(sys_get_temp_dir(), 'notas').'.xlsx';
        $exportador->xlsx($grupo, $ruta);

        return response()->download($ruta, $this->nombre($grupo, 'xlsx'))->deleteFileAfterSend();
    }

    public function csv(Grupo $grupo, ExportadorNotas $exportador): BinaryFileResponse
    {
        Gate::authorize('gestionar', $grupo);
        $ruta = tempnam(sys_get_temp_dir(), 'planilla').'.csv';
        $exportador->csv($grupo, $ruta);

        return response()->download($ruta, $this->nombre($grupo, 'csv', 'planilla'), ['Content-Type' => 'text/csv; charset=UTF-8'])
            ->deleteFileAfterSend();
    }

    public function respaldo(Dictado $dictado, RespaldoDictado $respaldo): StreamedResponse
    {
        Gate::authorize('gestionar', $dictado);
        $json = json_encode($respaldo->exportar($dictado), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $nombre = Str::slug($dictado->materia->nombre.' '.$dictado->periodo).'-respaldo-'.now()->format('Ymd-His').'.json';

        return response()->streamDownload(fn () => print ($json), $nombre, ['Content-Type' => 'application/json']);
    }

    private function nombre(Grupo $grupo, string $ext, string $tipo = 'notas'): string
    {
        $g = $grupo->dictado;

        return Str::slug("{$tipo} {$g->materia->nombre} {$g->periodo} {$grupo->nombre}").'-'.now()->format('Ymd').".{$ext}";
    }
}
