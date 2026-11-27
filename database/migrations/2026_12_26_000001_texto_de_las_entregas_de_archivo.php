<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 6.11.1: el texto de un archivo entregado, para compararlo con los demás. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas', fn (Blueprint $t) => $t->longText('texto_extraido')->nullable());
    }

    public function down(): void
    {
        Schema::table('entregas', fn (Blueprint $t) => $t->dropColumn('texto_extraido'));
    }
};
