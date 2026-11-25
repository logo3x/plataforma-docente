<?php

namespace App\Temario;

use App\IA\ConstructorPrompt;
use App\IA\ExtractorJson;
use App\IA\GestorIA;
use App\IA\SolicitudIA;
use App\Models\ConfigMotorIa;
use App\Models\Contenido;
use App\Models\GeneracionIa;
use App\Models\Grupo;
use App\Models\Materia;
use App\Models\TemarioItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Plan curricular y temario (7.1): extracción con IA como borrador, sugerencias de organización,
 * y el alcance real por grupo que alimenta la capa 2 del prompt.
 */
final class ServicioTemario
{
    public function __construct(private readonly ConstructorPrompt $prompts, private readonly GestorIA $ia) {}

    /** 7.1.2: la IA lee el plan y propone unidades, temas y resultados. Queda como borrador. */
    public function extraer(User $docente, Materia $materia, Contenido $plan, ?ConfigMotorIa $motor = null): array
    {
        abort_unless($plan->materia_id === $materia->id, 403);
        @set_time_limit(config('ia.tiempo_maximo'));

        $p = $this->prompts->construir($docente, $materia, $this->dictado($materia), 'temario', [], collect([$plan]));
        $this->ia->generar($docente, new SolicitudIA($p['sistema'], $p['usuario'], soloJson: true, maxTokens: 8000), $motor, [
            'plantillas' => $p['plantillas'], 'contenidos' => $p['contenidos'], 'materia_id' => $materia->id, 'tarea' => 'temario',
        ]);

        return $this->guardarBorrador($this->ia->ultima);
    }

    /** @return array{generacion: GeneracionIa, errores: array<int, string>} */
    public function guardarBorrador(GeneracionIa $g, ?string $texto = null): array
    {
        try {
            $t = ExtractorJson::extraer($texto ?? $g->respuesta);
        } catch (InvalidArgumentException $e) {
            $g->update(['borrador' => $texto ?? $g->respuesta]);

            return ['generacion' => $g, 'errores' => [$e->getMessage()]];
        }
        $g->update(['borrador' => json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]);

        return ['generacion' => $g, 'errores' => $this->validar($t)];
    }

    /** @return array<int, string> */
    public function validar(array $t): array
    {
        $unidades = $t['unidades'] ?? null;
        if (! is_array($unidades) || $unidades === []) {
            return ['No hay unidades en el temario.'];
        }
        $e = [];
        foreach (array_values($unidades) as $i => $u) {
            if (blank($u['titulo'] ?? null)) {
                $e[] = 'La unidad '.($i + 1).' no tiene título.';
            }
            foreach (array_values((array) ($u['temas'] ?? [])) as $j => $tema) {
                if (blank(is_array($tema) ? ($tema['titulo'] ?? null) : $tema)) {
                    $e[] = 'El tema '.($j + 1).' de la unidad '.($i + 1).' no tiene título.';
                }
            }
        }

        return $e;
    }

    /**
     * Aplica el borrador aprobado. «reemplazar» solo se permite si ningún dictado ha marcado avance,
     * para no borrar la lista de chequeo de un periodo en curso.
     */
    public function aplicar(GeneracionIa $g, string $modo): int
    {
        $t = json_decode((string) $g->borrador, true);
        if (! is_array($t) || $this->validar($t) !== []) {
            throw new InvalidArgumentException('El borrador del temario no es válido.');
        }
        $materia = $g->materia;

        return DB::transaction(function () use ($t, $materia, $modo, $g) {
            if ($modo === 'reemplazar') {
                if (DB::table('avances_temario')->whereIn('temario_item_id', $materia->temario()->pluck('id'))->exists()) {
                    throw new InvalidArgumentException('Ya hay temas marcados como vistos: agregue en lugar de reemplazar, o edite el temario a mano.');
                }
                TemarioItem::query()->where('materia_id', $materia->id)->delete();
            }
            $orden = (int) TemarioItem::query()->where('materia_id', $materia->id)->whereNull('parent_id')->max('orden');
            $creados = 0;
            foreach ($t['unidades'] as $u) {
                $unidad = $this->crear($materia, null, 'unidad', $u['titulo'], $u['descripcion'] ?? null, ++$orden, 'ia');
                $n = 0;
                foreach ((array) ($u['temas'] ?? []) as $tema) {
                    $this->crear($materia, $unidad->id, 'tema', is_array($tema) ? $tema['titulo'] : $tema, is_array($tema) ? ($tema['descripcion'] ?? null) : null, ++$n, 'ia');
                    $creados++;
                }
                foreach ((array) ($u['resultados'] ?? []) as $r) {
                    $this->crear($materia, $unidad->id, 'resultado', is_array($r) ? ($r['titulo'] ?? '') : $r, null, ++$n, 'ia');
                }
            }
            $g->update(['estado' => 'aprobado']);

            return $creados;
        });
    }

