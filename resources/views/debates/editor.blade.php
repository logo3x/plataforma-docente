<x-app :titulo="($generacion ? 'Borrador de preguntas de debate' : ($existente ? 'Editar preguntas de debate' : 'Nuevas preguntas de debate')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('debates.index', $materia) }}">Preguntas de debate — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nuevas') }}</div>
    <h1>{{ $generacion ? 'Borrador de preguntas de debate generado con IA' : ($existente ? 'Editar preguntas de debate' : 'Nuevas preguntas de debate') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Revise que cada pregunta de verdad admita posturas y que el caso sea creíble para su grupo.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorDebate = function (inicial) {
            return {
                d: Object.assign({titulo: '', proposito: '', preguntas: []}, inicial),
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                minutos() { return this.d.preguntas.reduce((s, p) => s + (parseInt(p.minutos) || 0), 0); },
                agregarPregunta() { this.d.preguntas.push({pregunta: '', tema: '', enfoque: 'caso', contexto: '', dinamica: 'grupos', minutos: '15',
                    posturas: [{postura: '', argumentos: []}, {postura: '', argumentos: []}], seguimiento: [], cierre: ''}); },
                quitarPregunta(i) { if (confirm('¿Quitar esta pregunta?')) this.d.preguntas.splice(i, 1); },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorDebate(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="contenido" x-bind:value="json()">
            <div class="tarjeta">
                <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255" placeholder="Ética en el manejo de datos"></div>
                <div class="campo"><label for="proposito">Qué se busca con el debate</label><input id="proposito" type="text" x-model="d.proposito"></div>
                <p class="suave" role="status" style="margin:0" x-text="d.preguntas.length + ' pregunta(s) · ' + minutos() + ' minutos en total'"></p>
            </div>

            <datalist id="temas-temario">@foreach ($temas as $t)<option value="{{ $t }}">@endforeach</datalist>
            <template x-for="(p, i) in d.preguntas" :key="i">
                <section class="tarjeta">
                    <div class="fila" style="justify-content:space-between">
                        <h2 style="margin:0" x-text="'Pregunta ' + (i + 1)"></h2>
                        <button type="button" class="enlace" x-on:click="quitarPregunta(i)" x-bind:aria-label="'Quitar la pregunta ' + (i + 1)">Quitar</button>
                    </div>
                    <div class="campo"><label x-bind:for="'p-preg-' + i">Pregunta</label>
                        <input x-bind:id="'p-preg-' + i" type="text" x-model="p.pregunta" placeholder="¿Debe una empresa usar los datos de sus clientes para entrenar su IA sin pedirles permiso?"></div>
                    <div class="rejilla" style="grid-template-columns:2fr 1fr 1fr 7rem">
                        <div class="campo"><label x-bind:for="'p-tema-' + i">Tema</label><input x-bind:id="'p-tema-' + i" type="text" list="temas-temario" x-model="p.tema"></div>
                        <div class="campo"><label x-bind:for="'p-enf-' + i">Enfoque</label>
                            <select x-bind:id="'p-enf-' + i" x-model="p.enfoque">@foreach (\App\Material\PreguntasDebate::ENFOQUES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="campo"><label x-bind:for="'p-din-' + i">Dinámica</label>
                            <select x-bind:id="'p-din-' + i" x-model="p.dinamica">@foreach (\App\Material\PreguntasDebate::DINAMICAS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="campo"><label x-bind:for="'p-min-' + i">Minutos</label><input x-bind:id="'p-min-' + i" type="number" min="5" max="120" step="5" x-model="p.minutos"></div>
                    </div>
                    <div class="campo"><label x-bind:for="'p-ctx-' + i">Caso o contexto <span class="suave">(se proyecta a los estudiantes)</span></label>
                        <textarea x-bind:id="'p-ctx-' + i" rows="3" x-model="p.contexto" style="font-family:inherit"></textarea></div>
                    <fieldset style="border:0;padding:0;margin:0"><legend style="font-weight:600">Posturas <span class="suave">(guía del docente)</span></legend>
                        <template x-for="(po, j) in p.posturas" :key="j">
                            <div class="rejilla" style="grid-template-columns:1fr 1.4fr auto;align-items:start;margin-bottom:.4rem">
                                <input type="text" x-model="po.postura" x-bind:aria-label="'Postura ' + (j + 1) + ' de la pregunta ' + (i + 1)" x-bind:placeholder="'Postura ' + (j + 1)">
                                <textarea rows="2" x-bind:value="lineas(po.argumentos)" x-on:change="po.argumentos = partir($event.target.value)" x-bind:aria-label="'Argumentos de la postura ' + (j + 1) + ', uno por línea'" placeholder="Argumentos, uno por línea" style="font-family:inherit"></textarea>
                                <button type="button" class="enlace" x-on:click="p.posturas.splice(j, 1)" x-bind:aria-label="'Quitar la postura ' + (j + 1)">Quitar</button>
                            </div>
                        </template>
                        <button type="button" class="boton boton-sec boton-chico" x-on:click="p.posturas.push({postura: '', argumentos: []})">Agregar postura</button>
                    </fieldset>
                    <div class="rejilla" style="margin-top:.6rem">
                        <div class="campo"><label x-bind:for="'p-seg-' + i">Preguntas de seguimiento <span class="suave">(una por línea)</span></label>
                            <textarea x-bind:id="'p-seg-' + i" rows="3" x-bind:value="lineas(p.seguimiento)" x-on:change="p.seguimiento = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                        <div class="campo"><label x-bind:for="'p-cie-' + i">Idea que debe quedar al cierre</label>
                            <textarea x-bind:id="'p-cie-' + i" rows="3" x-model="p.cierre" style="font-family:inherit"></textarea></div>
                    </div>
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="agregarPregunta()">Agregar pregunta</button></p>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar preguntas</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar preguntas</button>
                    @endif
                    <a href="{{ $existente ? route('debates.show', $existente) : route('debates.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('debates.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que las corrija</button></form>@endif
                <form method="POST" action="{{ route('debates.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('debates.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar estas preguntas?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar preguntas</button>
            </form>
        @endif
    </div>
    @if ($existente instanceof \Illuminate\Database\Eloquent\Model)@include('versiones._lista', ['modelo' => $existente])@endif
</x-app>
