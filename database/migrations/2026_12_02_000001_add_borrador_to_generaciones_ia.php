<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El borrador editable de lo que generó la IA: nada se aplica sin aprobación del docente (1.2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generaciones_ia', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $t->foreignId('materia_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $t->string('tarea', 40)->default('banco')->after('materia_id');
            $t->longText('borrador')->nullable()->after('respuesta');
            $t->string('estado', 15)->default('borrador')->after('borrador');   // borrador | aprobado | descartado
        });
    }

    public function down(): void
    {
        Schema::table('generaciones_ia', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropConstrainedForeignId('materia_id');
            $t->dropColumn(['tarea', 'borrador', 'estado']);
        });
    }
};
