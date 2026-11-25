<?php

use App\Http\Controllers\Calificador\ResultadosNavegadorController;
use App\Http\Controllers\CalificadorController;
use App\Http\Controllers\CargaNotasController;
use App\Http\Controllers\CierreController;
use App\Http\Controllers\ContenidoController;
use App\Http\Controllers\DictadoController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ExportacionController;
use App\Http\Controllers\GeneracionBancoController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\MateriaController;
use App\Http\Controllers\MotorIaController;
use App\Http\Controllers\PlantillasIaController;
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

    Route::prefix('ia')->name('ia.')->group(function () {
        Route::get('motores', [MotorIaController::class, 'index'])->name('motores');
        Route::post('motores', [MotorIaController::class, 'store'])->name('motores.store');
        Route::post('motores/{motor}/probar', [MotorIaController::class, 'probar'])->name('motores.probar');
        Route::post('motores/{motor}/predeterminado', [MotorIaController::class, 'predeterminado'])->name('motores.predeterminado');
        Route::delete('motores/{motor}', [MotorIaController::class, 'destroy'])->name('motores.destroy');
        Route::get('plantillas', [PlantillasIaController::class, 'edit'])->name('plantillas');
        Route::post('plantillas/perfil', [PlantillasIaController::class, 'perfil'])->name('plantillas.perfil');
        Route::post('plantillas', [PlantillasIaController::class, 'plantilla'])->name('plantillas.guardar');
        Route::post('plantillas/restaurar', [PlantillasIaController::class, 'restaurar'])->name('plantillas.restaurar');
    });
    Route::get('/materias/{materia}/estilo', [PlantillasIaController::class, 'estilo'])->name('materias.estilo');
    Route::put('/materias/{materia}/estilo', [PlantillasIaController::class, 'guardarEstilo'])->name('materias.estilo.guardar');
    Route::get('/materias/{materia}/contenido', [ContenidoController::class, 'index'])->name('contenido.index');
    Route::post('/materias/{materia}/contenido', [ContenidoController::class, 'store'])->name('contenido.store');
    Route::get('/contenido/{contenido}', [ContenidoController::class, 'show'])->name('contenido.show');
    Route::delete('/contenido/{contenido}', [ContenidoController::class, 'destroy'])->name('contenido.destroy');
    Route::get('/materias/{materia}/generar', [GeneracionBancoController::class, 'create'])->name('generacion.create');
    Route::post('/materias/{materia}/generar/prompt', [GeneracionBancoController::class, 'preparar'])->name('generacion.preparar');
    Route::post('/materias/{materia}/generar', [GeneracionBancoController::class, 'generar'])->name('generacion.generar');
    Route::get('/generaciones/{generacion}', [GeneracionBancoController::class, 'show'])->name('generacion.show');
    Route::put('/generaciones/{generacion}', [GeneracionBancoController::class, 'editar'])->name('generacion.editar');
    Route::post('/generaciones/{generacion}/reescalar', [GeneracionBancoController::class, 'reescalar'])->name('generacion.reescalar');
    Route::post('/generaciones/{generacion}/corregir', [GeneracionBancoController::class, 'corregir'])->name('generacion.corregir');
    Route::post('/generaciones/{generacion}/aprobar', [GeneracionBancoController::class, 'aprobar'])->name('generacion.aprobar');
    Route::post('/generaciones/{generacion}/descartar', [GeneracionBancoController::class, 'descartar'])->name('generacion.descartar');

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
