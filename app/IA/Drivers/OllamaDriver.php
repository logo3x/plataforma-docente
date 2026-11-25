<?php

namespace App\IA\Drivers;

use App\IA\MotorIA;
use App\IA\MotorNoDisponible;
use App\IA\RespuestaIA;
use App\IA\SolicitudIA;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Ollama en el equipo del docente o en un servidor del programa (1.5). */
final class OllamaDriver implements MotorIA
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $modelo,
        private readonly int $timeout = 600,
    ) {}

    public function generar(SolicitudIA $s): RespuestaIA
    {
        $inicio = hrtime(true);

        $cuerpo = [
            'model' => $this->modelo,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $s->sistema],
                ['role' => 'user', 'content' => $s->usuario],
            ],
            'options' => ['num_predict' => $s->maxTokens],
        ];
        if ($s->esquemaJson !== null) {
            $cuerpo['format'] = $s->esquemaJson;   // salida estructurada con JSON Schema
        }

        try {
            $r = Http::timeout($this->timeout)->post(rtrim($this->baseUrl, '/').'/api/chat', $cuerpo)->throw();
        } catch (ConnectionException $e) {
            throw new MotorNoDisponible("Ollama no responde en {$this->baseUrl}.", previous: $e);
        }

        return new RespuestaIA(
            texto: (string) $r->json('message.content'),
            tokensEntrada: $r->json('prompt_eval_count'),
            tokensSalida: $r->json('eval_count'),
            duracionMs: (int) ((hrtime(true) - $inicio) / 1_000_000),
        );
    }

    public function disponible(): bool
    {
        try {
            return Http::timeout(3)->get(rtrim($this->baseUrl, '/').'/api/tags')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function esLocal(): bool
    {
        return true;
    }
}
