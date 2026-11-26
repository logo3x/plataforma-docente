<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bitácora de retroalimentación de aula (anexo B). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_aula', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('materia_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('actividad_id')->nullable()->constrained('actividades')->nullOnDelete();
            $t->date('fecha');
            $t->text('observacion');
            $t->string('destino', 15)->default('pendiente');      // pendiente | leccion | requerimiento | descartada
            $t->string('referencia', 200)->nullable();             // «Requerimiento 5.4.9», «Sección 6.7»
            $t->text('leccion')->nullable();                       // la lección, escrita como indicación para el futuro
            $t->boolean('para_ia')->default(false);                // la lección entra en los prompts de la materia
            $t->string('origen', 20)->default('manual');           // manual | anulacion | reclamo | ensayo | alertas
            $t->string('origen_clave', 60)->nullable();            // para no sugerir dos veces lo mismo
            $t->timestamps();
            $t->unique(['user_id', 'origen', 'origen_clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_aula');
    }
};
