<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 2.7 y 3.1.2: la estructura se edita después del alta; cada cambio queda registrado con cómo estaba. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_estructura', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->json('cambios');                          // frases legibles: «Corte 1: peso de 30 % a 25 %»
            $t->json('antes');                            // la estructura completa antes del cambio
            $t->unsignedInteger('notas_cargadas')->default(0);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_estructura');
    }
};
