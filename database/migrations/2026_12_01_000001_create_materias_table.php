<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materia (plantilla estable), temario y repositorio de contenido.
 * Requerimientos: 1.4, 2.4, 2.6, 7, 7.1, 8.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');
            $t->string('codigo', 30)->nullable();
            $t->string('nivel', 20)->default('universidad');            // universidad | colegio | otro
            $t->boolean('perfil_codigo')->default(false);               // 1.4: el código es opcional
            $t->string('modalidad_practica', 20)->default('ninguna');   // integrada | anexo | ninguna
            $t->json('plantilla_evaluacion')->nullable();               // B1: divisiones/componentes sugeridos
            $t->json('estilo')->nullable();                             // capa 2 del prompt (8.2)
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['user_id', 'codigo']);
        });

        Schema::create('temario_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('temario_items')->cascadeOnDelete();
            $t->string('tipo', 20);                                     // unidad | tema | resultado
            $t->string('titulo');
            $t->text('descripcion')->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->string('origen', 10)->default('manual');                // ia | manual (principio 1.2)
            $t->timestamps();
        });

        Schema::create('contenidos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('temario_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('titulo');
            $t->string('tipo', 30);                                     // plan_curricular, syllabus, presentacion...
            $t->string('ruta');                                         // disco privado, nunca público
            $t->string('mime', 100);
            $t->char('sha256', 64);
            $t->unsignedInteger('version')->default(1);
            $t->longText('texto_extraido')->nullable();                 // contexto para la IA
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contenidos');
        Schema::dropIfExists('temario_items');
        Schema::dropIfExists('materias');
    }
};
