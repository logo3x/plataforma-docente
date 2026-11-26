<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas que no son el examen digital (5.7, formato de entrega; 5.1 «trabajo con entrega de
 * archivos»): un PDF consolidado o un archivo libre, calificado a mano o con rúbrica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $t) {
            $t->string('formato_entrega', 10)->default('pdf')->after('es_grupal');   // pdf | libre (sin examen digital)
            $t->foreignId('rubrica_id')->nullable()->after('formato_entrega')->constrained('rubricas')->nullOnDelete();
        });

        Schema::table('entregas', function (Blueprint $t) {
            $t->string('tipo', 10)->default('examen')->after('actividad_id');        // examen | archivo
            $t->string('archivo_nombre')->nullable()->after('ruta');
            $t->string('archivo_mime', 100)->nullable()->after('archivo_nombre');
            $t->unsignedInteger('archivo_bytes')->nullable()->after('archivo_mime');
            $t->unsignedSmallInteger('paginas')->nullable()->after('archivo_bytes');
            $t->decimal('puntaje', 7, 4)->nullable()->after('estado_codigo');         // en la escala de la actividad
            $t->json('desglose')->nullable()->after('puntaje');                     // niveles de la rúbrica
            $t->text('comentario')->nullable()->after('desglose');
            $t->timestamp('calificada_en')->nullable()->after('comentario');
        });
    }

    public function down(): void
    {
        Schema::table('entregas', fn (Blueprint $t) => $t->dropColumn(['tipo', 'archivo_nombre', 'archivo_mime', 'archivo_bytes', 'paginas', 'puntaje', 'desglose', 'comentario', 'calificada_en']));
        Schema::table('actividades', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rubrica_id');
            $t->dropColumn('formato_entrega');
        });
    }
};
