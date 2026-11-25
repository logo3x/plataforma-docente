<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de prompt (3 capas), motores de IA, trazabilidad de generaciones y claves de cifrado.
 * Requerimientos: 1.5, 7.4, 7.7, 8, A2 de la revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_prompt', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('materia_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('capa', 10);                                      // contexto | materia | tarea
            $t->string('clave', 50);                                     // quiz, rubrica... (catálogo 7.4)
            $t->string('nombre');
            $t->longText('cuerpo');                                      // con {{variables}}
            $t->unsignedInteger('version');
            $t->boolean('activa')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'materia_id', 'capa', 'clave', 'version']);
        });

        Schema::create('motores_ia', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');
            $t->string('driver', 30);                                    // ollama | openai_compatible | anthropic
            $t->string('base_url')->nullable();
            $t->string('modelo');
            $t->boolean('es_local');
            $t->text('api_key')->nullable();                             // cifrado
            $t->boolean('predeterminado')->default(false);
            $t->timestamps();
        });

        Schema::create('generaciones_ia', function (Blueprint $t) {
            $t->id();
            $t->foreignId('motor_ia_id')->constrained('motores_ia')->restrictOnDelete();
            $t->nullableMorphs('resultado');
            $t->json('plantillas')->nullable();
            $t->json('contenidos_contexto')->nullable();
            $t->longText('prompt_final');
            $t->longText('respuesta');
            $t->boolean('contiene_datos_estudiantes')->default(false);
            $t->unsignedInteger('tokens_entrada')->nullable();
            $t->unsignedInteger('tokens_salida')->nullable();
            $t->unsignedInteger('duracion_ms')->nullable();
            $t->timestamps();
        });

        Schema::create('claves_cifrado', function (Blueprint $t) {       // un par por dictado
            $t->id();
            $t->foreignId('dictado_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('publica_jwk');
            $t->text('privada_jwk');                                     // cifrado
            $t->timestamp('rotada_en')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claves_cifrado');
        Schema::dropIfExists('generaciones_ia');
        Schema::dropIfExists('motores_ia');
        Schema::dropIfExists('plantillas_prompt');
    }
};
