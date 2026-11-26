<?php

namespace App\Evaluacion;

use App\Models\Artefacto;

/**
 * Opciones de aplicación de una actividad en un solo panel (5.7). Viven en la configuración del
 * examen, aparte del contenido: cambiarlas no obliga a rehacer las preguntas. Los valores por
 * defecto dependen del tipo de actividad, como pide el documento.
 */
final class ConfiguracionActividad
{
    public const NIVELES_AYUDA = [
        'completo' => 'Completo: ejecución, consola, casos visibles, verificación de sintaxis y mensajes explicados',
        'intermedio' => 'Intermedio: ejecución, consola y verificación de sintaxis, sin mensajes explicados',
        'minimo' => 'Mínimo: solo el estado de la ejecución y la consola',
    ];

    /** Valores efectivos: lo guardado y, si falta, el valor por defecto según el tipo. */
    public static function efectiva(Artefacto $a): array
    {
        $c = $a->configuracion ?? [];
        $tipo = $a->tipo;

        return [
            'instrucciones' => $c['instrucciones'] ?? null,
            'modo_desconectado' => (bool) ($c['modo_desconectado'] ?? $tipo === 'parcial'),
            'sonda' => $c['sonda'] ?? 'https://www.google.com/generate_204',
            'intervalo_sonda' => (int) ($c['intervalo_sonda'] ?? 20),
            'reloj_minutos' => isset($c['reloj_minutos']) && (int) $c['reloj_minutos'] > 0 ? (int) $c['reloj_minutos'] : null,
            'contar_salidas' => (bool) ($c['contar_salidas'] ?? true),
            'umbral_salidas' => (int) ($c['umbral_salidas'] ?? 3),
            'umbral_salidas_segundos' => (int) ($c['umbral_salidas_segundos'] ?? 60),
            'duracion_minima_minutos' => isset($c['duracion_minima_minutos']) && (int) $c['duracion_minima_minutos'] > 0 ? (int) $c['duracion_minima_minutos'] : null,
            'nivel_ayuda' => array_key_exists($c['nivel_ayuda'] ?? '', self::NIVELES_AYUDA) ? $c['nivel_ayuda'] : (in_array($tipo, ['taller', 'manual'], true) ? 'completo' : 'intermedio'),
            'tiempo_ejecucion_ms' => (int) ($c['tiempo_ejecucion_ms'] ?? 2000),
            'anexo' => (bool) ($c['anexo'] ?? false),
        ];
    }

    /** Reglas de validación del panel. */
    public static function reglas(): array
    {
        return [
            'instrucciones' => ['nullable', 'string', 'max:2000'],
            'modo_desconectado' => ['nullable', 'boolean'],
            'sonda' => ['nullable', 'url:https', 'max:300'],
            'intervalo_sonda' => ['required', 'integer', 'between:5,300'],
            'con_reloj' => ['nullable', 'boolean'],
            'reloj_minutos' => ['nullable', 'required_if:con_reloj,1', 'integer', 'between:1,600'],
            'contar_salidas' => ['nullable', 'boolean'],
            'umbral_salidas' => ['required', 'integer', 'between:1,100'],
            'umbral_salidas_segundos' => ['required', 'integer', 'between:5,3600'],
            'duracion_minima_minutos' => ['nullable', 'integer', 'between:1,600'],
            'nivel_ayuda' => ['required', 'in:'.implode(',', array_keys(self::NIVELES_AYUDA))],
            'tiempo_ejecucion_ms' => ['required', 'integer', 'between:500,20000'],
            'anexo' => ['nullable', 'boolean'],
        ];
    }

    /** Guarda lo validado sin tocar el resto de la configuración (por ejemplo, el valor). */
    public static function guardar(Artefacto $a, array $d): void
    {
        $a->update(['configuracion' => [
            'instrucciones' => filled($d['instrucciones'] ?? null) ? trim($d['instrucciones']) : null,
            'modo_desconectado' => (bool) ($d['modo_desconectado'] ?? false),
            'sonda' => $d['sonda'] ?? 'https://www.google.com/generate_204',
            'intervalo_sonda' => (int) $d['intervalo_sonda'],
            'reloj_minutos' => ! empty($d['con_reloj']) ? (int) $d['reloj_minutos'] : null,
            'contar_salidas' => (bool) ($d['contar_salidas'] ?? false),
            'umbral_salidas' => (int) $d['umbral_salidas'],
            'umbral_salidas_segundos' => (int) $d['umbral_salidas_segundos'],
            'duracion_minima_minutos' => ! empty($d['duracion_minima_minutos']) ? (int) $d['duracion_minima_minutos'] : null,
            'nivel_ayuda' => $d['nivel_ayuda'],
            'tiempo_ejecucion_ms' => (int) $d['tiempo_ejecucion_ms'],
            'anexo' => (bool) ($d['anexo'] ?? false),
        ] + ($a->configuracion ?? [])]);
    }
}
