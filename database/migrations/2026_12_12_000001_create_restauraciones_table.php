<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restauraciones de respaldos (3.1.10): de qué archivo salió cada periodo restaurado y la bitácora
 * original tal como venía. La bitácora del periodo nuevo nunca se reescribe con fechas pasadas:
 * lo anterior queda aquí, para consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restauraciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->char('sha256', 64);
            $t->unsignedTinyInteger('version');
            $t->string('periodo_original', 20);
            $t->timestamp('generado_en')->nullable();
            $t->json('resumen');
            $t->json('bitacora_original');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restauraciones');
    }
};
