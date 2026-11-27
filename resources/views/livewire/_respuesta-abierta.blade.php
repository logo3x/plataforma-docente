{{-- Una respuesta abierta para calificar (6.7). La usan los dos modos: por pregunta y por estudiante. --}}
@php($rubrica = $r->item->pregunta->rubrica)
@php($max = (string) \Brick\Math\BigDecimal::of($r->item->peso)->strippedOfTrailingZeros())
@php($texto = is_string($r->respuesta['valor'] ?? null) ? $r->respuesta['valor'] : '')
@php($palabras = str_word_count(preg_replace('/[^\pL\s]/u', ' ', \Illuminate\Support\Str::ascii($texto))))
<section class="tarjeta respuesta-abierta" wire:key="r-{{ $r->id }}" id="r-{{ $r->id }}" data-pendiente="{{ $r->puntaje === null ? 1 : 0 }}">
    <div class="fila" style="justify-content:space-between">
        @if ($modo === 'estudiante')
            <h2 style="margin:0;font-size:1.05rem;flex:1">{{ $numero }}. {{ $r->item->pregunta->enunciado['texto'] }} <span class="suave">(máximo {{ $max }})</span></h2>
        @else
            <strong>{{ $r->entrega->integrantes->map->nombreCompleto()->join(' · ') }}</strong>
        @endif
        <span class="suave">{{ $palabras }} palabras
            @if (trim($texto) === '') · <span class="estado estado-reprobado">Vacía</span>
            @elseif ($palabras < $umbral) · <span class="estado estado-incompleto">Corta</span>@endif</span>
    </div>
    <div class="texto-estudiante">@if ($buscar !== '' && $texto !== ''){!! preg_replace('/('.collect(preg_split('/[\s,]+/', $buscar))->filter(fn ($t) => mb_strlen($t) > 1)->map(fn ($t) => preg_quote(e($t), '/'))->join('|').')/iu', '<mark>$1</mark>', e($texto)) !!}@else{{ $texto }}@endif</div>
    @if ($modo === 'estudiante')
        <details class="referencia-abierta"><summary>Referencia @if ($rubrica)y rúbrica «{{ $rubrica->titulo }}»@endif</summary>
            <div class="suave" style="white-space:pre-wrap">{{ $r->item->pregunta->clave['referencia'] ?? 'Sin referencia.' }}</div>
            @if (! empty($r->item->pregunta->clave['rubrica']))<div class="suave" style="white-space:pre-wrap;margin-top:.4rem">{{ $r->item->pregunta->clave['rubrica'] }}</div>@endif
        </details>
    @endif
    @if ($rubrica)
        @php($sel = ($r->desglose['rubrica_id'] ?? null) === $rubrica->id ? ($r->desglose['rubrica']['seleccion'] ?? []) : [])
        <div class="rubrica-calificar" role="group" aria-label="Rúbrica «{{ $rubrica->titulo }}»">
            @foreach ($rubrica->criterios as $ci => $c)
                <div class="fila">
                    <span><strong>{{ $c['nombre'] }}</strong> <span class="suave">{{ $c['peso'] }} %</span></span>
                    <span class="fila" style="gap:.4rem">
                    @foreach ($c['niveles'] as $ni => $n)
                        @php($elegido = ($sel[$ci] ?? null) === $ni)
                        <button type="button" @class(['boton', 'boton-chico', 'boton-sec' => ! $elegido]) aria-pressed="{{ $elegido ? 'true' : 'false' }}"
                                title="{{ $n['descripcion'] }}" wire:click="nivel({{ $r->id }}, {{ $ci }}, {{ $elegido ? 'null' : $ni }})">@if ($elegido)✓ @endif{{ $n['nombre'] }}</button>
                    @endforeach
                    </span>
                </div>
            @endforeach
            @if ($sel !== [] && in_array(null, $sel, true))<p class="suave" style="margin:.2rem 0">Faltan criterios por evaluar: el puntaje se calcula al completarlos.</p>@endif
        </div>
    @endif
    @php($sug = $r->sugerencia)
    @if (is_array($sug) && $r->puntaje === null && ($sug['decision'] ?? null) === null)
        <div class="sugerencia-ia" role="note" aria-label="Sugerencia de la IA">
            <div class="fila" style="justify-content:space-between">
                <span><strong>Sugerencia de la IA:</strong> {{ \Brick\Math\BigDecimal::of($sug['puntaje'])->toScale(2, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros() }}
                    de {{ \Brick\Math\BigDecimal::of($r->puntaje_max)->strippedOfTrailingZeros() }}
                    @if ($rubrica && is_array($sug['seleccion'] ?? null))
                        <span class="suave">· {{ collect($sug['seleccion'])->map(fn ($ni, $ci) => ($rubrica->criterios[$ci]['nombre'] ?? '?').': '.($rubrica->criterios[$ci]['niveles'][$ni]['nombre'] ?? '?'))->join(' · ') }}</span>
                    @endif</span>
                <span class="fila" style="gap:.4rem">
                    <button type="button" class="boton boton-chico" wire:click="aceptarSugerencia({{ $r->id }})">Aceptar</button>
                    <button type="button" class="boton boton-sec boton-chico" wire:click="descartarSugerencia({{ $r->id }})">Descartar</button>
                </span>
            </div>
            @if (! empty($sug['justificacion']))<p style="margin:.3rem 0 0">{{ $sug['justificacion'] }}</p>@endif
            <p class="suave" style="margin:.2rem 0 0">Lea la respuesta antes de aceptar. Si no está de acuerdo, ponga su puntaje: la sugerencia no cuenta.</p>
        </div>
    @endif
    <div class="fila puntaje-abierta">
        <label for="p-{{ $r->id }}" style="margin:0">Puntaje</label>
        <input id="p-{{ $r->id }}" type="text" inputmode="decimal" style="width:6rem" value="{{ $r->puntaje === null ? '' : (string) \Brick\Math\BigDecimal::of($r->puntaje)->strippedOfTrailingZeros() }}"
               x-on:change="$wire.puntuar({{ $r->id }}, $event.target.value)"
               x-on:keydown.enter.prevent="$wire.puntuar({{ $r->id }}, $event.target.value).then(() => siguiente({{ $r->id }}))" aria-describedby="ayuda-teclado">
        @foreach ($rubrica ? [] : [0, 0.25, 0.5, 0.75, 1] as $f)
            @php($v = (string) \Brick\Math\BigDecimal::of($max)->multipliedBy((string) $f)->toScale(2, \Brick\Math\RoundingMode::HalfUp)->strippedOfTrailingZeros())
            <button class="boton boton-sec boton-chico" wire:click="puntuar({{ $r->id }}, '{{ $v }}')">{{ $v }}</button>
        @endforeach
        @isset($errores[$r->id])<span class="error" role="alert">{{ $errores[$r->id] }}</span>@endisset
    </div>
    <label for="c-{{ $r->id }}" style="margin-top:.5rem">Comentario para el estudiante</label>
    {{-- La clave cambia con el texto: al insertar un frecuente o usar un borrador, la casilla muestra el valor nuevo --}}
    <input id="c-{{ $r->id }}" wire:key="c-{{ $r->id }}-{{ md5((string) $r->comentario) }}" type="text" value="{{ $r->comentario }}" x-on:change="$wire.comentar({{ $r->id }}, $event.target.value)" placeholder="Breve, como lo diría en clase">
    @if ($frecuentes->isNotEmpty())
        <div class="fila frecuentes" style="margin-top:.3rem;gap:.3rem">
            @foreach ($frecuentes->take(8) as $f)
                <button type="button" class="boton boton-sec boton-chico" wire:click="insertarFrecuente({{ $r->id }}, {{ $f->id }})" title="Agregar al comentario">+ {{ \Illuminate\Support\Str::limit($f->texto, 40) }}</button>
            @endforeach
        </div>
    @endif
    @if ($r->comentario_borrador)
        <div class="borrador-ia" x-data="{ texto: @js($r->comentario_borrador) }">
            <label for="b-{{ $r->id }}"><strong>Borrador de la IA</strong> <span class="suave">(el estudiante aún no lo ve; edítelo si hace falta)</span></label>
            <textarea id="b-{{ $r->id }}" rows="2" x-model="texto" style="font-family:inherit"></textarea>
            @if (\App\Calificacion\ComentariosIA::delataCalculo($r->comentario_borrador))
                <p class="estado estado-incompleto" style="margin:.3rem 0">Menciona cifras o puntajes: un comentario de docente no los dice (8.5).</p>
            @endif
            <div class="fila" style="margin-top:.3rem">
                <button type="button" class="boton boton-chico" x-on:click="$wire.usarBorrador({{ $r->id }}, texto)">Usar este comentario</button>
                <button type="button" class="enlace" wire:click="descartarBorrador({{ $r->id }})">Descartar</button>
            </div>
        </div>
    @endif
</section>
