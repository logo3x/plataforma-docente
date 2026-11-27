<x-app :titulo="($generacion ? 'Borrador de unidad didáctica' : ($existente ? 'Editar unidad didáctica' : 'Nueva unidad didáctica')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('unidades.index', $materia) }}">Unidades didácticas — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nueva') }}</div>
    <h1>{{ $generacion ? 'Borrador de unidad didáctica generado con IA' : ($existente ? 'Editar unidad didáctica' : 'Nueva unidad didáctica') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>La unidad tiene {{ count($errores) }} observación(es) que impiden guardarla:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El borrador es válido. Revise los tiempos y las actividades: la IA no conoce su salón ni su grupo.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorUnidad = function (inicial) {
            const sesionVacia = (dur) => ({titulo: '', temas: [], objetivo: '', duracion: dur || '120', inicio: '', desarrollo: '', cierre: '', recursos: [], evaluacion: {tipo: 'formativa', descripcion: ''}});
            return {
                u: Object.assign({titulo: '', proposito: '', resultados: [], previos: [], sesiones: [], evaluacion: []}, inicial),
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                minutos() { return this.u.sesiones.reduce((s, x) => s + (parseInt(x.duracion) || 0), 0); },
                agregarSesion() { this.u.sesiones.push(sesionVacia(this.u.sesiones.at(-1)?.duracion)); },
                quitarSesion(i) { if (confirm('¿Quitar esta sesión?')) this.u.sesiones.splice(i, 1); },
                mover(i, d) { const j = i + d; if (j < 0 || j >= this.u.sesiones.length) return; const s = this.u.sesiones.splice(i, 1)[0]; this.u.sesiones.splice(j, 0, s); },
                agregarEvaluacion() { this.u.evaluacion.push({actividad: '', tipo: 'quiz', descripcion: ''}); },
                json() { return JSON.stringify(this.u); },
            };
        };
    </script>

    <div x-data="editorUnidad(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="unidad" x-bind:value="json()">
            <div class="tarjeta">
                <div class="rejilla">
                    <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="u.titulo" required maxlength="255"></div>
                    <div class="campo"><label for="temario_item_id">Unidad del temario</label>
                        <select id="temario_item_id" name="temario_item_id">
                            <option value="">— Ninguna —</option>
                            @foreach ($unidades as $un)<option value="{{ $un->id }}" @selected($unidadId === $un->id)>{{ $un->titulo }}</option>@endforeach
                        </select></div>
                </div>
                <div class="campo"><label for="proposito">Propósito</label><textarea id="proposito" rows="2" x-model="u.proposito"></textarea></div>
                <div class="rejilla">
                    <div class="campo"><label for="resultados">Resultados de aprendizaje <span class="suave">(uno por línea)</span></label>
                        <textarea id="resultados" rows="3" x-bind:value="lineas(u.resultados)" x-on:change="u.resultados = partir($event.target.value)"></textarea></div>
                    <div class="campo"><label for="previos">Conocimientos previos <span class="suave">(uno por línea)</span></label>
                        <textarea id="previos" rows="3" x-bind:value="lineas(u.previos)" x-on:change="u.previos = partir($event.target.value)"></textarea></div>
                </div>
                <p class="suave" role="status" style="margin:0" x-text="u.sesiones.length + ' sesión(es) · ' + minutos() + ' minutos en total (' + (Math.round(minutos() / 6) / 10) + ' horas)'"></p>
            </div>

            <template x-for="(s, i) in u.sesiones" :key="i">
                <section class="tarjeta">
                    <div class="fila" style="justify-content:space-between">
                        <h2 style="margin:0" x-text="'Sesión ' + (i + 1)"></h2>
                        <div class="fila">
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(i, -1)" x-bind:aria-label="'Subir la sesión ' + (i + 1)">↑</button>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(i, 1)" x-bind:aria-label="'Bajar la sesión ' + (i + 1)">↓</button>
                            <button type="button" class="enlace" x-on:click="quitarSesion(i)" x-bind:aria-label="'Quitar la sesión ' + (i + 1)">Quitar</button>
                        </div>
                    </div>
                    <div class="rejilla" style="grid-template-columns:1fr 8rem">
                        <div class="campo"><label x-bind:for="'s-titulo-' + i">Título</label><input x-bind:id="'s-titulo-' + i" type="text" x-model="s.titulo"></div>
                        <div class="campo"><label x-bind:for="'s-dur-' + i">Minutos</label><input x-bind:id="'s-dur-' + i" type="number" min="10" max="600" step="5" x-model="s.duracion"></div>
                    </div>
                    <div class="rejilla">
                        <div class="campo"><label x-bind:for="'s-obj-' + i">Objetivo</label><textarea x-bind:id="'s-obj-' + i" rows="2" x-model="s.objetivo" placeholder="Implementar una pila con un arreglo y usarla para validar paréntesis"></textarea></div>
                        <div class="campo"><label x-bind:for="'s-temas-' + i">Temas del temario <span class="suave">(uno por línea)</span></label>
                            <textarea x-bind:id="'s-temas-' + i" rows="2" x-bind:value="lineas(s.temas)" x-on:change="s.temas = partir($event.target.value)"></textarea></div>
                    </div>
                    <div class="campo"><label x-bind:for="'s-ini-' + i">Inicio</label><textarea x-bind:id="'s-ini-' + i" rows="2" x-model="s.inicio"></textarea></div>
                    <div class="campo"><label x-bind:for="'s-des-' + i">Desarrollo</label><textarea x-bind:id="'s-des-' + i" rows="4" x-model="s.desarrollo"></textarea></div>
                    <div class="campo"><label x-bind:for="'s-cie-' + i">Cierre</label><textarea x-bind:id="'s-cie-' + i" rows="2" x-model="s.cierre"></textarea></div>
                    <div class="rejilla">
                        <div class="campo"><label x-bind:for="'s-rec-' + i">Recursos <span class="suave">(uno por línea)</span></label>
                            <textarea x-bind:id="'s-rec-' + i" rows="2" x-bind:value="lineas(s.recursos)" x-on:change="s.recursos = partir($event.target.value)"></textarea></div>
                        <div class="campo"><label x-bind:for="'s-evt-' + i">Evaluación de la sesión</label>
                            <select x-bind:id="'s-evt-' + i" x-model="s.evaluacion.tipo">
                                @foreach (\App\Planificacion\UnidadesDidacticas::EVALUACION_SESION as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select>
                            <input type="text" x-model="s.evaluacion.descripcion" x-bind:aria-label="'Descripción de la evaluación de la sesión ' + (i + 1)" placeholder="Ticket de salida: tres casos de prueba" style="margin-top:.3rem"></div>
                    </div>
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="agregarSesion()">Agregar sesión</button></p>

            <section class="tarjeta">
                <h2 style="margin-top:0">Evaluación de la unidad</h2>
                <template x-for="(e, k) in u.evaluacion" :key="k">
                    <div class="rejilla" style="grid-template-columns:1fr 11rem 2fr auto;align-items:end;margin-bottom:.4rem">
                        <div class="campo"><label x-bind:for="'e-act-' + k">Actividad</label><input x-bind:id="'e-act-' + k" type="text" x-model="e.actividad"></div>
                        <div class="campo"><label x-bind:for="'e-tipo-' + k">Tipo</label>
                            <select x-bind:id="'e-tipo-' + k" x-model="e.tipo">@foreach (\App\Planificacion\UnidadesDidacticas::TIPOS_ACTIVIDAD as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="campo"><label x-bind:for="'e-desc-' + k">Qué evalúa</label><input x-bind:id="'e-desc-' + k" type="text" x-model="e.descripcion"></div>
                        <div class="campo"><button type="button" class="enlace" x-on:click="u.evaluacion.splice(k, 1)">Quitar</button></div>
                    </div>
                </template>
                <button type="button" class="boton boton-sec boton-chico" x-on:click="agregarEvaluacion()">Agregar actividad de evaluación</button>
            </section>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar unidad</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar unidad</button>
                    @endif
                    <a href="{{ $existente ? route('unidades.show', $existente) : route('unidades.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('unidades.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que la corrija</button></form>@endif
                <form method="POST" action="{{ route('unidades.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('unidades.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar la unidad didáctica?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar unidad didáctica</button>
            </form>
        @endif
    </div>
    @if ($existente instanceof \Illuminate\Database\Eloquent\Model)@include('versiones._lista', ['modelo' => $existente])@endif
</x-app>
