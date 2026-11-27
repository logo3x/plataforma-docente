<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.6.2: el calendario es semanal. Se registra el horario de una semana por grupo y las fechas de clase
 * salen solas entre el inicio y el fin de los cortes, sin los festivos nacionales ni los días sin clase
 * que el docente marque (receso, semana institucional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('grupo_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('dia');                 // 1 = lunes … 7 = domingo (ISO-8601)
            $t->time('hora_inicio');
            $t->time('hora_fin');
            $t->string('aula', 60)->nullable();
            $t->timestamps();
        });

        Schema::create('dias_sin_clase', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->date('desde');
            $t->date('hasta');
            $t->string('motivo', 120);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_sin_clase');
        Schema::dropIfExists('horarios');
    }
};
