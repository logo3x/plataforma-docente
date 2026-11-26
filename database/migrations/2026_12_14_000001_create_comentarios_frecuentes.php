<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentarios frecuentes de un clic (6.7.13) y borrador de comentario propuesto por la IA (8.5):
 * el borrador no le llega al estudiante hasta que el docente lo usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comentarios_frecuentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pregunta_id')->nullable()->constrained('preguntas')->cascadeOnDelete();   // null: toda la materia
            $t->string('texto', 300);
            $t->unsignedInteger('usos')->default(0);
            $t->timestamps();
        });

        Schema::table('respuestas', function (Blueprint $t) {
            $t->text('comentario_borrador')->nullable()->after('comentario');
        });
    }

    public function down(): void
    {
        Schema::table('respuestas', fn (Blueprint $t) => $t->dropColumn('comentario_borrador'));
        Schema::dropIfExists('comentarios_frecuentes');
    }
};
