@php($nombresCierre = ['estudiante' => 'Finalizó', 'tiempo' => 'Por tiempo', 'docente' => 'Cerrado por el docente', 'docente_sin_respuesta' => 'Cerrado por el docente, sin respuesta del equipo', 'sin_datos' => 'Sin datos: no llegó nada'])
<div wire:poll.5s>
    @if ($aviso)<div class="aviso aviso-ok" role="status">{{ $aviso }}</div>@endif
    @php($enCurso = $participantes->reject->finalizado())
    <div class="fila indicadores-vivo" role="status" aria-live="polite">
        <span><strong>{{ $participantes->count() }}</strong> entraron</span>
        <span><strong>{{ $enCurso->count() }}</strong> respondiendo</span>
        <span><strong>{{ $participantes->count() - $enCurso->count() }}</strong> finalizaron</span>
        <span><strong>{{ $esperados->count() }}</strong> sin entrar</span>
        <span @class(['estado', 'estado-aprobado' => $sesion->abierta(), 'estado-sin_actividades' => ! $sesion->abierta()])>{{ $sesion->abierta() ? 'Sesión abierta' : 'Sesión cerrada' }}</span>
    </div>

    <div class="fila" style="margin:.75rem 0">
        <button class="boton boton-sec" wire:click="finalizarElegidos" @disabled($elegidos === [])
                wire:confirm="¿Finalizar los exámenes elegidos? Se cierran y se entregan con lo respondido.">Finalizar los elegidos ({{ count($elegidos) }})</button>
        <button class="boton boton-peligro" wire:click="finalizarTodos" @disabled($enCurso->isEmpty() && ! $sesion->abierta())
                wire:confirm="¿Finalizar todos los exámenes abiertos y cerrar la sesión? Cada examen se cierra y se entrega con lo respondido hasta ahora.">Finalizar a todos y cerrar</button>
        @if ($sesion->abierta())<button class="boton boton-sec" wire:click="cerrar" wire:confirm="¿Cerrar la sesión? Nadie más podrá entrar; los que están respondiendo siguen.">Cerrar la sesión</button>@endif
    </div>

    <div class="tabla-notas"><table class="tabla-vivo">
        <thead><tr><th><span class="sr-only">Elegir</span></th><th>Estudiante</th><th>Versión</th><th>Inició</th><th>Avance</th><th class="num">Salidas</th><th>Señal</th><th>Estado</th><th class="num">Nota</th></tr></thead>
        <tbody>
            @forelse ($participantes as $p)
                @php($nota = $p->entrega ? $calificador->nota($p->entrega) : null)
                <tr wire:key="p-{{ $p->id }}" @class(['finalizado' => $p->finalizado()])>
                    <td>@unless ($p->finalizado() || $p->cierre_solicitado_en)<input type="checkbox" value="{{ $p->id }}" wire:model.live="elegidos" aria-label="Elegir a {{ $p->nombre }}">@endunless</td>
                    <td>{{ $p->estudiante?->nombreCompleto() ?? $p->nombre }} <span class="suave">· {{ $p->documento }}</span>
                        @if (! $p->estudiante)<span class="alerta-chip">No está en la lista</span>@endif
                        @if ($p->ingresos > 1)<span class="alerta-chip" title="Volvió a abrir el examen o entró desde otro equipo">{{ $p->ingresos }} ingresos</span>@endif</td>
                    <td>{{ $p->version ?? '—' }}</td>
                    <td>{{ $p->created_at->format('H:i') }}</td>
                    <td><div class="barra-avance" style="width:7rem;display:inline-block;vertical-align:middle;margin:0" role="img" aria-label="{{ $p->respondidas }} de {{ $p->total }}"><div style="width:{{ $p->total ? round(100 * $p->respondidas / $p->total) : 0 }}%"></div></div>
                        <span class="suave">{{ $p->respondidas }}/{{ $p->total }}</span></td>
                    <td class="num">@if ($p->salidas){{ $p->salidas }} <span class="suave">· {{ $p->segundos_fuera }} s</span>@else 0 @endif</td>
                    <td>@if ($p->finalizado())—@elseif ($p->ultimo_latido && $p->ultimo_latido->gte($sinSenal))<span class="estado estado-aprobado">✓ Conectado</span>
                        @else<span class="estado estado-reprobado">⚠ Sin señal {{ $p->ultimo_latido ? 'desde '.$p->ultimo_latido->format('H:i:s') : '' }}</span>@endif</td>
                    <td>@if ($p->finalizado()){{ $nombresCierre[$p->finalizado_como] ?? $p->finalizado_como }} <span class="suave">{{ $p->finalizado_en->format('H:i') }}</span>
                        @elseif ($p->cierre_solicitado_en)<span class="estado estado-incompleto">Cerrando… (pedido {{ $p->cierre_solicitado_en->format('H:i:s') }})</span>
                        @else Respondiendo @endif</td>
                    <td class="num definitiva">@if ($p->entrega){{ $nota === null ? 'pendiente' : $escala->mostrar($nota) }}@else — @endif</td>
                </tr>
            @empty
                <tr><td colspan="9" class="suave">Nadie ha entrado todavía. Proyecte el código para que los estudiantes abran el examen.</td></tr>
            @endforelse
        </tbody>
    </table></div>

    @if ($masivas->isNotEmpty())
        <h2>Constancia de finalización por el docente</h2>
        <ul>@foreach ($masivas as $clave => $ps)@php([$hora, $quien] = explode('|', $clave))
            <li>{{ $hora }} · {{ $quien }} pidió finalizar {{ $ps->count() }} examen(es):
                {{ $ps->where('finalizado_como', 'docente')->count() }} respondieron y entregaron,
                {{ $ps->where('finalizado_como', 'docente_sin_respuesta')->count() }} se registraron con lo último que enviaron,
                {{ $ps->where('finalizado_como', 'sin_datos')->count() }} sin datos{{ $ps->whereNull('finalizado_en')->count() ? ', '.$ps->whereNull('finalizado_en')->count().' cerrándose' : '' }}.</li>
        @endforeach</ul>
    @endif

    @if ($esperados->isNotEmpty())
        <details class="tarjeta"><summary>Sin entrar ({{ $esperados->count() }})</summary><p>{{ $esperados->map->nombreCompleto()->join(' · ') }}</p></details>
    @endif
</div>
