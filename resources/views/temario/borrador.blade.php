<x-app titulo="Borrador del temario">
    <div class="migas"><a href="{{ route('temario.show', $g->materia) }}">Temario — {{ $g->materia->nombre }}</a> › Borrador</div>
    <h1>Temario extraído <span class="suave">· {{ $g->motor->nombre }} · {{ $g->created_at->format('Y-m-d H:i') }}</span></h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($errores)
        <div class="aviso aviso-error" role="alert"><ul style="margin:0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @else
        <div class="aviso aviso-ok" role="status">✓ Revise que las unidades y los temas correspondan al plan. Después podrá editarlos uno por uno.</div>
    @endif

    @if ($temario && ! $errores)
        <div class="fila" style="margin-bottom:1rem">
            <form method="POST" action="{{ route('temario.aplicar', $g) }}">@csrf <input type="hidden" name="modo" value="agregar">
                <button class="boton">{{ $tieneTemario ? 'Agregar al temario actual' : 'Usar este temario' }}</button></form>
            @if ($tieneTemario)
                <form method="POST" action="{{ route('temario.aplicar', $g) }}">@csrf <input type="hidden" name="modo" value="reemplazar">
                    <button class="boton boton-sec" onclick="return confirm('¿Reemplazar el temario actual por este?')">Reemplazar el temario actual</button></form>
            @endif
            <form method="POST" action="{{ route('temario.descartar', $g) }}">@csrf<button class="enlace">Descartar</button></form>
        </div>
        @foreach ($temario['unidades'] as $u)
            <section class="tarjeta">
                <h2 style="margin-top:0">{{ $u['titulo'] ?? '' }}</h2>
                <ol>@foreach ((array) ($u['temas'] ?? []) as $t)<li>{{ is_array($t) ? ($t['titulo'] ?? '') : $t }}@if (is_array($t) && ! empty($t['descripcion']))<span class="suave"> — {{ $t['descripcion'] }}</span>@endif</li>@endforeach</ol>
                @if (! empty($u['resultados']))<p class="suave" style="margin-bottom:0">Resultados: {{ collect($u['resultados'])->map(fn ($r) => is_array($r) ? ($r['titulo'] ?? '') : $r)->join(' · ') }}</p>@endif
            </section>
        @endforeach
    @endif

    <details class="tarjeta" @if (! $temario) open @endif>
        <summary><strong>Editar el JSON a mano</strong></summary>
        <form method="POST" action="{{ route('temario.editar', $g) }}">@csrf @method('PUT')
            <textarea name="borrador" rows="24" aria-label="JSON del temario">{{ $g->borrador }}</textarea>
            <button class="boton boton-sec" style="margin-top:.5rem">Guardar y validar</button></form>
    </details>
</x-app>
