<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 2.8.3: lo reutilizado conserva el vínculo con su origen. */
return new class extends Migration
{
    private const TABLAS = ['rubricas', 'unidades_didacticas', 'materiales'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->foreignId('origen_id')->nullable()->constrained($tabla)->nullOnDelete());
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropConstrainedForeignId('origen_id'));
        }
    }
};
