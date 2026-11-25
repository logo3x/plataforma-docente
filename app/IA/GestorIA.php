<?php

namespace App\IA;

use App\Models\ConfigMotorIa;
use App\Models\GeneracionIa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Punto único de llamada a la IA. Aplica las reglas de la sección 1.5:
 *  - con datos de estudiantes, solo motor local salvo autorización explícita (1.5.3);
 *  - si el motor no está disponible, avisa y NUNCA cae en silencio a otro motor (1.5.4);
 *  - registra cada generación para trazabilidad (7.7, 8.4.6).
 */
class GestorIA
{
    public function __construct(private readonly ResolutorMotor $resolutor) {}

    /**
     * @param  array<string, mixed>  $trazabilidad  ['plantillas' => [...], 'contenidos' => [...], 'resultado' => Model]
     */
    public function generar(User $docente, SolicitudIA $solicitud, ?ConfigMotorIa $config = null, array $trazabilidad = []): RespuestaIA
    {
        $config ??= ConfigMotorIa::query()
            ->where('user_id', $docente->id)
            ->where('predeterminado', true)
            ->first();

        if ($config === null) {
            throw new MotorNoDisponible('No hay un motor de IA configurado. Configure uno en Ajustes.');
        }

        $motor = $this->resolutor->para($config);

        if ($solicitud->contieneDatosEstudiantes && ! $motor->esLocal()
            && ! config('ia.permitir_nube_con_datos_estudiantes')) {
            throw new MotorNoPermitido(
                'Esta tarea procesa trabajo de estudiantes y el motor configurado es en la nube. '
                .'Configure un motor local o autorice el uso de la nube en Ajustes.'
            );
        }

        if (! $motor->disponible()) {
            throw new MotorNoDisponible("El motor «{$config->nombre}» no responde. No se usó ningún otro motor.");
        }

        $respuesta = $motor->generar($solicitud);

        $resultado = $trazabilidad['resultado'] ?? null;

        GeneracionIa::query()->create([
            'motor_ia_id' => $config->id,
            'resultado_type' => $resultado instanceof Model ? $resultado->getMorphClass() : null,
            'resultado_id' => $resultado instanceof Model ? $resultado->getKey() : null,
            'plantillas' => $trazabilidad['plantillas'] ?? null,
            'contenidos_contexto' => $trazabilidad['contenidos'] ?? null,
            'prompt_final' => $solicitud->sistema."\n\n---\n\n".$solicitud->usuario,
            'respuesta' => $respuesta->texto,
            'contiene_datos_estudiantes' => $solicitud->contieneDatosEstudiantes,
            'tokens_entrada' => $respuesta->tokensEntrada,
            'tokens_salida' => $respuesta->tokensSalida,
            'duracion_ms' => $respuesta->duracionMs,
        ]);

        return $respuesta;
    }
}
