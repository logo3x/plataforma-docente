<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el docente concluyó al revisar un par de entregas parecidas (6.11.4). Es su registro:
 * no cambia ninguna nota ni genera sanción automática.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisiones_similitud', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('entrega_a_id')->constrained('entregas')->cascadeOnDelete();
            $t->foreignId('entrega_b_id')->constrained('entregas')->cascadeOnDelete();
            $t->string('conclusion', 20);                                 // sin_copia | posible_copia | copia
            $t->text('nota')->nullable();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
            $t->unique(['actividad_id', 'entrega_a_id', 'entrega_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisiones_similitud');
    }
};
