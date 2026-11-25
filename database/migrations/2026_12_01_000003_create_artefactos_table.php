<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artefactos generados, banco de preguntas y actividades aplicadas.
 * Requerimientos: 5.4, 5.6, 5.7, 6.1.3, 6.5.5, 6.8, 7.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artefactos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('origen_artefacto_id')->nullable()->constrained('artefactos')->nullOnDelete();
            $t->uuid('uuid')->unique();                                  // embebido en el HTML (6.1.4)
            $t->string('sigla', 40)->unique();                           // "ED-2026A-P2"
            $t->string('tipo', 30);                                      // quiz, parcial, taller, manual...
            $t->string('titulo');
            $t->json('configuracion');                                   // panel 5.7
            $t->json('especificacion_calificacion')->nullable();         // reglas definidas una sola vez
            $t->text('solucionario')->nullable();                        // cifrado (cast encrypted)
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('ensayado_en')->nullable();                    // 5.6.4
            $t->string('ensayado_equipo')->nullable();
            $t->timestamps();
        });

        Schema::create('preguntas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('temario_item_id')->nullable()->constrained()->nullOnDelete(); // 5.4.14
            $t->string('tipo', 30);          // unica, multiple, vf, emparejar, ordenar, completar, abierta, reto
            $t->string('dificultad', 10)->default('media');
            $t->json('enunciado');
            $t->text('clave');               // cifrado: respuesta correcta y casos reservados
            $t->text('justificacion')->nullable();
            $t->timestamps();
        });

        Schema::create('artefacto_pregunta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artefacto_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pregunta_id')->constrained()->restrictOnDelete();
            $t->string('banco', 5)->default('A');                        // A, B, 2A, 2B (5.4.2)
            $t->decimal('peso', 7, 4);
            $t->unsignedSmallInteger('orden');
            $t->timestamp('anulada_en')->nullable();                     // 6.5.5
            $t->string('regla_anulacion', 30)->nullable();               // dar_por_buena | retirar_y_reescalar
            $t->string('motivo_anulacion')->nullable();
        });

        Schema::create('actividades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('componente_id')->constrained()->cascadeOnDelete();
            $t->foreignId('artefacto_id')->nullable()->constrained()->nullOnDelete();
            $t->string('nombre');
            $t->date('fecha')->nullable();
            $t->decimal('valor_max', 7, 4);
            $t->decimal('peso_escrita', 5, 4)->default(0.5);             // B3: 50/50 es parámetro
            $t->boolean('es_grupal')->default(false);
            $t->unsignedTinyInteger('tamano_grupo')->nullable();         // 6.8 tope por tamaño
            $t->decimal('tope_exceso', 7, 4)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('actividad_grupo', function (Blueprint $t) {
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('grupo_id')->constrained()->cascadeOnDelete();
            $t->primary(['actividad_id', 'grupo_id']);
        });

        Schema::create('pregunta_usos', function (Blueprint $t) {        // 5.4.13
            $t->foreignId('pregunta_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('grupo_id')->constrained()->cascadeOnDelete();
            $t->primary(['pregunta_id', 'actividad_id', 'grupo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pregunta_usos');
        Schema::dropIfExists('actividad_grupo');
        Schema::dropIfExists('actividades');
        Schema::dropIfExists('artefacto_pregunta');
        Schema::dropIfExists('preguntas');
        Schema::dropIfExists('artefactos');
    }
};
