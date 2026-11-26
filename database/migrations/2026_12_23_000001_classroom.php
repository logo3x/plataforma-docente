<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Decisión pendiente 4: integración con Google Classroom (lectura de entregas y escritura de notas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_cuentas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('email')->nullable();
            $t->text('access_token');                 // cifrados con la llave de la aplicación
            $t->text('refresh_token')->nullable();
            $t->timestamp('expira_en')->nullable();
            $t->timestamps();
        });
        Schema::table('grupos', function (Blueprint $t) {
            $t->string('classroom_curso_id', 40)->nullable();
            $t->string('classroom_curso_nombre')->nullable();
        });
        Schema::table('estudiantes', fn (Blueprint $t) => $t->string('classroom_usuario_id', 40)->nullable()->index());
        Schema::table('actividades', function (Blueprint $t) {
            $t->string('classroom_tarea_id', 40)->nullable();
            $t->json('classroom_tarea')->nullable();  // título, puntos, enlace y si la creó esta aplicación
        });
        Schema::table('entregas', fn (Blueprint $t) => $t->string('classroom_entrega_id', 60)->nullable()->index());
        Schema::create('classroom_envios', function (Blueprint $t) {   // constancia de cada nota enviada
            $t->id();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('estudiante_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('entrega_classroom_id', 60);
            $t->decimal('nota', 7, 4);
            $t->decimal('puntos', 9, 4);
            $t->string('como', 10);                   // borrador | asignada
            $t->string('error', 300)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_envios');
        Schema::table('entregas', fn (Blueprint $t) => $t->dropColumn('classroom_entrega_id'));
        Schema::table('actividades', fn (Blueprint $t) => $t->dropColumn(['classroom_tarea_id', 'classroom_tarea']));
        Schema::table('estudiantes', fn (Blueprint $t) => $t->dropColumn('classroom_usuario_id'));
        Schema::table('grupos', fn (Blueprint $t) => $t->dropColumn(['classroom_curso_id', 'classroom_curso_nombre']));
        Schema::dropIfExists('google_cuentas');
    }
};
