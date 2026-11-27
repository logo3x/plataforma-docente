<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 6.2.3: en la modalidad II la práctica se entrega aparte y solo se registra su nota, en su propia columna. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $t) {
            $t->foreignId('practica_de_id')->nullable()->after('artefacto_id')->constrained('actividades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('actividades', function (Blueprint $t) {
            $t->dropConstrainedForeignId('practica_de_id');
        });
    }
};
