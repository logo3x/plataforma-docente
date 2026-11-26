<x-app :titulo="'Versión '.$version.' de '.$a->sigla">
    <div class="migas"><a href="{{ route('artefactos.index', $a->materia) }}">Actividades evaluables</a> › <a href="{{ route('variantes.create', $a) }}">Versiones de {{ $a->sigla }}</a> › Borrador {{ $version }}</div>
    <h1>Borrador de la versión {{ $version }} <span class="suave">· a partir de la {{ $origenVersion }} · {{ $g->motor->nombre }} · {{ $g->created_at->format('Y-m-d H:i') }}</span></h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($g->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $g->estado }}.</div>@endif
    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden agregarla:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @else
        <div class="aviso aviso-ok" role="status">✓ Mismos tipos y pesos que la versión {{ $origenVersion }}. Revise cada par: que evalúe lo mismo, que los datos de verdad cambien y que la respuesta correcta lo sea con los datos nuevos.</div>
    @endif

    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide agregarla):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $x)<li>{{ $x }}</li>@endforeach</ul></div>
    @endif
    @if ($g->estado === 'borrador')
        <div class="fila" style="margin-bottom:1rem">
            <form method="POST" action="{{ route('variantes.aprobar', $g) }}">@csrf<button class="boton" @disabled($errores)>Agregar la versión {{ $version }} al examen</button></form>
            @if ($errores)<form method="POST" action="{{ route('variantes.corregir', $g) }}">@csrf<button class="boton boton-sec">Pedir a la IA que la corrija</button></form>@endif
            <form method="POST" action="{{ route('variantes.descartar', $g) }}">@csrf<button class="enlace">Descartar</button></form>
        </div>
    @endif

    @foreach ($originales as $i => $o)
        @php($n = $nuevos[$i] ?? null)
        <section class="tarjeta">
            <h2 style="margin-top:0">Ítem {{ $i + 1 }} <span class="suave">· {{ $o['tipo'] }} · peso {{ $o['peso'] }}@if (! empty($o['tema'])) · {{ $o['tema'] }}@endif</span></h2>
            <div class="lado-a-lado">
                <div><p class="suave" style="margin:0">Versión {{ $origenVersion }}</p>@include('variantes._item', ['it' => $o])</div>
                <div><p class="suave" style="margin:0">Versión {{ $version }}</p>
                    @if ($n)@include('variantes._item', ['it' => $n])@if (($n['tipo'] ?? null) !== $o['tipo'] || (string) ($n['peso'] ?? '') !== $o['peso'])<p class="estado estado-reprobado">Cambió el tipo o el peso</p>@endif
                    @else<p class="estado estado-reprobado">Falta este ítem</p>@endif</div>
            </div>
        </section>
    @endforeach

    @if ($g->estado === 'borrador')
        <details class="tarjeta">
            <summary><strong>Editar el JSON a mano</strong></summary>
            <form method="POST" action="{{ route('variantes.editar', $g) }}">@csrf @method('PUT')
                <textarea name="borrador" rows="24" aria-label="JSON del borrador" style="font-size:.85rem">{{ $g->borrador }}</textarea>
                <button class="boton boton-sec" type="submit" style="margin-top:.5rem">Guardar y validar</button></form>
        </details>
    @endif
</x-app>
