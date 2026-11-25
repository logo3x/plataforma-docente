<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades didácticas (7.4, generador «Unidad didáctica»): la secuencia de sesiones de una unidad
 * del temario, con objetivos, momentos de clase, recursos y evaluación. Pertenecen a la materia y
 * se reutilizan entre dictados (2.6, 7.3.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_didacticas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('temario_item_id')->nullable()->constrained('temario_items')->nullOnDelete();
            $t->string('titulo');
            $t->json('contenido');                                        // formato «plataforma-unidad» v1, normalizado
            $t->foreignId('generacion_ia_id')->nullable()->constrained('generaciones_ia')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_didacticas');
    }
};
