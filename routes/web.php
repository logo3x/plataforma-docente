<?php

use App\Http\Controllers\Calificador\ResultadosNavegadorController;
use App\Http\Controllers\CalificadorController;
use App\Http\Controllers\CargaNotasController;
use App\Http\Controllers\CierreController;
use App\Http\Controllers\DictadoController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ExportacionController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\MateriaController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\SeguridadController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inicio');

Route::middleware('auth')->group(function () {
    Route::get('/inicio', InicioController::class)->name('inicio');
    Route::get('/seguridad', SeguridadController::class)->name('seguridad');

    Route::get('/materias/crear', [MateriaController::class, 'create'])->name('materias.create');
    Route::post('/materias', [MateriaController::class, 'store'])->name('materias.store');

    Route::get('/dictados/{dictado}', [DictadoController::class, 'show'])->name('dictados.show');
    Route::post('/dictados/{dictado}/siguiente', [DictadoController::class, 'store'])->name('dictados.store');

    Route::get('/plantilla-lista-clase', [ImportacionController::class, 'plantilla'])->name('importacion.plantilla');
    Route::get('/grupos/{grupo}/importar', [ImportacionController::class, 'create'])->name('importacion.create');
    Route::post('/grupos/{grupo}/importar', [ImportacionController::class, 'previsualizar'])->name('importacion.previa');
    Route::post('/grupos/{grupo}/importar/confirmar', [ImportacionController::class, 'confirmar'])->name('importacion.confirmar');

    Route::get('/grupos/{grupo}/notas', RegistroController::class)->name('registro');
    Route::get('/grupos/{grupo}/cierre', CierreController::class)->name('cierre');
    Route::get('/grupos/{grupo}/exportar.xlsx', [ExportacionController::class, 'xlsx'])->name('exportar.xlsx');
    Route::get('/grupos/{grupo}/planilla.csv', [ExportacionController::class, 'csv'])->name('exportar.csv');
    Route::get('/dictados/{dictado}/respaldo.json', [ExportacionController::class, 'respaldo'])->name('respaldo');
    Route::get('/grupos/{grupo}/estudiantes/{estudiante}', [EstudianteController::class, 'edit'])->name('estudiantes.edit');
    Route::put('/grupos/{grupo}/estudiantes/{estudiante}', [EstudianteController::class, 'update'])->name('estudiantes.update');
    Route::get('/grupos/{grupo}/cargar-notas', [CargaNotasController::class, 'create'])->name('carga-notas.create');
    Route::post('/grupos/{grupo}/cargar-notas', [CargaNotasController::class, 'subir'])->name('carga-notas.subir');
    Route::get('/grupos/{grupo}/cargar-notas/previa', [CargaNotasController::class, 'previa'])->name('carga-notas.previa');
    Route::post('/grupos/{grupo}/cargar-notas/confirmar', [CargaNotasController::class, 'confirmar'])->name('carga-notas.confirmar');

    Route::get('/materias/{materia}/actividades', [CalificadorController::class, 'artefactos'])->name('artefactos.index');
    Route::post('/materias/{materia}/actividades', [CalificadorController::class, 'importar'])->name('artefactos.importar');
    Route::post('/actividades-evaluables/{artefacto}/aplicar', [CalificadorController::class, 'aplicar'])->name('artefactos.aplicar');

    Route::prefix('calificador')->name('calificador.')->group(function () {
        Route::get('actividades/{actividad}', [CalificadorController::class, 'show'])->name('show');
        Route::get('actividades/{actividad}/examen.html', [CalificadorController::class, 'examen'])->name('examen');
        Route::post('actividades/{actividad}/entregas', [CalificadorController::class, 'subir'])->name('subir');
        Route::get('actividades/{actividad}/retos-pendientes', [CalificadorController::class, 'retosPendientes'])->name('retos');
        Route::post('actividades/{actividad}/registro', [CalificadorController::class, 'pasarAlRegistro'])->name('registro');
        Route::get('actividades/{actividad}/abiertas', [CalificadorController::class, 'abiertas'])->name('abiertas');
        Route::get('entregas/{entrega}', [CalificadorController::class, 'detalle'])->name('detalle');
        Route::post('entregas/{entrega}/resultados-navegador', ResultadosNavegadorController::class)
            ->name('entregas.resultados-navegador');
    });
});
