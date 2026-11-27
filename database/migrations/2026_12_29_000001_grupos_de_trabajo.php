<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 6.8.1 a 6.8.3: quién arma los grupos de una actividad grupal. «docente»: los define antes de aplicar
 * (por defecto en el parcial en sala); «estudiante»: quien entrega escoge a sus compañeros de la lista
 * (por defecto en el taller). Los grupos del docente se guardan como número de grupo por estudiante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $t) {
            $t->string('grupos_por', 10)->nullable()->after('es_grupal');       // null = según el modo de la actividad
        });

        Schema::create('integrantes_trabajo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->unsignedSmallInteger('numero');
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->unique(['actividad_id', 'estudiante_id']);                      // un estudiante, un grupo por actividad
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrantes_trabajo');
        Schema::table('actividades', fn (Blueprint $t) => $t->dropColumn('grupos_por'));
    }
};
