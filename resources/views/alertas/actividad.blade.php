@php($P = \App\Calificacion\PoliticaAlertas::class)
@php($quienes = fn ($a) => $a->entrega?->integrantes->map->nombreCompleto()->join(' · ') ?: ($a->estudiante?->nombreCompleto() ?? '—'))
<x-app :titulo="'Alertas — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Alertas</div>
    <h1>Alertas — {{ $actividad->nombre }}</h1>
    <p class="suave">Informan; ninguna cambia la nota. Revise las que su política marca para revisión; si la política lo permite, decida si se le cuenta al estudiante.
        <a href="{{ route('alertas.politica') }}">Política de alertas</a></p>

    <h2>Por revisar ({{ $por_revisar->count() }})</h2>
    @forelse ($por_revisar as $a)
        <section class="tarjeta alerta-revision" id="alerta-{{ $a->id }}">
            <div class="fila" style="justify-content:space-between">
                <strong>{{ $P::TIPOS[$a->tipo][0] ?? $a->tipo }} · {{ $quienes($a) }}</strong>
                @if ($a->entrega)<a href="{{ route('calificador.detalle', $a->entrega) }}">Ver la entrega</a>@endif
            </div>
            <p style="margin:.3rem 0">{{ $P::textoNeutral($a) }}</p>
            <form method="POST" action="{{ route('alertas.revisar', $a) }}" class="fila" style="align-items:flex-end">@csrf
                <div><label for="d-{{ $a->id }}">Decisión</label><select id="d-{{ $a->id }}" name="decision">@foreach ($P::DECISIONES as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select></div>
                <div style="flex:1;min-width:16rem"><label for="n-{{ $a->id }}">Nota <span class="suave">(si se informa, el estudiante la lee)</span></label>
                    <input id="n-{{ $a->id }}" name="nota" type="text" maxlength="500" placeholder="Se revisó con el estudiante: estaba consultando el enunciado impreso."></div>
                @if (($politica[$a->tipo]['informar'] ?? 'nunca') === 'tras_revision')
                    <label style="font-weight:normal"><input type="hidden" name="informar" value="0"><input type="checkbox" name="informar" value="1"> Contárselo en su informe</label>
                @endif
                <button class="boton boton-chico" type="submit">Marcar revisada</button>
            </form>
        </section>
    @empty
        <div class="tarjeta vacio"><strong>No hay alertas por revisar.</strong>Las que su política marca para revisión aparecerán aquí.</div>
    @endforelse

    @if ($revisadas->isNotEmpty())
        <h2>Revisadas ({{ $revisadas->count() }})</h2>
        <div class="tabla-notas"><table>
            <thead><tr><th>Alerta</th><th>Estudiante(s)</th><th>Decisión</th><th>Se informó</th></tr></thead>
            <tbody>@foreach ($revisadas as $a)<tr>
                <td>{{ $P::TIPOS[$a->tipo][0] ?? $a->tipo }}</td><td>{{ $quienes($a) }}</td>
                <td>{{ $P::DECISIONES[$a->decision] ?? '—' }} <span class="suave">· {{ $a->revisor?->name }} · {{ $a->revisada_en->format('d/m H:i') }}</span>@if ($a->nota_docente)<div class="suave">{{ $a->nota_docente }}</div>@endif</td>
                <td>{{ $a->informar && ($politica[$a->tipo]['informar'] ?? 'nunca') === 'tras_revision' ? '✓ Sí' : 'No' }}</td></tr>@endforeach</tbody>
        </table></div>
    @endif

    @if ($informativas->isNotEmpty())
        <details class="tarjeta" style="margin-top:1rem"><summary>Informativas, sin revisión ({{ $informativas->count() }})</summary>
            <ul>@foreach ($informativas as $a)<li>{{ $P::TIPOS[$a->tipo][0] ?? $a->tipo }} · {{ $quienes($a) }}</li>@endforeach</ul>
        </details>
    @endif
</x-app>
