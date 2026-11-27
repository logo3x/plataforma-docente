<x-app :titulo="($generacion ? 'Borrador de texto' : ($existente ? 'Editar texto' : 'Nuevo texto')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('apuntes.index', $materia) }}">Textos y apuntes — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nuevo') }}</div>
    <h1>{{ $generacion ? 'Borrador de texto generado con IA' : ($existente ? 'Editar texto' : 'Nuevo texto o apunte') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Léalo completo: la IA puede afirmar con seguridad algo que no está en su material.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorApunte = function (inicial) {
            return {
                d: Object.assign({titulo: '', proposito: '', secciones: [], resumen: [], preguntas: [], fuentes: []}, inicial),
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                palabras() { return this.d.secciones.reduce((n, s) => n + (s.texto.match(/\S+/g) || []).length, 0); },
                mover(i, k) { const j = i + k; if (j < 0 || j >= this.d.secciones.length) return; const s = this.d.secciones.splice(i, 1)[0]; this.d.secciones.splice(j, 0, s); },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorApunte(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="contenido" x-bind:value="json()">
            <div class="tarjeta">
                <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255"></div>
                <div class="campo"><label for="proposito">Qué va a entender el estudiante</label><input id="proposito" type="text" x-model="d.proposito"></div>
                <p class="suave" role="status" style="margin:0" x-text="d.secciones.length + ' sección(es) · ' + palabras() + ' palabras · unos ' + Math.max(1, Math.ceil(palabras() / {{ \App\Material\TextoInformativo::PALABRAS_MINUTO }})) + ' min de lectura'"></p>
            </div>

            <template x-for="(s, i) in d.secciones" :key="i">
                <section class="tarjeta">
                    <div class="fila" style="justify-content:space-between">
                        <h2 style="margin:0" x-text="'Sección ' + (i + 1)"></h2>
                        <div class="fila">
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(i, -1)" x-bind:aria-label="'Subir la sección ' + (i + 1)">↑</button>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(i, 1)" x-bind:aria-label="'Bajar la sección ' + (i + 1)">↓</button>
                            <button type="button" class="enlace" x-on:click="confirm('¿Quitar esta sección?') && d.secciones.splice(i, 1)" x-bind:aria-label="'Quitar la sección ' + (i + 1)">Quitar</button>
                        </div>
                    </div>
                    <div class="campo"><label x-bind:for="'s-tit-' + i">Título</label><input x-bind:id="'s-tit-' + i" type="text" x-model="s.titulo"></div>
                    <div class="campo"><label x-bind:for="'s-tex-' + i">Texto <span class="suave">(línea en blanco entre párrafos; «- » al inicio para listas)</span></label>
                        <textarea x-bind:id="'s-tex-' + i" rows="8" x-model="s.texto" style="font-family:inherit"></textarea></div>
                    <div class="rejilla">
                        <div class="campo"><label x-bind:for="'s-ide-' + i">Idea clave</label><input x-bind:id="'s-ide-' + i" type="text" x-model="s.idea_clave"></div>
                        <div class="campo"><label x-bind:for="'s-cod-' + i">Código <span class="suave">(opcional)</span></label><textarea x-bind:id="'s-cod-' + i" rows="3" x-model="s.codigo" spellcheck="false"></textarea></div>
                    </div>
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="d.secciones.push({titulo: '', texto: '', idea_clave: '', codigo: ''})">Agregar sección</button></p>

            <section class="tarjeta">
                <div class="rejilla">
                    <div class="campo"><label for="resumen">Ideas clave del texto <span class="suave">(una por línea)</span></label>
                        <textarea id="resumen" rows="4" x-bind:value="lineas(d.resumen)" x-on:change="d.resumen = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                    <div class="campo"><label for="fuentes">Fuentes <span class="suave">(una por línea)</span></label>
                        <textarea id="fuentes" rows="4" x-bind:value="lineas(d.fuentes)" x-on:change="d.fuentes = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                </div>
                <fieldset style="border:0;padding:0;margin:0"><legend style="font-weight:600">Preguntas de comprensión</legend>
                    <template x-for="(p, k) in d.preguntas" :key="k">
                        <div class="rejilla" style="grid-template-columns:1fr 1fr auto;align-items:start;margin-bottom:.4rem">
                            <input type="text" x-model="p.pregunta" x-bind:aria-label="'Pregunta ' + (k + 1)" placeholder="Pregunta">
                            <input type="text" x-model="p.respuesta" x-bind:aria-label="'Respuesta de la pregunta ' + (k + 1)" placeholder="Respuesta breve">
                            <button type="button" class="enlace" x-on:click="d.preguntas.splice(k, 1)" x-bind:aria-label="'Quitar la pregunta ' + (k + 1)">Quitar</button>
                        </div>
                    </template>
                    <button type="button" class="boton boton-sec boton-chico" x-on:click="d.preguntas.push({pregunta: '', respuesta: ''})">Agregar pregunta</button>
                </fieldset>
            </section>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar texto</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar texto</button>
                    @endif
                    <a href="{{ $existente ? route('apuntes.show', $existente) : route('apuntes.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('apuntes.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que lo corrija</button></form>@endif
                <form method="POST" action="{{ route('apuntes.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('apuntes.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar este texto?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar texto</button>
            </form>
        @endif
    </div>
    @if ($existente instanceof \Illuminate\Database\Eloquent\Model)@include('versiones._lista', ['modelo' => $existente])@endif
</x-app>
