<?php

namespace App\Calificacion;

use App\Models\Actividad;
use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Política frente a las alertas (decisión pendiente 6). Las alertas informan y nunca sancionan por sí solas
 * (6.3). El docente define, por tipo, si la alerta pide su revisión y si el estudiante puede llegar a saber de
 * ella. Por defecto no sabe nada (6.9.4); con «tras revisión», el informe solo muestra lo que el docente
 * revisó y decidió contar, en un texto neutral y con su nota. Ninguna alerta cambia la nota.
 */
final class PoliticaAlertas
{
    /** tipo => [nombre, por qué importa, requiere revisión por defecto] */
    public const TIPOS = [
        'texto_identico' => ['Texto idéntico entre entregas', 'Respuestas o correcciones iguales palabra por palabra.', true],
        'duracion_anomala' => ['Duración corta', 'Entregó en menos del tiempo mínimo configurado.', true],
        'salidas' => ['Salidas de la ventana', 'Superó el umbral de salidas o de tiempo fuera.', true],
        'manipulado' => ['Identidad no coincide', 'La identidad visible del archivo no es la cifrada.', true],
        'version_no_coincide' => ['Versión distinta', 'Respondió una versión que no corresponde a su documento.', true],
        'reconexion' => ['Reconexión', 'El equipo recuperó internet en una actividad sin conexión.', true],
        'estudiante_en_dos_entregas' => ['En dos entregas', 'Aparece como integrante de dos entregas de la actividad.', true],
        'grupo_no_cuadra' => ['Otro grupo', 'La entrega es de un grupo distinto al de la actividad.', true],
        'integrante_desconocido' => ['Integrante que no está en la lista', 'Declaró un integrante que no está en el periodo.', true],
        'pegados' => ['Pegados de texto', 'Pegó texto en los editores.', false],
        'sin_cifrar' => ['Sin cifrar', 'El navegador del aula no permitió cifrar.', false],
        'cierre_automatico' => ['Cierre por tiempo', 'El examen se cerró al agotarse el tiempo.', false],
        'cierre_docente' => ['Cerrado por el docente', 'Finalización masiva de una sesión en vivo.', false],
    ];

    public const INFORMAR = ['nunca' => 'Nunca: es solo para usted', 'tras_revision' => 'Si usted lo decide al revisarla'];

    public const DECISIONES = ['sin_consecuencia' => 'Revisada: sin consecuencia', 'conversada' => 'Se conversó con el estudiante', 'ajuste' => 'Se ajustó la nota (en el registro)'];

    /** Lo que integra a todos los de la entrega; las demás son de un estudiante en particular. */
    private const PERSONALES = ['estudiante_en_dos_entregas', 'integrante_desconocido'];

    /** @return array<string, array{revisar: bool, informar: string}> */
    public function de(User $docente): array
    {
        $g = $docente->politica_alertas ?? [];

        return collect(self::TIPOS)->mapWithKeys(fn ($t, $tipo) => [$tipo => [
            'revisar' => (bool) ($g[$tipo]['revisar'] ?? $t[2]),
            'informar' => array_key_exists($g[$tipo]['informar'] ?? '', self::INFORMAR) ? $g[$tipo]['informar'] : 'nunca',
        ]])->all();
    }

    public function guardar(User $docente, array $d): void
    {
        $docente->forceFill(['politica_alertas' => collect(self::TIPOS)->mapWithKeys(fn ($t, $tipo) => [$tipo => [
            'revisar' => (bool) ($d[$tipo]['revisar'] ?? false),
            'informar' => array_key_exists($d[$tipo]['informar'] ?? '', self::INFORMAR) ? $d[$tipo]['informar'] : 'nunca',
        ]])->all()])->save();
    }

    /** Alertas de la actividad, una por tipo y entrega, separadas en por revisar, revisadas e informativas. */
    public function deActividad(Actividad $actividad, User $docente): array
    {
        $politica = $this->de($docente);
        $alertas = Alerta::query()->where('actividad_id', $actividad->id)->with('entrega.integrantes', 'estudiante', 'revisor')->latest()->get()
            ->unique(fn ($a) => $a->tipo.'|'.$a->entrega_id.'|'.(in_array($a->tipo, self::PERSONALES, true) ? $a->estudiante_id : ''))->values();
        $revisable = fn ($a) => $politica[$a->tipo]['revisar'] ?? false;

        return [
            'por_revisar' => $alertas->filter(fn ($a) => $revisable($a) && $a->revisada_en === null)->values(),
            'revisadas' => $alertas->whereNotNull('revisada_en')->values(),
            'informativas' => $alertas->filter(fn ($a) => ! $revisable($a) && $a->revisada_en === null)->values(),
            'politica' => $politica,
        ];
    }

