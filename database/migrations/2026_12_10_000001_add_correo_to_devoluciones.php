<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Envío del enlace personal por correo institucional (6.9.3) y su registro por estudiante (6.9.5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $t) {
            $t->string('correo_destino')->nullable()->after('entregada_en');      // a dónde se envió, tal como estaba ese día
            $t->timestamp('correo_en_cola_en')->nullable()->after('correo_destino');
            $t->timestamp('correo_enviado_en')->nullable()->after('correo_en_cola_en');
            $t->string('correo_error', 300)->nullable()->after('correo_enviado_en');
            $t->unsignedInteger('correos')->default(0)->after('correo_error');     // envíos exitosos
        });
    }

    public function down(): void
    {
        Schema::table('devoluciones', fn (Blueprint $t) => $t->dropColumn(['correo_destino', 'correo_en_cola_en', 'correo_enviado_en', 'correo_error', 'correos']));
    }
};
