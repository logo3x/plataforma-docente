<div>
    @if ($mensaje)<div class="aviso aviso-info" role="status">{{ $mensaje }}</div>@endif
    @forelse ($unidades as $u)
        <section class="tarjeta" wire:key="u-{{ $u->id }}">
            <div class="fila" style="justify-content:space-between">
                <input type="text" value="{{ $u->titulo }}" aria-label="Título de la unidad" style="font-weight:700;flex:1" x-on:change="$wire.renombrar({{ $u->id }}, $event.target.value)">
                <span class="fila" style="gap:.25rem">
                    @if ($u->origen === 'ia')<span class="alerta-chip" title="Extraído por la IA; revíselo">IA</span>@endif
                    <button class="boton boton-sec boton-chico" wire:click="mover({{ $u->id }}, -1)" aria-label="Subir unidad">↑</button>
                    <button class="boton boton-sec boton-chico" wire:click="mover({{ $u->id }}, 1)" aria-label="Bajar unidad">↓</button>
                    <button class="enlace" wire:click="eliminar({{ $u->id }})" wire:confirm="¿Eliminar la unidad «{{ $u->titulo }}» con todos sus temas?">Eliminar</button>
                </span>
            </div>
            @php($temas = $u->hijos->where('tipo', 'tema'))
            @php($resultados = $u->hijos->where('tipo', 'resultado'))
            <ol style="margin:.5rem 0">
                @foreach ($temas as $t)
                    <li wire:key="t-{{ $t->id }}" style="margin-bottom:.4rem">
                        <div class="fila" style="gap:.25rem">
                            <input type="text" value="{{ $t->titulo }}" aria-label="Título del tema" style="flex:1" x-on:change="$wire.renombrar({{ $t->id }}, $event.target.value)">
                            <button class="boton boton-sec boton-chico" wire:click="mover({{ $t->id }}, -1)" aria-label="Subir tema">↑</button>
                            <button class="boton boton-sec boton-chico" wire:click="mover({{ $t->id }}, 1)" aria-label="Bajar tema">↓</button>
                            <button class="enlace" wire:click="eliminar({{ $t->id }})" wire:confirm="¿Eliminar el tema «{{ $t->titulo }}»?">Eliminar</button>
                        </div>
                        @if ($t->descripcion)<div class="suave" style="white-space:pre-wrap;font-size:.9rem">{{ $t->descripcion }}</div>@endif
                        @if ($t->sugerencia)
                            <div class="aviso aviso-info" style="margin:.3rem 0">
                                <strong>Sugerencia de la IA:</strong> {{ $t->sugerencia }}
                                <div class="fila" style="margin-top:.3rem">
                                    <button class="boton boton-sec boton-chico" wire:click="aceptarSugerencia({{ $t->id }})">Tomarla</button>
                                    <button class="enlace" wire:click="descartarSugerencia({{ $t->id }})">Descartar</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
            <div class="fila"><input type="text" wire:model="nuevoTema.{{ $u->id }}" wire:keydown.enter="agregarHijo({{ $u->id }}, 'tema')" placeholder="Nuevo tema" aria-label="Nuevo tema en {{ $u->titulo }}" style="max-width:28rem">
                <button class="boton boton-sec boton-chico" wire:click="agregarHijo({{ $u->id }}, 'tema')">+ tema</button></div>
            <details style="margin-top:.5rem"><summary>Resultados de aprendizaje ({{ $resultados->count() }})</summary>
                <ul>@foreach ($resultados as $r)<li wire:key="r-{{ $r->id }}">{{ $r->titulo }} <button class="enlace" wire:click="eliminar({{ $r->id }})">quitar</button></li>@endforeach</ul>
                <div class="fila"><input type="text" wire:model="nuevoResultado.{{ $u->id }}" placeholder="El estudiante será capaz de…" aria-label="Nuevo resultado" style="max-width:28rem">
                    <button class="boton boton-sec boton-chico" wire:click="agregarHijo({{ $u->id }}, 'resultado')">+ resultado</button></div>
            </details>
        </section>
    @empty
        <div class="tarjeta vacio"><strong>La materia aún no tiene temario.</strong>Extráigalo del plan curricular con la IA o escríbalo a mano.</div>
    @endforelse
    <div class="fila tarjeta">
        <input type="text" wire:model="nuevaUnidad" wire:keydown.enter="agregarUnidad" placeholder="Unidad 1: Fundamentos" aria-label="Nueva unidad" style="max-width:28rem">
        <button class="boton boton-sec" wire:click="agregarUnidad">+ unidad</button>
    </div>
</div>
