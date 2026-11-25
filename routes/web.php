<?php

use App\Http\Controllers\Calificador\ResultadosNavegadorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->prefix('calificador')->name('calificador.')->group(function () {
    Route::post('entregas/{entrega}/resultados-navegador', ResultadosNavegadorController::class)
        ->name('entregas.resultados-navegador');
});
