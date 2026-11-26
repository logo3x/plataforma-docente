<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sesión en vivo con internet (sección 13): entrega automática, panel del grupo y finalización masiva. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_vivo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->unsignedTinyInteger('banco')->default(1);
            $t->string('codigo', 12)->unique();                       // lo que el estudiante escribe: /v/{codigo}
            $t->timestamp('cerrada_en')->nullable();                  // cerrada: nadie más entra
            $t->timestamps();
        });

        Schema::create('participantes_vivo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sesion_vivo_id')->constrained('sesiones_vivo')->cascadeOnDelete();
            $t->string('documento', 20);
            $t->string('nombre');
            $t->foreignId('estudiante_id')->nullable()->constrained()->nullOnDelete();
            $t->string('version', 5)->nullable();
            $t->char('clave_hash', 64);                               // credencial del navegador que lo atiende
            $t->unsignedSmallInteger('ingresos')->default(1);         // más de uno: reabrió o entró desde otro equipo
            $t->timestamp('ultimo_latido')->nullable();
            $t->unsignedSmallInteger('respondidas')->default(0);
            $t->unsignedSmallInteger('total')->default(0);
            $t->unsignedSmallInteger('salidas')->default(0);
            $t->unsignedInteger('segundos_fuera')->default(0);
            $t->longText('instantanea')->nullable();                  // último sobre cifrado con lo respondido
            $t->timestamp('instantanea_en')->nullable();
            $t->timestamp('cierre_solicitado_en')->nullable();         // el docente pidió finalizarlo (13.1)
            $t->foreignId('cierre_solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('finalizado_en')->nullable();
            $t->string('finalizado_como', 25)->nullable();            // estudiante | tiempo | docente | docente_sin_respuesta | sin_datos
            $t->foreignId('entrega_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['sesion_vivo_id', 'documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participantes_vivo');
        Schema::dropIfExists('sesiones_vivo');
    }
};
