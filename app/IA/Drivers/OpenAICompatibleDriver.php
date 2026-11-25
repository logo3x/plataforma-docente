<?php

namespace App\IA\Drivers;

use App\IA\MotorIA;
use App\IA\MotorNoDisponible;
use App\IA\RespuestaIA;
use App\IA\SolicitudIA;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Servidores locales con API compatible con /v1/chat/completions:
 * LM Studio, llama.cpp server, vLLM. Se trata como motor local (1.5).
 */
final class OpenAICompatibleDriver implements MotorIA
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $modelo,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 600,
    ) {}

    public function generar(SolicitudIA $s): RespuestaIA
    {
        $inicio = hrtime(true);

        $cuerpo = [
            'model' => $this->modelo,
            'max_tokens' => $s->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $s->sistema],
                ['role' => 'user', 'content' => $s->usuario],
            ],
        ];
        if ($s->esquemaJson !== null) {
            $cuerpo['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'salida', 'strict' => true, 'schema' => $s->esquemaJson],
            ];
        }

        try {
            $r = $this->cliente($this->timeout)->post($this->url('/v1/chat/completions'), $cuerpo)->throw();
        } catch (ConnectionException $e) {
            throw new MotorNoDisponible("El servidor local no responde en {$this->baseUrl}.", previous: $e);
        }

        return new RespuestaIA(
            texto: (string) $r->json('choices.0.message.content'),
            tokensEntrada: $r->json('usage.prompt_tokens'),
            tokensSalida: $r->json('usage.completion_tokens'),
            duracionMs: (int) ((hrtime(true) - $inicio) / 1_000_000),
        );
    }

    public function disponible(): bool
    {
        try {
            return $this->cliente(3)->get($this->url('/v1/models'))->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function esLocal(): bool
    {
        return true;
    }

    private function cliente(int $timeout): PendingRequest
    {
        $c = Http::timeout($timeout);

        return $this->apiKey ? $c->withToken($this->apiKey) : $c;
    }

    private function url(string $ruta): string
    {
        return rtrim($this->baseUrl, '/').$ruta;
    }
}
