<div>
    @if ($items->isEmpty())
        <div class="tarjeta vacio"><strong>Esta actividad no tiene preguntas abiertas.</strong></div>
    @else
        <div class="fila" style="margin-bottom:1rem">
            <label for="pregunta" style="margin:0">Pregunta</label>
            <select id="pregunta" wire:model.live="itemId" style="max-width:40rem">
                @foreach ($items as $i)
                    <option value="{{ $i->id }}">{{ $loop->iteration }}. {{ \Illuminate\Support\Str::limit($i->pregunta->enunciado['texto'], 90) }}</option>
                @endforeach
            </select>
            <span class="estado {{ $pendientes ? 'estado-incompleto' : 'estado-aprobado' }}" role="status">{{ $pendientes ? "Faltan {$pendientes} por calificar" : '✓ Todas calificadas' }}</span>
        </div>

        @if ($item)
            @php($max = (string) \Brick\Math\BigDecimal::of($item->peso)->strippedOfTrailingZeros())
            <div class="tarjeta">
                <h2 style="margin-top:0">{{ $item->pregunta->enunciado['texto'] }} <span class="suave">(máximo {{ $max }})</span></h2>
                <details>
                    <summary>Referencia y rúbrica</summary>
                    <div class="suave" style="white-space:pre-wrap">{{ $item->pregunta->clave['referencia'] ?? 'Sin referencia.' }}</div>
                    @if (! empty($item->pregunta->clave['rubrica']))<div class="suave" style="white-space:pre-wrap;margin-top:.5rem">{{ $item->pregunta->clave['rubrica'] }}</div>@endif
                </details>
                <div class="fila" style="margin-top:.75rem">
                    <label for="buscar" style="margin:0">Resaltar términos</label>
                    <input id="buscar" type="text" wire:model.live.debounce.300ms="buscar" placeholder="requisito, restricción" style="max-width:24rem">
                </div>
            </div>

            @foreach ($respuestas as $r)
                @php($texto = is_string($r->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '')
                @php($palabras = str_word_count(preg_replace('/[^\pL\s]/u', ' ', \Illuminate\Support\Str::ascii($texto))))
                <section class="tarjeta respuesta-abierta" wire:key="r-{{ $r->id }}" id="r-{{ $r->id }}">
                    <div class="fila" style="justify-content:space-between">
                        <strong>{{ $r->entrega->integrantes->map->nombreCompleto()->join(' · ') }}</strong>
                        <span class="suave">{{ $palabras }} palabras
                            @if (trim($texto) === '') · <span class="estado estado-reprobado">Vacía</span>
                            @elseif ($palabras < 15) · <span class="estado estado-incompleto">Corta</span>@endif</span>
                    </div>
                    <div class="texto-estudiante">@if ($buscar !== '' && $texto !== ''){!! preg_replace('/('.collect(preg_split('/[\s,]+/', $buscar))->filter(fn ($t) => mb_strlen($t) > 1)->map(fn ($t) => preg_quote(e($t), '/'))->join('|').')/iu', '<mark>$1</mark>', e($texto)) !!}@else{{ $texto }}@endif</div>
                    <div class="fila puntaje-abierta">
                        <label for="p-{{ $r->id }}" style="margin:0">Puntaje</label>
                        <input id="p-{{ $r->id }}" type="text" inputmode="decimal" style="width:6rem" value="{{ $r->puntaje === null ? '' : (string) \Brick\Math\BigDecimal::of($r->puntaje)->strippedOfTrailingZeros() }}"
                               x-on:change="$wire.puntuar({{ $r->id }}, $event.target.value)">
                        @foreach ([0, 0.25, 0.5, 0.75, 1] as $f)
                            @php($v = (string) \Brick\Math\BigDecimal::of($max)->multipliedBy((string) $f)->toScale(2, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros())
                            <button class="boton boton-sec boton-chico" wire:click="puntuar({{ $r->id }}, '{{ $v }}')">{{ $v }}</button>
                        @endforeach
                        @isset($errores[$r->id])<span class="error" role="alert">{{ $errores[$r->id] }}</span>@endisset
                    </div>
                    <label for="c-{{ $r->id }}" style="margin-top:.5rem">Comentario para el estudiante</label>
                    <input id="c-{{ $r->id }}" type="text" value="{{ $r->comentario }}" x-on:change="$wire.comentar({{ $r->id }}, $event.target.value)" placeholder="Breve, como lo diría en clase">
                </section>
            @endforeach
        @endif
    @endif
</div>
