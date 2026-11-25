<x-app titulo="Borrador generado">
    <div class="migas"><a href="{{ route('generacion.create', $g->materia) }}">Generar con IA — {{ $g->materia->nombre }}</a> › Borrador</div>
    <h1>Borrador {{ $banco['sigla'] ?? '' }} <span class="suave">· {{ $g->motor->nombre }} · {{ $g->created_at->format('Y-m-d H:i') }}</span></h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($g->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $g->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>El borrador tiene {{ count($errores) }} observación(es); corríjalas antes de guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @else
        <div class="aviso aviso-ok" role="status">✓ El borrador es válido. Revise el contenido: la IA puede equivocarse en una respuesta aunque el formato sea correcto.</div>
    @endif

    @if ($g->estado === 'borrador')
        <div class="fila" style="margin-bottom:1rem">
            <form method="POST" action="{{ route('generacion.aprobar', $g) }}">@csrf<button class="boton" @disabled($errores)>Guardar como actividad</button></form>
            @if ($errores)
                <form method="POST" action="{{ route('generacion.corregir', $g) }}">@csrf<button class="boton boton-sec">Pedir a la IA que lo corrija</button></form>
                @if ($banco)<form method="POST" action="{{ route('generacion.reescalar', $g) }}">@csrf<button class="boton boton-sec">Ajustar pesos al valor</button></form>@endif
            @endif
            <form method="POST" action="{{ route('generacion.descartar', $g) }}">@csrf<button class="enlace">Descartar</button></form>
        </div>
    @endif

    @if ($banco)
        <h2>{{ $banco['titulo'] ?? 'Sin título' }} <span class="suave">· valor {{ $banco['valor'] ?? '?' }} · {{ count($banco['items'] ?? []) }} ítems</span></h2>
        @foreach ($banco['items'] ?? [] as $i => $it)
            <section class="tarjeta">
                <div class="fila" style="justify-content:space-between"><strong>{{ $i + 1 }}. [{{ \App\Evaluacion\GeneradorBanco::TIPOS[$it['tipo'] ?? ''] ?? ($it['tipo'] ?? '?') }}] {{ $it['enunciado'] ?? '' }}</strong><span class="suave">{{ $it['peso'] ?? '?' }}</span></div>
                @if (! empty($it['opciones']))<ul>@foreach ($it['opciones'] as $k => $o)@php($esCorrecta = in_array((string) $k, array_map('strval', (array) ($it['correctas'] ?? [$it['correcta'] ?? null])), true))<li @class(['correcta' => $esCorrecta])>{{ $k }}) {{ is_scalar($o) ? $o : json_encode($o) }}@if ($esCorrecta) ✓ correcta @endif</li>@endforeach</ul>@endif
                @if (($it['tipo'] ?? '') === 'vf')<p class="correcta">✓ Correcta: <strong>{{ ($it['correcta'] ?? null) === true ? 'Verdadero' : (($it['correcta'] ?? null) === false ? 'Falso' : '?') }}</strong></p>@endif
                @if (! empty($it['plantilla']))<pre class="texto-estudiante">{{ $it['plantilla'] }}</pre><p>Huecos: <code>{{ json_encode($it['huecos'] ?? [], JSON_UNESCAPED_UNICODE) }}</code></p>@endif
                @if (! empty($it['verificaciones']))<ul>@foreach ($it['verificaciones'] as $v)<li>{{ $v['nombre'] ?? '' }} — <code>{{ $v['prueba'] ?? '' }}</code> ({{ $v['fraccion'] ?? '?' }}@if ($v['reservada'] ?? false), reservada @endif)</li>@endforeach</ul>@endif
                @if (! empty($it['justificacion']))<p class="suave">{{ $it['justificacion'] }}</p>@endif
            </section>
        @endforeach
    @endif

    @if ($g->estado === 'borrador')
        <details class="tarjeta" @if (! $banco) open @endif>
            <summary><strong>Editar el JSON a mano</strong></summary>
            <form method="POST" action="{{ route('generacion.editar', $g) }}">
                @csrf @method('PUT')
                <textarea name="borrador" rows="30" aria-label="JSON del borrador" style="font-size:.85rem">{{ $g->borrador }}</textarea>
                <button class="boton boton-sec" type="submit" style="margin-top:.5rem">Guardar y validar</button>
            </form>
        </details>
    @endif
</x-app>
