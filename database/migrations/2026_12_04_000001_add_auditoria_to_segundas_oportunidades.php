<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.6 y 3.5: una segunda oportunidad es un evento que no se edita ni se borra. Si se registró por
 * error, se anula con su motivo y queda la traza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segundas_oportunidades', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('estudiante_id')->constrained()->nullOnDelete();
            $t->string('motivo', 300)->nullable()->after('fecha');
            $t->timestamp('anulada_en')->nullable()->after('motivo');
            $t->string('motivo_anulacion', 300)->nullable()->after('anulada_en');
        });
    }

    public function down(): void
    {
        Schema::table('segundas_oportunidades', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropColumn(['motivo', 'anulada_en', 'motivo_anulacion']);
        });
    }
};
