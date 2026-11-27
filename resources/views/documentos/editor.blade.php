<x-app :titulo="($generacion ? 'Borrador de documento' : ($existente ? 'Editar documento' : 'Nuevo documento')).' — '.$materia->nombre">
    @php($DG = \App\Material\DocumentoGenerado::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('documentos.index', $materia) }}">Guías, manuales y otros documentos — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nuevo') }}</div>
    <h1>{{ $generacion ? 'Borrador generado con IA' : ($existente ? 'Editar documento' : 'Nuevo documento') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif
    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Léalo completo antes de guardarlo.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorDocumento = function (inicial) {
            return {
                d: Object.assign({titulo: '', proposito: '', generador: '', secciones: [], fuentes: []}, inicial),
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                mover(i, k) { const j = i + k; if (j < 0 || j >= this.d.secciones.length) return; const s = this.d.secciones.splice(i, 1)[0]; this.d.secciones.splice(j, 0, s); },
                conEjercicio(s) { s.ejercicio = {enunciado: '', plantilla: '', solucion: '', prueba: ''}; if (!s.lenguaje) s.lenguaje = 'javascript'; },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorDocumento(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="contenido" x-bind:value="json()">
            <div class="tarjeta">
                <div class="rejilla" style="grid-template-columns:2fr 1fr">
                    <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255"></div>
                    <div class="campo"><label for="generador">Tipo de documento</label><input id="generador" type="text" x-model="d.generador" maxlength="80" placeholder="Guía de laboratorio"></div>
                </div>
                <div class="campo"><label for="proposito">Para qué sirve</label><input id="proposito" type="text" x-model="d.proposito"></div>
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
                        <textarea x-bind:id="'s-tex-' + i" rows="6" x-model="s.texto" style="font-family:inherit"></textarea></div>
                    <div class="rejilla" style="grid-template-columns:3fr 1fr">
                        <div class="campo"><label x-bind:for="'s-cod-' + i">Código de ejemplo <span class="suave">(opcional)</span></label><textarea x-bind:id="'s-cod-' + i" rows="4" x-model="s.codigo" spellcheck="false"></textarea></div>
                        <div class="campo"><label x-bind:for="'s-len-' + i">Lenguaje</label>
                            <select x-bind:id="'s-len-' + i" x-model="s.lenguaje"><option value="">—</option>@foreach ($DG::LENGUAJES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    </div>
                    <template x-if="s.ejercicio">
                        <fieldset class="tarjeta" style="box-shadow:none;margin:0">
                            <legend>Ejercicio</legend>
                            <div class="campo"><label x-bind:for="'e-enu-' + i">Enunciado</label><input x-bind:id="'e-enu-' + i" type="text" x-model="s.ejercicio.enunciado"></div>
                            <div class="rejilla">
                                <div class="campo"><label x-bind:for="'e-pla-' + i">Código para empezar</label><textarea x-bind:id="'e-pla-' + i" rows="4" x-model="s.ejercicio.plantilla" spellcheck="false"></textarea></div>
                                <div class="campo"><label x-bind:for="'e-sol-' + i">Solución <span class="suave">(no la ve el estudiante)</span></label><textarea x-bind:id="'e-sol-' + i" rows="4" x-model="s.ejercicio.solucion" spellcheck="false"></textarea></div>
                            </div>
                            <div class="campo"><label x-bind:for="'e-pru-' + i">Prueba <span class="suave">(expresión que da true si el código resuelve el ejercicio)</span></label>
                                <input x-bind:id="'e-pru-' + i" type="text" x-model="s.ejercicio.prueba" spellcheck="false" placeholder="sumar(2, 3) === 5"></div>
                            <button type="button" class="enlace" x-on:click="s.ejercicio = null">Quitar el ejercicio</button>
                        </fieldset>
                    </template>
                    <button type="button" class="enlace" x-show="! s.ejercicio" x-on:click="conEjercicio(s)">+ ejercicio que se comprueba solo</button>
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="d.secciones.push({titulo: '', texto: '', codigo: '', lenguaje: '', ejercicio: null})">Agregar sección</button></p>
            <div class="tarjeta campo"><label for="fuentes">Fuentes <span class="suave">(una por línea)</span></label>
                <textarea id="fuentes" rows="3" x-bind:value="lineas(d.fuentes)" x-on:change="d.fuentes = partir($event.target.value)" style="font-family:inherit"></textarea></div>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar documento</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar documento</button>
                    @endif
                    <a href="{{ $existente ? route('documentos.show', $existente) : route('documentos.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>
        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('documentos.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que lo corrija</button></form>@endif
                <form method="POST" action="{{ route('documentos.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('documentos.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar este documento?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar documento</button></form>
        @endif
    </div>
    @if ($existente instanceof \Illuminate\Database\Eloquent\Model)@include('versiones._lista', ['modelo' => $existente])@endif
</x-app>
