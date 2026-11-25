<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material de clase producido por los generadores de la categoría «Material» y «Ayuda al alumno»
 * del catálogo (7.4): por ahora ejemplos prácticos; después preguntas de debate y vocabulario.
 * Pertenece a la materia y se reutiliza entre dictados (7.3.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->string('tipo', 20);                                       // ejemplos
            $t->string('titulo');
            $t->json('contenido');                                        // formato del tipo, normalizado
            $t->foreignId('generacion_ia_id')->nullable()->constrained('generaciones_ia')->nullOnDelete();
            $t->timestamps();
            $t->index(['materia_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiales');
    }
};