    public function pendientes(Actividad $actividad, User $docente): int
    {
        return $this->deActividad($actividad, $docente)['por_revisar']->count();
    }

    /** El docente revisa: su decisión, su nota y si se le informa al estudiante (solo si la política lo permite). */
    public function revisar(Alerta $alerta, User $docente, string $decision, ?string $nota, bool $informar): void
    {
        $informar = $informar && ($this->de($docente)[$alerta->tipo]['informar'] ?? 'nunca') === 'tras_revision';
        // Revisar una alerta de la entrega es revisarla para todos sus integrantes
        Alerta::query()->where('actividad_id', $alerta->actividad_id)->where('tipo', $alerta->tipo)->where('entrega_id', $alerta->entrega_id)
            ->when(in_array($alerta->tipo, self::PERSONALES, true), fn ($q) => $q->where('estudiante_id', $alerta->estudiante_id))
            ->update(['revisada_en' => now(), 'revisada_por' => $docente->id, 'decision' => $decision,
                'nota_docente' => filled($nota) ? trim($nota) : null, 'informar' => $informar]);
    }

    /** Lo que el informe del estudiante muestra: solo lo revisado e informado, y si la política todavía lo permite. */
    public function paraInforme(?Entrega $entrega, Estudiante $e, User $docente): Collection
    {
        if ($entrega === null) {
            return collect();
        }
        $politica = $this->de($docente);

        return Alerta::query()->where('entrega_id', $entrega->id)->whereNotNull('revisada_en')->where('informar', true)->get()
            ->filter(fn ($a) => ($politica[$a->tipo]['informar'] ?? 'nunca') === 'tras_revision')
            ->filter(fn ($a) => ! in_array($a->tipo, self::PERSONALES, true) || $a->estudiante_id === $e->id)
            ->unique('tipo')->map(fn ($a) => ['texto' => self::textoNeutral($a), 'nota' => $a->nota_docente])->values();
    }

    /** Lo que pasó, sin juicio: el estudiante lee el hecho y la nota del docente, no una acusación. */
    public static function textoNeutral(Alerta $a): string
    {
        $d = $a->datos ?? [];

        return match ($a->tipo) {
            'salidas' => 'Durante el examen se registraron '.($d['cantidad'] ?? '?').' salidas de la ventana, '.($d['segundos'] ?? '?').' segundos en total.',
            'duracion_anomala' => 'La entrega se hizo en '.(($d['segundos'] ?? 0) < 90 ? 'menos de dos minutos' : round(($d['segundos'] ?? 0) / 60).' minutos')
                .', menos que el tiempo mínimo previsto para la actividad.',
            'texto_identico' => 'Una de sus respuestas coincide palabra por palabra con la de otra entrega.',
            'pegados' => 'Se pegó texto en el examen '.($d['cantidad'] ?? '').' vez/veces.',
            'manipulado' => 'La identidad del archivo entregado no coincidía con la registrada dentro del examen.',
            'version_no_coincide' => 'La versión del examen respondida no es la que corresponde a su documento.',
            'reconexion' => 'El equipo recuperó la conexión a internet durante un examen que debía hacerse sin conexión.',
            'estudiante_en_dos_entregas' => 'Usted aparece como integrante de dos entregas de esta actividad.',
            'grupo_no_cuadra' => 'La entrega llegó de un grupo distinto al de la actividad.',
            'integrante_desconocido' => 'La entrega declaró un integrante que no está en la lista del curso.',
            'sin_cifrar' => 'El navegador del equipo no permitió cifrar la entrega.',
            'cierre_automatico' => 'El examen se cerró al agotarse el tiempo.',
            'cierre_docente' => 'El docente finalizó el examen para todo el grupo.',
            default => 'La entrega tuvo una observación.',
        };
    }
}
