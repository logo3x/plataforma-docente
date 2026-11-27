<x-app :titulo="($generacion ? 'Borrador de presentación' : ($existente ? 'Editar presentación' : 'Nueva presentación')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('presentaciones.index', $materia) }}">Presentaciones — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nueva') }}</div>
    <h1>{{ $generacion ? 'Borrador de presentación generado con IA' : ($existente ? 'Editar presentación' : 'Nueva presentación') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Revise que cada diapositiva diga una sola idea y que las notas suenen a usted.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorPresentacion = function (inicial) {
            return {
                d: Object.assign({titulo: '', subtitulo: '', proposito: '', diapositivas: []}, inicial),
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                agregar(i) { this.d.diapositivas.splice(i + 1, 0, {tipo: 'contenido', titulo: '', puntos: [], codigo: '', notas: '', tema: ''}); },
                mover(i, paso) { const j = i + paso; if (j < 0 || j >= this.d.diapositivas.length) return; const a = this.d.diapositivas; [a[i], a[j]] = [a[j], a[i]]; },
                quitar(i) { if (confirm('¿Quitar esta diapositiva?')) this.d.diapositivas.splice(i, 1); },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorPresentacion(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="contenido" x-bind:value="json()">
            <div class="tarjeta">
                <h2 style="margin-top:0">Portada</h2>
                <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255" placeholder="Pilas: el último en llegar, el primero en salir"></div>
                <div class="campo"><label for="subtitulo">Subtítulo</label><input id="subtitulo" type="text" x-model="d.subtitulo" placeholder="Unidad 2 · Estructuras lineales"></div>
                <div class="campo"><label for="proposito">Qué debe lograr la clase <span class="suave">(va en las notas de la portada)</span></label><input id="proposito" type="text" x-model="d.proposito"></div>
                <p class="suave" role="status" style="margin:0" x-text="(d.diapositivas.length + 1) + ' diapositivas con la portada'"></p>
            </div>

            <datalist id="temas-temario">@foreach ($temas as $t)<option value="{{ $t }}">@endforeach</datalist>
            <template x-for="(s, i) in d.diapositivas" :key="i">
                <section class="tarjeta">
                    <div class="fila" style="justify-content:space-between">
                        <h2 style="margin:0" x-text="'Diapositiva ' + (i + 2)"></h2>
                        <span class="fila" style="gap:.4rem">
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(i, -1)" x-bind:aria-label="'Subir la diapositiva ' + (i + 2)">↑</button>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(i, 1)" x-bind:aria-label="'Bajar la diapositiva ' + (i + 2)">↓</button>
                            <button type="button" class="enlace" x-on:click="quitar(i)" x-bind:aria-label="'Quitar la diapositiva ' + (i + 2)">Quitar</button>
                        </span>
                    </div>
                    <div class="rejilla" style="grid-template-columns:12rem 1fr 14rem">
                        <div class="campo"><label x-bind:for="'s-tipo-' + i">Tipo</label>
                            <select x-bind:id="'s-tipo-' + i" x-model="s.tipo">@foreach (\App\Material\Presentacion::TIPOS_DIAPOSITIVA as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="campo"><label x-bind:for="'s-tit-' + i" x-text="s.tipo === 'pregunta' ? 'Pregunta' : 'Título'"></label><input x-bind:id="'s-tit-' + i" type="text" x-model="s.titulo"></div>
                        <div class="campo"><label x-bind:for="'s-tema-' + i">Tema</label><input x-bind:id="'s-tema-' + i" type="text" list="temas-temario" x-model="s.tema"></div>
                    </div>
                    <div class="campo" x-show="s.tipo === 'codigo'"><label x-bind:for="'s-cod-' + i">Código <span class="suave">(15 líneas como máximo)</span></label>
                        <textarea x-bind:id="'s-cod-' + i" rows="6" x-model="s.codigo" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace"></textarea></div>
                    <div class="rejilla">
                        <div class="campo"><label x-bind:for="'s-pun-' + i" x-text="s.tipo === 'pregunta' ? 'Pistas (una por línea, opcional)' : 'Viñetas (una por línea; máximo {{ \App\Material\Presentacion::MAX_VINETAS }}, cortas)'"></label>
                            <textarea x-bind:id="'s-pun-' + i" rows="5" x-bind:value="lineas(s.puntos)" x-on:change="s.puntos = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                        <div class="campo"><label x-bind:for="'s-not-' + i">Notas del orador <span class="suave">(lo que usted dice; no se proyecta)</span></label>
                            <textarea x-bind:id="'s-not-' + i" rows="5" x-model="s.notas" style="font-family:inherit"></textarea></div>
                    </div>
                    <button type="button" class="boton boton-sec boton-chico" x-on:click="agregar(i)">Agregar diapositiva después de esta</button>
                </section>
            </template>
            <p x-show="d.diapositivas.length === 0"><button type="button" class="boton boton-sec" x-on:click="agregar(-1)">Agregar diapositiva</button></p>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar presentación</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar presentación</button>
                    @endif
                    <a href="{{ $existente ? route('presentaciones.show', $existente) : route('presentaciones.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('presentaciones.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que la corrija</button></form>@endif
                <form method="POST" action="{{ route('presentaciones.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('presentaciones.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar esta presentación?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar presentación</button>
            </form>
        @endif
    </div>
</x-app>
