<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de reclamos y su resolución (6.5.6): qué se alegó, qué se decidió, qué corrección se
 * aplicó y a quién afectó, para no repetir el mismo error en el siguiente corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclamos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->foreignId('artefacto_pregunta_id')->nullable()->constrained('artefacto_pregunta')->nullOnDelete();
            $t->text('descripcion');                                  // lo que alega el estudiante
            $t->string('estado', 15)->default('abierto');             // abierto | aceptado | rechazado
            $t->string('accion', 20)->nullable();                     // anulacion | correccion_clave | ajuste_nota
            $t->text('resolucion')->nullable();                       // respuesta del docente
            $t->json('detalle')->nullable();                          // clave anterior y nueva, nota anterior, notas recalculadas
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('atendido_en')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamos');
    }
};
