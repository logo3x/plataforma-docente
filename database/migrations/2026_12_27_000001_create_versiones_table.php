<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.6: lo generado se versiona; cada edición guarda antes una foto de cómo estaba. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versiones', function (Blueprint $t) {
            $t->id();
            $t->morphs('versionable');                    // material, rúbrica, unidad didáctica o examen (banco)
            $t->unsignedInteger('numero');
            $t->string('titulo');
            $t->json('datos');                            // cómo estaba antes del cambio
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones');
    }
};
