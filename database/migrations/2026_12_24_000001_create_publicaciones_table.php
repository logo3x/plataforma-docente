<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Publicación institucional del material (7.8, decisión pendiente 2): qué se publicó, en qué formato y con qué ficha. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_id')->constrained('materiales')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('formato', 10);                 // html | scorm | docx
            $t->unsignedInteger('version');
            $t->json('ficha');
            $t->char('sha256', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicaciones');
    }
};
