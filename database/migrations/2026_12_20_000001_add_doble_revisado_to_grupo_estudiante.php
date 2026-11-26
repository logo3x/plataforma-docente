<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Decisión pendiente 5: el docente revisó que el estudiante siga en dos grupos y no se le vuelve a avisar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grupo_estudiante', fn (Blueprint $t) => $t->timestamp('doble_revisado_en')->nullable());
    }

    public function down(): void
    {
        Schema::table('grupo_estudiante', fn (Blueprint $t) => $t->dropColumn('doble_revisado_en'));
    }
};
