<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Decisión pendiente 6: política frente a las alertas, revisión del docente y lo que se informa al estudiante. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alertas', function (Blueprint $t) {
            $t->foreignId('revisada_por')->nullable()->after('revisada_en')->constrained('users')->nullOnDelete();
            $t->string('decision', 20)->nullable()->after('revisada_por');      // sin_consecuencia | conversada | ajuste
            $t->text('nota_docente')->nullable()->after('decision');
            $t->boolean('informar')->default(false)->after('nota_docente');    // el docente decidió contárselo al estudiante
        });
        Schema::table('users', fn (Blueprint $t) => $t->json('politica_alertas')->nullable());
    }

    public function down(): void
    {
        Schema::table('alertas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('revisada_por');
            $t->dropColumn(['decision', 'nota_docente', 'informar']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('politica_alertas'));
    }
};
