<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión pendiente 9: puntaje sugerido por IA para respuestas abiertas. La sugerencia queda aparte del
 * puntaje y conserva lo que el docente decidió con ella (aceptada, cambiada, descartada) para calibrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respuestas', fn (Blueprint $t) => $t->json('sugerencia')->nullable()->after('comentario_borrador'));
    }

    public function down(): void
    {
        Schema::table('respuestas', fn (Blueprint $t) => $t->dropColumn('sugerencia'));
    }
};
