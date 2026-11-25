<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas, respuestas por ítem, notas, auditoría, segundas oportunidades y alertas.
 * Requerimientos: 3.1.6, 3.5, 3.6, 6.1, 6.3, 6.4, 6.6, 6.7, 6.8, 6.11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('subida_por_id')->nullable()->constrained('estudiantes')->nullOnDelete();
            $t->string('sigla_detectada', 40)->nullable();
            $t->uuid('artefacto_uuid_detectado')->nullable();            // actividad equivocada (6.1.4)
            $t->string('version', 5)->nullable();
            $t->string('ruta');
            $t->char('sha256', 64);
            $t->boolean('cifrada')->default(true);
            $t->json('sesion')->nullable();                              // salidas con duración, pegados...
            $t->unsignedInteger('duracion_seg')->nullable();
            $t->string('cierre', 15)->nullable();                        // manual | automatico
            $t->string('estado_codigo', 15)->default('no_aplica');       // no_aplica | pendiente | calificado
            $t->timestamp('procesada_en')->nullable();
            $t->timestamps();
            $t->unique(['actividad_id', 'sha256']);
        });

        Schema::create('entrega_estudiante', function (Blueprint $t) {   // 6.8
            $t->foreignId('entrega_id')->constrained()->cascadeOnDelete();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->primary(['entrega_id', 'estudiante_id']);
        });

        Schema::create('respuestas', function (Blueprint $t) {           // 6.4, 6.7
            $t->id();
            $t->foreignId('entrega_id')->constrained()->cascadeOnDelete();
            $t->foreignId('artefacto_pregunta_id')->constrained('artefacto_pregunta')->cascadeOnDelete();
            $t->json('respuesta');
            $t->decimal('puntaje', 7, 4)->nullable();                    // NULL = sin calificar
            $t->decimal('puntaje_max', 7, 4);
            $t->string('calificado_por', 15)->nullable();                // servidor | navegador | docente
            $t->json('desglose')->nullable();
            $t->text('comentario')->nullable();
            $t->unique(['entrega_id', 'artefacto_pregunta_id']);
        });

        Schema::create('notas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->decimal('valor', 7, 4)->nullable();                      // NULL = vacía (3.1.6)
            $t->string('origen', 15);       // digitada | archivo | calificador | grupal | migracion
            $t->foreignId('entrega_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('ajuste_individual')->default(false);
            $t->text('comentario')->nullable();
            $t->timestamps();
            $t->unique(['actividad_id', 'estudiante_id']);
        });

        // 3.5: bitácora de solo inserción. Sin llaves foráneas a propósito: la bitácora debe
        // sobrevivir al borrado de la nota o de la actividad (9.1), y un ON DELETE CASCADE o
        // SET NULL chocaría con los triggers de solo lectura.
        Schema::create('nota_cambios', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('nota_id')->index();
            $t->unsignedBigInteger('actividad_id')->index();
            $t->unsignedBigInteger('estudiante_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('valor_anterior', 7, 4)->nullable();
            $t->decimal('valor_nuevo', 7, 4)->nullable();
            $t->string('origen', 30);
            $t->string('motivo')->nullable();
            $t->uuid('operacion_id');                                    // deshacer por operación (3.1.9)
            $t->timestamp('created_at')->useCurrent();
            $t->index('operacion_id');
        });

        Schema::create('segundas_oportunidades', function (Blueprint $t) { // 3.6
            $t->id();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->morphs('ambito');                                        // Actividad, Division o Dictado
            $t->string('tipo', 15);                                      // supletorio | habilitacion | recuperacion
            $t->decimal('valor', 7, 4);
            $t->string('regla', 10);                                     // reemplaza | promedia | tope
            $t->decimal('tope', 7, 4)->nullable();
            $t->date('fecha');
            $t->timestamps();
        });

        Schema::create('alertas', function (Blueprint $t) {              // 6.3
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('entrega_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('estudiante_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('tipo', 40);
            $t->json('datos')->nullable();
            $t->timestamp('revisada_en')->nullable();
            $t->timestamps();
        });

        $this->protegerBitacora();
    }

    /**
     * Triggers que impiden editar o borrar la bitácora aunque se use DB::table().
     */
    private function protegerBitacora(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER nota_cambios_no_update BEFORE UPDATE ON nota_cambios FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bitacora de notas es de solo lectura'");
            DB::unprepared("CREATE TRIGGER nota_cambios_no_delete BEFORE DELETE ON nota_cambios FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bitacora de notas es de solo lectura'");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER nota_cambios_no_update BEFORE UPDATE ON nota_cambios
                BEGIN SELECT RAISE(ABORT, 'La bitacora de notas es de solo lectura'); END");
            DB::unprepared("CREATE TRIGGER nota_cambios_no_delete BEFORE DELETE ON nota_cambios
                BEGIN SELECT RAISE(ABORT, 'La bitacora de notas es de solo lectura'); END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS nota_cambios_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS nota_cambios_no_delete');
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('segundas_oportunidades');
        Schema::dropIfExists('nota_cambios');
        Schema::dropIfExists('notas');
        Schema::dropIfExists('respuestas');
        Schema::dropIfExists('entrega_estudiante');
        Schema::dropIfExists('entregas');
    }
};