    /** 7.1.3: sugerencias de organización por tema. Se guardan aparte; el docente las acepta o descarta. */
    public function sugerir(User $docente, Materia $materia, ?ConfigMotorIa $motor = null): int
    {
        $temas = $materia->temario()->where('tipo', 'tema')->with('padre')->get();
        if ($temas->isEmpty()) {
            throw new InvalidArgumentException('Primero arme el temario.');
        }
        @set_time_limit(config('ia.tiempo_maximo'));

        $p = $this->prompts->construir($docente, $materia, $this->dictado($materia), 'sugerencias', [
            'temario' => $temas->map(fn ($t) => "{$t->id}: {$t->padre?->titulo} › {$t->titulo}")->join("\n"),
            'materiales' => $materia->contenidos()->get()->map(fn ($c) => "- {$c->titulo} ({$c->tipo})")->join("\n") ?: '(repositorio vacío)',
        ], collect());
        $this->ia->generar($docente, new SolicitudIA($p['sistema'], $p['usuario'], soloJson: true, maxTokens: 8000), $motor, [
            'plantillas' => $p['plantillas'], 'materia_id' => $materia->id, 'tarea' => 'sugerencias',
        ]);

        $datos = ExtractorJson::extraer($this->ia->ultima->respuesta);
        $n = 0;
        foreach ((array) ($datos['sugerencias'] ?? []) as $s) {
            $tema = $temas->firstWhere('id', (int) ($s['id'] ?? 0));      // solo temas de esta materia
            if ($tema && filled($s['sugerencia'] ?? null)) {
                $tema->update(['sugerencia' => Str::limit(trim($s['sugerencia']), 1500)]);
                $n++;
            }
        }
        $this->ia->ultima->update(['estado' => 'aprobado', 'borrador' => json_encode($datos, JSON_UNESCAPED_UNICODE)]);

        return $n;
    }

    /**
     * 7.1.5: el alcance real de un grupo, para la capa 2 del prompt. Si no hay temario, null
     * (se usa lo que el docente escribió a mano en el estilo de la materia).
     */
    public function alcance(Materia $materia, ?Grupo $grupo): ?string
    {
        $temas = $materia->temario()->where('tipo', 'tema')->get();
        if ($temas->isEmpty() || $grupo === null) {
            return null;
        }
        $vistos = DB::table('avances_temario')->where('grupo_id', $grupo->id)->pluck('temario_item_id')->all();
        [$si, $no] = $temas->partition(fn ($t) => in_array($t->id, $vistos, true));

        return "Grupo {$grupo->nombre}. Ya vio: ".($si->pluck('titulo')->join(', ') ?: 'ningún tema todavía')
            .'. Aún no ha visto (no evaluar): '.($no->pluck('titulo')->join(', ') ?: 'nada; el temario está completo').'.';
    }

    /** @return Collection<int, TemarioItem> unidades con sus hijos, en orden */
    public function arbol(Materia $materia): Collection
    {
        return $materia->temario()->whereNull('parent_id')->with(['hijos' => fn ($q) => $q->orderBy('orden')])->get();
    }

    private function crear(Materia $m, ?int $padre, string $tipo, string $titulo, ?string $desc, int $orden, string $origen): TemarioItem
    {
        return TemarioItem::query()->create([
            'materia_id' => $m->id, 'parent_id' => $padre, 'tipo' => $tipo,
            'titulo' => Str::limit(trim($titulo), 250, ''), 'descripcion' => $desc ? Str::limit(trim($desc), 1000) : null,
            'orden' => $orden, 'origen' => $origen,
        ]);
    }

    private function dictado(Materia $materia)
    {
        return $materia->dictados()->latest()->firstOrFail();
    }
}
