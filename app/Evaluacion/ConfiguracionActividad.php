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

    /** Decisión 8: qué ve el estudiante de las verificaciones reservadas en su informe. */
    public const RESERVADAS_INFORME = [
        'completas' => 'Completas: cada una con su nombre, su resultado y su valor',
        'cantidad' => 'Solo la cantidad: cuántas hubo, cuántas superó y lo que sumaron, sin nombrarlas',
    ];

    /** 3.2: cómo sale la nota del examen cuando tiene parte escrita y parte práctica (retos de código). */
    public const CALCULO_NOTA = [
        'puntos' => 'Por puntos: la nota es la proporción de todos los puntos del examen',
        'partes' => 'Por partes: la parte escrita y la práctica se califican por separado y cada una pesa lo que usted fije',
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
            // En un parcial el banco se reutiliza (otra jornada, otro semestre): nombrar lo reservado lo gasta
            'reservadas_informe' => array_key_exists($c['reservadas_informe'] ?? '', self::RESERVADAS_INFORME) ? $c['reservadas_informe'] : ($tipo === 'parcial' ? 'cantidad' : 'completas'),
            'calculo_nota' => array_key_exists($c['calculo_nota'] ?? '', self::CALCULO_NOTA) ? $c['calculo_nota'] : 'puntos',
            'exigir_ensayo' => (bool) ($c['exigir_ensayo'] ?? false),
            'peso_escrita' => (int) ($c['peso_escrita'] ?? 50),
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
            'reservadas_informe' => ['nullable', 'in:'.implode(',', array_keys(self::RESERVADAS_INFORME))],
            'calculo_nota' => ['nullable', 'in:'.implode(',', array_keys(self::CALCULO_NOTA))],
            'exigir_ensayo' => ['nullable', 'boolean'],
            'peso_escrita' => ['nullable', 'required_if:calculo_nota,partes', 'integer', 'between:1,99'],
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
            'reservadas_informe' => $d['reservadas_informe'] ?? self::efectiva($a)['reservadas_informe'],     // si no llega, se conserva
            'calculo_nota' => $d['calculo_nota'] ?? self::efectiva($a)['calculo_nota'],
            'exigir_ensayo' => (bool) ($d['exigir_ensayo'] ?? false),
            'peso_escrita' => (int) ($d['peso_escrita'] ?? self::efectiva($a)['peso_escrita']),
        ] + ($a->configuracion ?? [])]);
    }
}
