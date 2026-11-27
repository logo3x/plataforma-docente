<div x-data="{
        siguiente(desde) {
            const todas = [...document.querySelectorAll('.respuesta-abierta')];
            const i = desde ? todas.findIndex(s => s.id === 'r-' + desde) : todas.findIndex(s => s.contains(document.activeElement));
            const resto = [...todas.slice(i + 1), ...todas.slice(0, i + 1)];
            const s = resto.find(x => x.dataset.pendiente === '1' && x.id !== 'r-' + desde);
            if (s) { s.scrollIntoView({ block: 'center' }); s.querySelector('input[inputmode=decimal]')?.focus(); }
        } }"
     x-on:keydown.window="if ($event.key.toLowerCase() === 'j' && !$event.ctrlKey && !$event.metaKey && !$event.altKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)) { $event.preventDefault(); siguiente(null) }">
    @if ($items->isEmpty())
        <div class="tarjeta vacio"><strong>Esta actividad no tiene preguntas abiertas.</strong></div>
    @else
        <div class="fila" style="margin-bottom:.6rem;gap:.4rem" role="group" aria-label="Modo de calificación">
            <button type="button" @class(['boton', 'boton-chico', 'boton-sec' => $modo !== 'pregunta']) aria-pressed="{{ $modo === 'pregunta' ? 'true' : 'false' }}" wire:click="$set('modo', 'pregunta')">Por pregunta</button>
            <button type="button" @class(['boton', 'boton-chico', 'boton-sec' => $modo !== 'estudiante']) aria-pressed="{{ $modo === 'estudiante' ? 'true' : 'false' }}" wire:click="$set('modo', 'estudiante')">Por estudiante</button>
            <span class="suave">Faltan {{ $pendientesTotal }} respuesta(s) abiertas por calificar en toda la actividad.</span>
        </div>
        <div class="fila" style="margin-bottom:1rem">
            @if ($modo === 'estudiante')
                <label for="entrega" style="margin:0">Estudiante</label>
                <button type="button" class="boton boton-sec boton-chico" wire:click="$set('entregaId', {{ $anterior ?? 'null' }})" @disabled(! $anterior) aria-label="Estudiante anterior">←</button>
                <select id="entrega" wire:model.live="entregaId" style="max-width:32rem">
                    @foreach ($entregas as $x)
                        <option value="{{ $x['entrega']->id }}">{{ $x['entrega']->integrantes->map->nombreCompleto()->join(' · ') }}{{ $x['pendientes'] ? " — faltan {$x['pendientes']}" : ' — ✓' }}</option>
                    @endforeach
                </select>
                <button type="button" class="boton boton-sec boton-chico" wire:click="$set('entregaId', {{ $siguiente ?? 'null' }})" @disabled(! $siguiente) aria-label="Estudiante siguiente">→</button>
            @else
                <label for="pregunta" style="margin:0">Pregunta</label>
                <select id="pregunta" wire:model.live="itemId" style="max-width:40rem">
                    @foreach ($items as $i)
                        <option value="{{ $i->id }}">{{ $loop->iteration }}. {{ \Illuminate\Support\Str::limit($i->pregunta->enunciado['texto'], 90) }}</option>
                    @endforeach
                </select>
            @endif
            <span class="estado {{ $pendientes ? 'estado-incompleto' : 'estado-aprobado' }}" role="status">{{ $pendientes ? "Faltan {$pendientes} por calificar" : '✓ Todas calificadas' }}</span>
        </div>
        <div class="fila" style="margin-bottom:1rem">
            <label for="buscar" style="margin:0">Resaltar términos</label>
            <input id="buscar" type="text" wire:model.live.debounce.300ms="buscar" placeholder="requisito, restricción" style="max-width:20rem">
            <label for="umbral" style="margin:0">Marcar como corta con menos de</label>
            <input id="umbral" type="number" min="0" max="500" wire:model.live.debounce.400ms="umbral" style="width:5rem"> <span>palabras</span>
        </div>
        <p class="suave" id="ayuda-teclado" style="margin:-.5rem 0 1rem">Teclado: <kbd>J</kbd> va a la siguiente respuesta sin calificar; en la casilla de puntaje, <kbd>Enter</kbd> guarda y pasa a la siguiente.</p>

        @if ($modo === 'estudiante' || $item)
            @if ($modo === 'pregunta')
            @php($max = (string) \Brick\Math\BigDecimal::of($item->peso)->strippedOfTrailingZeros())
            <div class="tarjeta">
                <h2 style="margin-top:0">{{ $item->pregunta->enunciado['texto'] }} <span class="suave">(máximo {{ $max }})</span></h2>
                @php($rubrica = $item->pregunta->rubrica)
                <details>
                    <summary>Referencia y rúbrica @if ($rubrica)<span class="suave">· «{{ $rubrica->titulo }}»</span>@endif</summary>
                    <div class="suave" style="white-space:pre-wrap">{{ $item->pregunta->clave['referencia'] ?? 'Sin referencia.' }}</div>
                    @if (! empty($item->pregunta->clave['rubrica']))<div class="suave" style="white-space:pre-wrap;margin-top:.5rem">{{ $item->pregunta->clave['rubrica'] }}</div>@endif
                    @if ($rubrica)
                        <table class="suave" style="margin-top:.5rem"><thead><tr><th>Criterio</th><th class="num">Peso</th><th>Niveles</th></tr></thead><tbody>
                            @foreach ($rubrica->criterios as $c)
                                <tr><td>{{ $c['nombre'] }}</td><td class="num">{{ $c['peso'] }} %</td>
                                    <td>@foreach ($c['niveles'] as $n)<div><strong>{{ $n['nombre'] }} ({{ $n['porcentaje'] }} %)</strong>@if ($n['descripcion']): {{ $n['descripcion'] }}@endif</div>@endforeach</td></tr>
                            @endforeach
                        </tbody></table>
                    @endif
                    <div class="fila" style="margin-top:.5rem">
                        <label for="rubrica-asociada" style="margin:0">Rúbrica de la pregunta</label>
                        <select id="rubrica-asociada" x-on:change="$wire.asociarRubrica($event.target.value ? parseInt($event.target.value) : null)" style="max-width:24rem">
                            <option value="">Sin rúbrica: puntaje libre</option>
                            @foreach ($rubricas as $ru)<option value="{{ $ru->id }}" @selected($rubrica?->id === $ru->id)>{{ $ru->titulo }}</option>@endforeach
                        </select>
                        <a href="{{ route('rubricas.index', ['materia' => $item->pregunta->materia_id, 'pregunta' => $item->pregunta_id]) }}#generar">Generar una con IA</a>
                    </div>
                </details>
                <details style="margin-top:.6rem">
                    <summary>Comentarios frecuentes <span class="suave">({{ $frecuentes->count() }})</span></summary>
                    <p class="suave" style="margin:.3rem 0">Frases de un clic para lo que se repite («falta justificar», «confunde requisito con restricción»). Se insertan en el comentario y se pueden editar.</p>
                    <ul style="margin:.2rem 0">
                        @foreach ($frecuentes as $f)
                            <li>{{ $f->texto }} <span class="suave">· {{ $f->pregunta_id ? 'esta pregunta' : 'toda la materia' }} · usado {{ $f->usos }} vez/veces</span>
                                <button type="button" class="enlace" wire:click="eliminarFrecuente({{ $f->id }})" wire:confirm="¿Quitar este comentario frecuente?">Quitar</button></li>
                        @endforeach
                    </ul>
                    <div class="fila">
                        <label for="nuevo-frecuente" class="sr-only">Nuevo comentario frecuente</label>
                        <input id="nuevo-frecuente" type="text" wire:model="nuevoFrecuente" maxlength="300" placeholder="Falta un ejemplo propio" style="flex:1">
                        <select wire:model="alcanceFrecuente" aria-label="Para qué preguntas"><option value="pregunta">Para esta pregunta</option><option value="materia">Para toda la materia</option></select>
                        <button type="button" class="boton boton-sec boton-chico" wire:click="guardarFrecuente">Agregar</button>
                    </div>
                </details>
                <div class="fila" style="margin-top:.6rem">
                    <button type="button" class="boton boton-sec boton-chico" wire:click="proponerComentarios" wire:loading.attr="disabled" wire:target="proponerComentarios" @disabled(! $sinComentario)>
                        <span wire:loading.remove wire:target="proponerComentarios">Proponer comentarios con IA ({{ $sinComentario }})</span>
                        <span wire:loading wire:target="proponerComentarios">La IA está redactando… con un modelo local puede tardar</span>
                    </button>
                    <span class="suave">Para las respuestas ya calificadas sin comentario. Quedan como borrador hasta que usted los use.</span>
                </div>
                <div class="fila" style="margin-top:.4rem">
                    <button type="button" class="boton boton-sec boton-chico" wire:click="sugerirPuntajes" wire:loading.attr="disabled" wire:target="sugerirPuntajes" @disabled(! $sinSugerencia)>
                        <span wire:loading.remove wire:target="sugerirPuntajes">Sugerir puntajes con IA ({{ $sinSugerencia }})</span>
                        <span wire:loading wire:target="sugerirPuntajes">La IA está leyendo las respuestas… con un modelo local puede tardar</span>
                    </button>
                    <span class="suave">Para las respuestas sin calificar. Solo con un motor local; nada cuenta hasta que usted lo acepte.</span>
                </div>
                @if ($calibracion['sugeridas'])
                    <p class="suave calibracion-ia" style="margin:.4rem 0 0">Sugerencias de la IA en esta pregunta: {{ $calibracion['sugeridas'] }} ·
                        aceptó tal cual {{ $calibracion['aceptadas'] }} · cambió {{ $calibracion['cambiadas'] }}@if ($calibracion['diferencia'] !== null) (diferencia media {{ $calibracion['diferencia'] }})@endif ·
                        descartó {{ $calibracion['descartadas'] }}@if ($calibracion['pendientes']) · {{ $calibracion['pendientes'] }} por decidir @endif</p>
                @endif
                @if ($avisoIa)<div @class(['aviso', 'aviso-error' => $avisoIaError, 'aviso-info' => ! $avisoIaError]) role="status" style="margin-top:.5rem">{{ $avisoIa }}</div>@endif
            </div>

            @endif

            @foreach ($respuestas as $r)
                @include('livewire._respuesta-abierta', ['r' => $r, 'frecuentes' => $frecuentesPor[$r->artefacto_pregunta_id] ?? collect(), 'numero' => $loop->iteration])
            @endforeach
        @endif
    @endif
</div>
