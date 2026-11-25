<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sugerencia de la IA por tema (7.1.3): se muestra aparte y el docente la acepta o la descarta. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temario_items', fn (Blueprint $t) => $t->text('sugerencia')->nullable()->after('descripcion'));
    }

    public function down(): void
    {
        Schema::table('temario_items', fn (Blueprint $t) => $t->dropColumn('sugerencia'));
    }
};
