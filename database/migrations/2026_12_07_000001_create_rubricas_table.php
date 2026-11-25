<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rúbricas de la materia (7.4, generador «Rúbrica»): criterios con peso y niveles de desempeño.
 * Se asocian a las preguntas abiertas y se usan al calificarlas (6.7.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubricas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->string('titulo');
            $t->text('proposito')->nullable();                            // qué evalúa: actividad o pregunta
            $t->json('criterios');                                        // [{nombre, descripcion, peso, niveles:[{nombre, descripcion, porcentaje}]}]
            $t->foreignId('generacion_ia_id')->nullable()->constrained('generaciones_ia')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('preguntas', function (Blueprint $t) {
            $t->foreignId('rubrica_id')->nullable()->after('temario_item_id')->constrained('rubricas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('preguntas', fn (Blueprint $t) => $t->dropConstrainedForeignId('rubrica_id'));
        Schema::dropIfExists('rubricas');
    }
};
