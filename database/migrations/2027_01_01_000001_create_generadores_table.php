<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.4.3: catálogo extensible. Un generador nuevo es configuración, no código: nombre, campos propios
 * e instrucciones en lenguaje natural con {{campo}}. La salida es siempre un documento por secciones.
 * 8.4.5: el que funcionó se marca y queda en la biblioteca, para usarlo en cualquier materia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generadores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('nombre', 80);
            $t->string('descripcion', 300)->nullable();
            $t->text('instrucciones');
            $t->json('campos');                          // [{clave, etiqueta, defecto}]
            $t->string('codigo', 12)->default('no');     // no | ejemplos | interactivo
            $t->string('origen', 20)->nullable();        // clave del de fábrica del que se copió
            $t->boolean('funciono')->default(false);
            $t->string('nota_funciono', 300)->nullable();
            $t->timestamps();
        });

        Schema::table('plantillas_prompt', function (Blueprint $t) {
            $t->boolean('funciono')->default(false);
            $t->string('nota_funciono', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plantillas_prompt', fn (Blueprint $t) => $t->dropColumn(['funciono', 'nota_funciono']));
        Schema::dropIfExists('generadores');
    }
};
