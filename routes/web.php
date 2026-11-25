<?php

use App\Http\Controllers\Calificador\ResultadosNavegadorController;
use App\Http\Controllers\DictadoController;
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

    Route::prefix('calificador')->name('calificador.')->group(function () {
        Route::post('entregas/{entrega}/resultados-navegador', ResultadosNavegadorController::class)
            ->name('entregas.resultados-navegador');
    });
});
