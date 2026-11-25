<?php

namespace App\IA;

use App\IA\Drivers\AnthropicDriver;
use App\IA\Drivers\OllamaDriver;
use App\IA\Drivers\OpenAICompatibleDriver;
use App\Models\ConfigMotorIa;
use InvalidArgumentException;

/** Construye el driver a partir de la configuración guardada del motor. */
class ResolutorMotor
{
    public function para(ConfigMotorIa $config): MotorIA
    {
        return match ($config->driver) {
            'ollama' => new OllamaDriver($config->base_url, $config->modelo),
            'openai_compatible' => new OpenAICompatibleDriver($config->base_url, $config->modelo, $config->api_key),
            'anthropic' => new AnthropicDriver($config->modelo, $config->api_key),
            default => throw new InvalidArgumentException("Driver de IA desconocido: {$config->driver}."),
        };
    }
}
