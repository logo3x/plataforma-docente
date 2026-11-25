<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolución al estudiante (6.9): la actividad se libera de forma explícita (el informe trae las
 * respuestas correctas, 6.5.2) y se registra quién recibió su informe y quién falta (6.9.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $t) {
            $t->timestamp('devolucion_liberada_en')->nullable();
            $t->boolean('informe_muestra_original')->default(false);   // 3.6.5
        });

        Schema::create('devoluciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->timestamp('enlace_vence_en')->nullable();
            $t->timestamp('revocada_en')->nullable();
            $t->timestamp('abierta_en')->nullable();          // primera vez que el estudiante la abrió
            $t->unsignedInteger('aperturas')->default(0);
            $t->timestamp('entregada_en')->nullable();         // marcada por el docente (archivo por otro canal)
            $t->timestamps();
            $t->unique(['actividad_id', 'estudiante_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones');
        Schema::table('actividades', fn (Blueprint $t) => $t->dropColumn(['devolucion_liberada_en', 'informe_muestra_original']));
    }
};
