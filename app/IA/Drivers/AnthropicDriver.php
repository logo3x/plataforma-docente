<?php

namespace App\IA\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use App\IA\MotorIA;
use App\IA\MotorNoDisponible;
use App\IA\RespuestaIA;
use App\IA\RespuestaRechazada;
use App\IA\SolicitudIA;

/**
 * Motor en la nube con la API de Claude, usando el SDK oficial de PHP.
 * Es un motor NO local: GestorIA le impide recibir datos de estudiantes sin autorización.
 */
final class AnthropicDriver implements MotorIA
{
    private readonly Client $cliente;

    public function __construct(
        private readonly string $modelo = 'claude-opus-5-5',
        ?string $apiKey = null,
        private readonly string $esfuerzo = 'medium',
    ) {
        $this->cliente = new Client(apiKey: $apiKey);
    }

    public function generar(SolicitudIA $s): RespuestaIA
    {
        $inicio = hrtime(true);

        $outputConfig = ['effort' => $this->esfuerzo];
        if ($s->esquemaJson !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $s->esquemaJson];
        }

        try {
            // Reintento del lado del servidor en otro modelo de Claude si este declina por política.
            // No contradice la regla 1.5.4: no se cambia de motor ni sale a otro proveedor.
            $mensaje = $this->cliente->beta->messages->create(
                maxTokens: $s->maxTokens,
                messages: [['role' => 'user', 'content' => $s->usuario]],
                model: $this->modelo,
                fallbacks: 'default',
                outputConfig: $outputConfig,
                system: $s->sistema,
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIConnectionException|AuthenticationException $e) {
            throw new MotorNoDisponible('No fue posible conectar con el servicio de IA en la nube.', previous: $e);
        } catch (APIStatusException $e) {
            throw new MotorNoDisponible("El servicio de IA respondió con error ({$e->type?->value}).", previous: $e);
        }

        if ($mensaje->stopReason === 'refusal') {
            throw new RespuestaRechazada('El modelo declinó generar este contenido.');
        }

        $texto = '';
        foreach ($mensaje->content as $bloque) {
            if ($bloque->type === 'text') {
                $texto .= $bloque->text;
            }
        }

        return new RespuestaIA(
            texto: $texto,
            tokensEntrada: $mensaje->usage->inputTokens,
            tokensSalida: $mensaje->usage->outputTokens,
            duracionMs: (int) ((hrtime(true) - $inicio) / 1_000_000),
        );
    }

    /** No se hace ping para no generar costo; los errores de conexión se reportan al generar. */
    public function disponible(): bool
    {
        return true;
    }

    public function esLocal(): bool
    {
        return false;
    }
}
