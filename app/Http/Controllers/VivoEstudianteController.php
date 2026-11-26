<?php

namespace App\Http\Controllers;

use App\Evaluacion\GeneradorExamen;
use App\Evaluacion\SesionEnVivo;
use App\Models\SesionVivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Lado del estudiante de la sesión en vivo (sección 13): sin cuenta en la plataforma. El código de la sesión
 * abre el examen y cada examen recibe al unirse su propia credencial para el latido y la entrega.
 */
class VivoEstudianteController extends Controller
{
    public function __construct(private readonly SesionEnVivo $vivo) {}

    /** Para quien escribe la dirección corta: un campo para el código. */
    public function entrar(Request $request): Response|RedirectResponse
    {
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('codigo')));
        if ($codigo !== '') {
            return SesionVivo::query()->where('codigo', $codigo)->whereNull('cerrada_en')->exists()
                ? redirect()->route('vivo.examen', $codigo)
                : response()->view('vivo.entrar', ['error' => 'Ese código no corresponde a una sesión abierta. Revíselo con el docente.', 'codigo' => $codigo], 404);
        }

        return response()->view('vivo.entrar', ['error' => null, 'codigo' => '']);
    }

    public function examen(string $codigo, GeneradorExamen $generador): Response
    {
        $sesion = $this->sesion($codigo);
        abort_unless($sesion->abierta(), 410, 'La sesión ya terminó. Pregúntele al docente.');

        return response($generador->html($sesion->actividad, banco: $sesion->banco, vivo: $sesion), 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function unirse(Request $request, string $codigo): JsonResponse
    {
        $d = $request->validate(['documento' => ['required', 'string', 'max:30'], 'nombre' => ['required', 'string', 'max:200'],
            'version' => ['nullable', 'string', 'max:5'], 'total' => ['nullable', 'integer', 'min:0']]);
        try {
            $r = $this->vivo->unirse($this->sesion($codigo), $d['documento'], $d['nombre'], $d['version'] ?? null, (int) ($d['total'] ?? 0));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['clave' => $r['clave']]);
    }

    public function latido(Request $request, string $codigo): JsonResponse
    {
        return $this->conParticipante($request, $codigo, fn ($p) => response()->json($this->vivo->latido($p, $request->only('respondidas', 'total', 'salidas', 'segundos_fuera', 'instantanea'))));
    }

    public function entrega(Request $request, string $codigo): JsonResponse
    {
        $request->validate(['sobre' => ['required', 'string', 'max:2000000']]);

        return $this->conParticipante($request, $codigo, function ($p) use ($request) {
            $r = $this->vivo->entregar($p, $request->input('sobre'));

            return $r['estado'] === 'procesada'
                ? response()->json(['estado' => 'recibida'])
                : response()->json(['message' => preg_replace('/^en vivo \d+: /', '', $r['mensaje'])], 422);
        });
    }

    private function conParticipante(Request $request, string $codigo, callable $hacer): JsonResponse
    {
        $sesion = $this->sesion($codigo);
        try {
            $p = $this->vivo->participante($sesion, $request->input('clave'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
        try {
            return $hacer($p);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function sesion(string $codigo): SesionVivo
    {
        return SesionVivo::query()->where('codigo', strtoupper($codigo))->with('actividad.artefacto')->firstOrFail();
    }
}
