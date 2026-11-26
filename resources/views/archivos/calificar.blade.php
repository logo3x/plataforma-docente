@php($maximo = (string) \Brick\Math\BigDecimal::of($actividad->valor_max)->strippedOfTrailingZeros())
@php($rubrica = $actividad->rubrica)
@php($elegidos = old('integrantes', $entrega->integrantes->pluck('id')->all()))
<x-app :titulo="'Calificar — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.archivos', $actividad) }}">Entregas — {{ $actividad->nombre }}</a> › {{ $entrega->archivo_nombre }}</div>
    <h1>{{ $entrega->integrantes->isEmpty() ? 'Entrega sin identificar' : $entrega->integrantes->map->nombreCompleto()->join(' · ') }}</h1>
    <p class="suave">{{ $entrega->archivo_nombre }} · {{ number_format($entrega->archivo_bytes / 1024, 0, ',', '.') }} KB
        @if ($entrega->paginas) · {{ $entrega->paginas }} página(s) @endif · nota {{ $nota === null ? 'pendiente' : $escala->mostrar($nota) }} sobre {{ $maximo }}</p>

    <div class="calificar-archivo">
        <section class="tarjeta visor-archivo">
            @if ($entrega->archivo_mime === 'application/pdf')
                <iframe src="{{ route('calificador.archivos.archivo', $entrega) }}" title="Trabajo entregado: {{ $entrega->archivo_nombre }}"></iframe>
                <p style="margin:.5rem 0 0"><a href="{{ route('calificador.archivos.archivo', $entrega) }}" target="_blank" rel="noopener">Abrir en otra pestaña</a></p>
            @else
                <p>Este archivo no se muestra en la página: descárguelo y ábralo con su programa.</p>
                <p><a class="boton boton-sec" href="{{ route('calificador.archivos.archivo', $entrega) }}">Descargar {{ $entrega->archivo_nombre }}</a></p>
            @endif
        </section>

        <form class="tarjeta" method="POST" action="{{ route('calificador.archivos.guardar', $entrega) }}">
            @csrf @method('PUT')
            <label for="integrantes">Integrantes @if (! $actividad->es_grupal)<span class="suave">(uno, salvo entrega grupal)</span>@endif</label>
            <select id="integrantes" name="integrantes[]" multiple size="5">
                @foreach ($estudiantes as $est)<option value="{{ $est->id }}" @selected(in_array($est->id, $elegidos))>{{ $est->apellidos }}, {{ $est->nombres }} · {{ $est->documento }}</option>@endforeach
            </select>
            <p class="suave" style="margin:.25rem 0">Ctrl + clic para elegir varios. La nota llega a todos los integrantes.</p>

            @if ($rubrica)
                <h2>Rúbrica «{{ $rubrica->titulo }}»</h2>
                <div class="rubrica-archivo">
                    @foreach ($rubrica->criterios as $ci => $c)
                        <fieldset>
                            <legend><strong>{{ $c['nombre'] }}</strong> <span class="suave">{{ $c['peso'] }} %</span></legend>
                            @foreach ($c['niveles'] as $ni => $n)
                                <label class="nivel-rubrica" title="{{ $n['descripcion'] }}">
                                    <input type="radio" name="nivel[{{ $ci }}]" value="{{ $ni }}" @checked((string) old("nivel.$ci", $seleccion[$ci] ?? '') === (string) $ni)>
                                    <span>{{ $n['nombre'] }} <span class="suave">{{ $n['porcentaje'] }} %</span></span>
                                    <small class="suave">{{ $n['descripcion'] }}</small>
                                </label>
                            @endforeach
                        </fieldset>
                    @endforeach
                </div>
                <p class="suave">La nota se calcula sola cuando todos los criterios tienen nivel; mientras falte alguno, queda pendiente.</p>
            @else
                <label for="puntaje">Nota (0 a {{ $maximo }})</label>
                <input id="puntaje" name="puntaje" type="text" inputmode="decimal" style="width:8rem" autocomplete="off"
                       value="{{ old('puntaje', $entrega->puntaje === null ? '' : (string) \Brick\Math\BigDecimal::of($entrega->puntaje)->strippedOfTrailingZeros()) }}">
                <p class="suave" style="margin:.25rem 0">Sin rúbrica. Puede asociar una en la página de entregas.</p>
            @endif
            @error('puntaje')<div class="error" role="alert">{{ $message }}</div>@enderror

            <label for="comentario">Comentario para el estudiante</label>
            <textarea id="comentario" name="comentario" rows="5" maxlength="3000">{{ old('comentario', $entrega->comentario) }}</textarea>
            <p class="suave" style="margin:.25rem 0">Llega con la nota y en el informe de devolución: escríbalo como lo diría en clase.</p>

            <div class="fila">
                <button class="boton" type="submit" name="despues" value="siguiente">Guardar y seguir con la próxima</button>
                <button class="boton boton-sec" type="submit" name="despues" value="quedarse">Guardar</button>
            </div>
            <p class="fila" style="margin-top:1rem">
                @if ($anterior)<a href="{{ route('calificador.archivos.calificar', $anterior) }}">← Anterior</a>@endif
                @if ($siguiente)<a href="{{ route('calificador.archivos.calificar', $siguiente) }}">Siguiente →</a>@endif
                @if ($siguientePendiente)<a href="{{ route('calificador.archivos.calificar', $siguientePendiente) }}">Próxima sin calificar</a>@endif
            </p>
        </form>
    </div>

    <form method="POST" action="{{ route('calificador.archivos.eliminar', $entrega) }}" onsubmit="return confirm('¿Eliminar esta entrega y su archivo?')">
        @csrf @method('DELETE')
        <button class="boton boton-peligro boton-chico" type="submit">Eliminar entrega</button>
        <span class="suave">Solo si se cargó por error y aún no pasó al registro.</span>
    </form>
</x-app>
