<?php

namespace App\Http\Controllers;

use App\Material\GeneradorDebate;
use App\Material\GeneradorMaterial;

/** Preguntas de debate para la clase (7.4): guía del docente, versión para estudiantes y modo proyector. */
class DebateController extends MaterialController
{
    protected function generador(): GeneradorMaterial
    {
        return app(GeneradorDebate::class);
    }

    protected function ruta(): string
    {
        return 'debates';
    }

    protected function nombre(): string
    {
        return 'Preguntas de debate';
    }
}
