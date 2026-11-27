<x-app :titulo="'Historial — '.$materia->nombre">
    @php($H = \App\Historial\HistorialMateria::class)
    @php($periodo = $dictados->firstWhere('id', (int) ($filtros['dictado'] ?? 0)))
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Historial</div>
    <h1>Historial de actividades @if ($periodo)<span class="suave">· periodo {{ $periodo->periodo }}</span>@endif</h1>
    <p class="suave">Todo lo creado en la materia, a mano o con IA. Pertenece a la materia: cualquier periodo lo hereda.
        Las evaluaciones se vuelven a aplicar; lo demás se abre para editarlo o se copia para ajustarlo sin tocar la original.</p>

    <div class="fila" style="flex-wrap:wrap;gap:.4rem;margin-bottom:.8rem" aria-label="Resumen por tipo">
        @foreach ($H::TIPOS as $t => $etq)
            @if ($conteos[$t] ?? 0)
                <a @class(['boton', 'boton-chico', 'boton-sec' => ($filtros['tipo'] ?? null) !== $t]) href="{{ route('historial.index', ['materia' => $materia] + array_filter(['tipo' => $t, 'dictado' => $filtros['dictado'] ?? null])) }}">{{ $etq }} · {{ $conteos[$t] }}</a>
            @endif
        @endforeach
    </div>

    <form method="GET" action="{{ route('historial.index', $materia) }}" class="tarjeta">
        <div class="rejilla" style="grid-template-columns:2fr 1fr 1fr 1fr auto;align-items:end">
            <div class="campo"><label for="q">Buscar por título</label><input id="q" name="q" type="search" value="{{ $filtros['q'] ?? '' }}" placeholder="pilas, parcial 1, sustentación"></div>
            <div class="campo"><label for="tipo">Tipo</label>
                <select id="tipo" name="tipo"><option value="">Todos</option>@foreach ($H::TIPOS as $t => $etq)<option value="{{ $t }}" @selected(($filtros['tipo'] ?? null) === $t)>{{ $etq }}</option>@endforeach</select></div>
            <div class="campo"><label for="dictado">Periodo</label>
                <select id="dictado" name="dictado"><option value="">Todos</option>@foreach ($dictados as $d)<option value="{{ $d->id }}" @selected((int) ($filtros['dictado'] ?? 0) === $d->id)>{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="origen">Origen</label>
                <select id="origen" name="origen"><option value="">Todos</option><option value="ia" @selected(($filtros['origen'] ?? null) === 'ia')>Con IA</option><option value="manual" @selected(($filtros['origen'] ?? null) === 'manual')>A mano o importado</option></select></div>
            <div class="campo fila"><button class="boton boton-sec" type="submit">Filtrar</button>
                @if (array_filter($filtros))<a href="{{ route('historial.index', $materia) }}">Quitar filtros</a>@endif</div>
        </div>
    </form>

    @if ($periodo)
        <p class="suave">Del periodo {{ $periodo->periodo }}: lo creado mientras estaba en curso y las evaluaciones que se aplicaron en él.</p>
    @endif

    @forelse ($entradas as $e)
        <section class="tarjeta entrada-historial">
            <div class="fila" style="justify-content:space-between;align-items:flex-start">
                <div style="flex:1">
                    <span class="etiqueta-tipo">{{ $e['etiqueta'] }}</span>
                    <h2 style="margin:.3rem 0 .1rem"><a href="{{ $e['abrir'] }}">{{ $e['titulo'] }}</a></h2>
                    <p class="suave" style="margin:0">{{ $e['detalle'] }} · creada el {{ $e['creado']->format('Y-m-d') }}@if ($e['creado_en']) (periodo {{ $e['creado_en'] }})@endif ·
                        @if ($e['origen'] === 'ia') con IA ({{ $e['motor'] }})@if ($e['fuentes']), a partir de: {{ implode(', ', $e['fuentes']) }}@else, sin material de referencia @endif
                        @else {{ $e['tipo'] === 'evaluacion' ? 'importada o escrita a mano' : 'a mano' }} @endif</p>
                    @if ($e['tipo'] === 'evaluacion')
                        <p style="margin:.3rem 0 0">
                            @if ($e['aplicaciones'])
                                <strong>Aplicada en:</strong> @foreach ($e['aplicaciones'] as $ap)<a href="{{ $ap['url'] }}">{{ $ap['texto'] }}</a>@if (! $loop->last) · @endif @endforeach
                            @else <span class="suave">Aún no se ha aplicado en ningún periodo.</span> @endif
                        </p>
                    @endif
                </div>
                <div class="fila">
                    @if ($e['tipo'] === 'evaluacion')
                        <a class="boton boton-sec boton-chico" href="{{ $e['abrir'] }}">Aplicar en un periodo</a>
                    @else
                        <a class="boton boton-sec boton-chico" href="{{ $e['editar'] }}">Editar</a>
                        <form method="POST" action="{{ route('historial.copiar', $materia) }}">
                            @csrf<input type="hidden" name="tipo" value="{{ $e['tipo'] }}"><input type="hidden" name="id" value="{{ $e['id'] }}">
                            <button class="boton boton-sec boton-chico" aria-label="Copiar para ajustar «{{ $e['titulo'] }}»">Copiar para ajustar</button>
                        </form>
                        @if ($otrasMaterias->isNotEmpty())
                            <form method="POST" action="{{ route('historial.copiar', $materia) }}" class="fila" style="gap:.3rem">
                                @csrf<input type="hidden" name="tipo" value="{{ $e['tipo'] }}"><input type="hidden" name="id" value="{{ $e['id'] }}">
                                <select name="destino" class="boton-chico" style="width:auto" aria-label="Materia a la que se copia «{{ $e['titulo'] }}»" required>
                                    <option value="">Copiar a otra materia…</option>
                                    @foreach ($otrasMaterias as $om)<option value="{{ $om->id }}">{{ $om->nombre }}</option>@endforeach
                                </select>
                                <button class="boton boton-sec boton-chico" aria-label="Copiar «{{ $e['titulo'] }}» a la materia elegida">Copiar</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>
        </section>
    @empty
        <div class="tarjeta vacio">
            @if (array_filter($filtros))<strong>Ninguna actividad coincide con los filtros.</strong> <a href="{{ route('historial.index', $materia) }}">Ver todo</a>
            @else<strong>Aún no hay actividades en esta materia.</strong> Use los generadores de «Mis materias» o importe un banco.@endif
        </div>
    @endforelse
</x-app>
