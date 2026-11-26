<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Registro de los ensayos de una actividad (5.6.4): cuándo, en qué equipo y qué resultó. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ensayos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('artefacto_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('equipo');                                         // navegador, sistema y pantalla
            $t->boolean('cifrada');
            $t->boolean('equipo_listo');                                  // pasó todas las verificaciones del navegador
            $t->json('verificaciones');                                   // las del examen de ensayo
            $t->unsignedSmallInteger('salidas')->default(0);
            $t->unsignedInteger('segundos_fuera')->default(0);
            $t->unsignedInteger('ejecuciones')->default(0);
            $t->decimal('puntaje_servidor', 7, 4)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ensayos');
    }
};
