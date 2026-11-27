<x-app :titulo="($generacion ? 'Borrador de ideas de proyectos' : ($existente ? 'Editar ideas de proyectos' : 'Nuevas ideas de proyectos')).' — '.$materia->nombre">
    @php($IP = \App\Material\IdeasProyecto::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('proyectos.index', $materia) }}">Ideas de proyectos — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nuevas') }}</div>
    <h1>{{ $generacion ? 'Borrador de ideas de proyectos generado con IA' : ($existente ? 'Editar ideas de proyectos' : 'Nuevas ideas de proyectos') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Revise que el alcance quepa en el tiempo y que no pida lo que el grupo no ha visto.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorProyectos = function (inicial) {
            return {
                d: Object.assign({titulo: '', proposito: '', proyectos: []}, inicial),
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                agregar() { this.d.proyectos.push({titulo: '', resumen: '', problema: '', temas: [], dificultad: 'media', integrantes: '2-3', semanas: '4',
                    entregables: [], hitos: [{semana: '4', entrega: ''}], criterios: [], extension: '', notas_docente: ''}); },
                quitar(i) { if (confirm('¿Quitar este proyecto?')) this.d.proyectos.splice(i, 1); },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorProyectos(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="contenido" x-bind:value="json()">
            <div class="tarjeta rejilla">
                <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255" placeholder="Proyectos del segundo corte"></div>
                <div class="campo"><label for="proposito">Qué se busca</label><input id="proposito" type="text" x-model="d.proposito"></div>
            </div>
            <template x-for="(p, i) in d.proyectos" :key="i">
                <section class="tarjeta">
                    <div class="fila" style="justify-content:space-between">
                        <h2 style="margin:0" x-text="'Proyecto ' + (i + 1)"></h2>
                        <button type="button" class="enlace" x-on:click="quitar(i)" x-bind:aria-label="'Quitar el proyecto ' + (i + 1)">Quitar</button>
                    </div>
                    <div class="rejilla" style="grid-template-columns:2fr 8rem 7rem 7rem">
                        <div class="campo"><label x-bind:for="'p-tit-' + i">Título</label><input x-bind:id="'p-tit-' + i" type="text" x-model="p.titulo"></div>
                        <div class="campo"><label x-bind:for="'p-dif-' + i">Dificultad</label>
                            <select x-bind:id="'p-dif-' + i" x-model="p.dificultad">@foreach ($IP::DIFICULTADES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="campo"><label x-bind:for="'p-int-' + i">Integrantes</label><input x-bind:id="'p-int-' + i" type="text" x-model="p.integrantes" placeholder="2-3"></div>
                        <div class="campo"><label x-bind:for="'p-sem-' + i">Semanas</label><input x-bind:id="'p-sem-' + i" type="number" min="1" max="{{ $IP::MAX_SEMANAS }}" x-model="p.semanas"></div>
                    </div>
                    <div class="campo"><label x-bind:for="'p-res-' + i">Resumen</label><input x-bind:id="'p-res-' + i" type="text" x-model="p.resumen"></div>
                    <div class="campo"><label x-bind:for="'p-pro-' + i">Problema o necesidad que resuelve</label><textarea x-bind:id="'p-pro-' + i" rows="3" x-model="p.problema" style="font-family:inherit"></textarea></div>
                    <div class="rejilla">
                        <div class="campo"><label x-bind:for="'p-tem-' + i">Temas que integra <span class="suave">(uno por línea)</span></label>
                            <textarea x-bind:id="'p-tem-' + i" rows="3" x-bind:value="lineas(p.temas)" x-on:change="p.temas = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                        <div class="campo"><label x-bind:for="'p-ent-' + i">Entregables <span class="suave">(uno por línea)</span></label>
                            <textarea x-bind:id="'p-ent-' + i" rows="3" x-bind:value="lineas(p.entregables)" x-on:change="p.entregables = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                        <div class="campo"><label x-bind:for="'p-cri-' + i">Criterios de evaluación <span class="suave">(uno por línea)</span></label>
                            <textarea x-bind:id="'p-cri-' + i" rows="3" x-bind:value="lineas(p.criterios)" x-on:change="p.criterios = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                    </div>
                    <fieldset style="border:0;padding:0;margin:0"><legend style="font-weight:600">Hitos</legend>
                        <template x-for="(h, j) in p.hitos" :key="j">
                            <div class="fila" style="margin-bottom:.3rem">
                                <label style="margin:0" x-bind:for="'h-sem-' + i + '-' + j">Semana</label>
                                <input x-bind:id="'h-sem-' + i + '-' + j" type="number" min="1" style="width:5rem" x-model="h.semana">
                                <input type="text" x-model="h.entrega" x-bind:aria-label="'Entrega del hito ' + (j + 1)" placeholder="Qué se entrega" style="flex:1">
                                <button type="button" class="enlace" x-on:click="p.hitos.splice(j, 1)" x-bind:aria-label="'Quitar el hito ' + (j + 1)">Quitar</button>
                            </div>
                        </template>
                        <button type="button" class="boton boton-sec boton-chico" x-on:click="p.hitos.push({semana: p.semanas, entrega: ''})">Agregar hito</button>
                    </fieldset>
                    <div class="rejilla" style="margin-top:.6rem">
                        <div class="campo"><label x-bind:for="'p-ext-' + i">Reto adicional <span class="suave">(opcional)</span></label><textarea x-bind:id="'p-ext-' + i" rows="2" x-model="p.extension" style="font-family:inherit"></textarea></div>
                        <div class="campo"><label x-bind:for="'p-not-' + i">Notas para el docente <span class="suave">(no las ve el estudiante)</span></label><textarea x-bind:id="'p-not-' + i" rows="2" x-model="p.notas_docente" style="font-family:inherit"></textarea></div>
                    </div>
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="agregar()">Agregar proyecto</button></p>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar ideas</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar ideas</button>
                    @endif
                    <a href="{{ $existente ? route('proyectos.show', $existente) : route('proyectos.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('proyectos.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que las corrija</button></form>@endif
                <form method="POST" action="{{ route('proyectos.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('proyectos.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar estas ideas?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar ideas</button>
            </form>
        @endif
    </div>
    @if ($existente instanceof \Illuminate\Database\Eloquent\Model)@include('versiones._lista', ['modelo' => $existente])@endif
</x-app>
