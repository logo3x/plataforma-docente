<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo de errores frecuentes por lenguaje, mantenido por el docente (4.4.4, anexo A). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('errores_frecuentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('materia_id')->nullable()->constrained()->cascadeOnDelete();   // null = todas sus materias
            $t->string('lenguaje', 12);                                                    // javascript | html | sql | php
            $t->string('patron', 300);                                                     // texto con {x}, o expresión regular
            $t->boolean('es_regex')->default(false);
            $t->string('explicacion', 500);
            $t->string('revision', 500)->nullable();                                       // qué revisar
            $t->boolean('activo')->default(true);
            $t->boolean('base')->default(false);                                           // vino del anexo A
            $t->timestamps();
            $t->index(['user_id', 'lenguaje']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('catalogo_errores_en')->nullable();     // cuándo se sembró: borrar todo no lo vuelve a llenar
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('errores_frecuentes');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('catalogo_errores_en'));
    }
};
