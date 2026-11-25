<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dictado por periodo, divisiones del periodo, componentes, grupos y estudiantes.
 * Requerimientos: 2.6, 2.7, 2.8, 3.1, 3.2, 3.4, 9.1, 11.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('materia_id')->constrained()->cascadeOnDelete();
            $t->foreignId('origen_dictado_id')->nullable()->constrained('dictados')->nullOnDelete(); // 2.8
            $t->string('periodo', 20);                                   // "2026A", "2027-P1"
            $t->decimal('escala_min', 7, 4)->default(0);
            $t->decimal('escala_max', 7, 4)->default(5);
            $t->decimal('nota_aprobatoria', 7, 4)->default(3);
            $t->unsignedTinyInteger('decimales')->default(1);
            $t->string('redondeo', 10)->default('cercano');              // cercano | arriba | abajo
            $t->string('estado', 10)->default('abierto');                // abierto | cerrado
            $t->timestamps();
            $t->unique(['materia_id', 'periodo']);
        });

        // Cortes, periodos, bimestres... anidables (2.7)
        Schema::create('divisiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('divisiones')->cascadeOnDelete();
            $t->string('nombre');
            $t->decimal('peso', 7, 4);                                   // fracción 0..1 dentro del padre
            $t->date('fecha_inicio')->nullable();
            $t->date('fecha_fin')->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('componentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('division_id')->constrained('divisiones')->cascadeOnDelete();
            $t->string('nombre');
            $t->decimal('peso', 7, 4);
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('grupos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');
            $t->string('jornada', 20)->nullable();
            $t->timestamps();
            $t->unique(['dictado_id', 'nombre']);
        });

        Schema::create('estudiantes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->string('documento', 20);
            $t->string('apellidos');
            $t->string('nombres');
            $t->unsignedTinyInteger('numero_matriculas')->nullable();
            $t->unsignedTinyInteger('numero_convocatorias')->nullable();
            $t->text('observacion')->nullable();
            $t->string('email')->nullable();
            $t->string('telefono', 30)->nullable();
            $t->boolean('revisar_nombre')->default(false);              // 3.4.5: fila sin coma
            $t->timestamps();
            $t->unique(['dictado_id', 'documento']);                    // 9.1: único por dictado
        });

        // 11.5: un estudiante puede estar en dos grupos sin duplicarse
        Schema::create('grupo_estudiante', function (Blueprint $t) {
            $t->foreignId('grupo_id')->constrained()->cascadeOnDelete();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->boolean('activo')->default(true);                       // 3.4.6: se marca, no se borra
            $t->primary(['grupo_id', 'estudiante_id']);
        });

        // 7.1.4 lista de chequeo y alcance por grupo
        Schema::create('avances_temario', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dictado_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grupo_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('temario_item_id')->constrained()->cascadeOnDelete();
            $t->date('cubierto_en');
            $t->unique(['dictado_id', 'grupo_id', 'temario_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avances_temario');
        Schema::dropIfExists('grupo_estudiante');
        Schema::dropIfExists('estudiantes');
        Schema::dropIfExists('grupos');
        Schema::dropIfExists('componentes');
        Schema::dropIfExists('divisiones');
        Schema::dropIfExists('dictados');
    }
};
