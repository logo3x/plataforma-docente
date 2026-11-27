@php($TIPOS = ['unica' => 'Única respuesta', 'multiple' => 'Varias correctas', 'vf' => 'Verdadero o falso', 'emparejar' => 'Emparejar', 'ordenar' => 'Ordenar pasos', 'completar' => 'Completar', 'abierta' => 'Abierta', 'reto' => 'Reto de código'])
<x-app :titulo="($a ? 'Banco de '.$a->sigla : 'Nuevo examen').' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('artefactos.index', $materia) }}">Actividades evaluables — {{ $materia->nombre }}</a> ›
        {{ $a ? 'Banco de '.$a->sigla : 'Nuevo examen' }}</div>
    <h1>{{ $a ? 'Banco de preguntas — '.$a->titulo : 'Nuevo examen desde cero' }}</h1>

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>No se guardó: {{ count($errores) }} observación(es).</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if ($bloqueo)
        <div class="aviso aviso-info" role="status"><strong>Solo lectura.</strong> {{ $bloqueo }}
            <form method="POST" action="{{ route('bancos.duplicar', $a) }}" class="fila" style="margin-top:.5rem">@csrf
                <div class="campo" style="margin:0"><label for="copia-sigla">Sigla de la copia</label><input type="text" id="copia-sigla" name="sigla" value="{{ old('sigla', $a->sigla.'-R') }}" required maxlength="40"></div>
                <div class="campo" style="margin:0;flex:1"><label for="copia-titulo">Título</label><input type="text" id="copia-titulo" name="titulo" value="{{ old('titulo', $a->titulo) }}" required maxlength="255"></div>
                <button class="boton" style="align-self:flex-end">Hacer una copia para editar</button>
            </form>
            @error('sigla')<p class="error" role="alert" style="white-space:pre-line">{{ $message }}</p>@enderror
        </div>
    @endif

    <script src="{{ asset('js/editor-banco.js') }}?v={{ filemtime(public_path('js/editor-banco.js')) }}"></script>
    <div x-data="editorBanco(@js($banco), @js($otras), @js($TIPOS), @js($vistasItems))" class="editor-banco">
        <form method="POST" action="{{ $a ? route('bancos.update', $a) : route('bancos.store', $materia) }}" x-on:submit="enviar($event)">
            @csrf @if ($a) @method('PUT') @endif
            <input type="hidden" name="banco">
            <div class="tarjeta">
                <fieldset @disabled($bloqueo) class="sin-marco"><div class="rejilla" style="grid-template-columns:1fr 12rem 10rem 8rem">
                    <div class="campo"><label for="b-titulo">Título</label><input type="text" id="b-titulo" x-model="b.titulo" required maxlength="255" placeholder="Parcial 2 — Pilas y colas"></div>
                    <div class="campo"><label for="b-sigla">Sigla <span class="suave">(única)</span></label><input type="text" id="b-sigla" x-model="b.sigla" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9\-_.]{1,39}" placeholder="ED-2026B-P2"></div>
                    <div class="campo"><label for="b-tipo">Tipo</label><select id="b-tipo" x-model="b.tipo"><option value="quiz">Quiz</option><option value="parcial">Parcial</option><option value="taller">Taller</option></select></div>
                    <div class="campo"><label for="b-valor">Valor</label><input type="text" id="b-valor" x-model="b.valor" inputmode="decimal" required></div>
                </div></fieldset>

                <div class="fila" style="gap:.4rem">
                <div class="fila" role="tablist" aria-label="Versiones" style="gap:.4rem">
                    <template x-for="v in versiones" :key="v">
                        <button type="button" role="tab" class="boton boton-chico" :class="version === v ? '' : 'boton-sec'" :aria-selected="version === v" x-on:click="version = v">
                            <span x-text="'Versión ' + v + ' · ' + deVersion(v).length + ' ítems · suma ' + suma(v)"></span>
                            <span x-text="cuadra(v) ? ' ✓' : ' ≠ ' + b.valor" :class="cuadra(v) ? '' : 'texto-alerta'"></span>
                        </button>
                    </template>
                    
                </div>
                    <select aria-label="Agregar una versión" x-on:change="if ($event.target.value) { version = $event.target.value; $event.target.value = '' }" class="boton-chico" style="width:auto">
                        <option value="">+ versión…</option>
                        <template x-for="v in ['B', '2A', '2B'].filter(x => !versiones.includes(x))" :key="v"><option :value="v" x-text="v"></option></template>
                    </select>
                </div>
                <p class="suave" style="margin:.5rem 0 0">A y B son el mismo examen con otros datos (a cada estudiante le toca una por su documento); 2A y 2B, un segundo banco para otra jornada.
                    Cada versión debe sumar el valor y tener los mismos tipos con los mismos pesos.@if ($a) La versión B también se puede <a href="{{ route('variantes.create', $a) }}">generar con IA</a>.@endif</p>
                {{-- 5.4.14: el examen equilibrado por tema y dificultad, a la vista mientras se arma --}}
                <details class="equilibrio" x-show="deVersion(version).length">
                    <summary x-text="'Equilibrio por tema y dificultad — versión ' + version"></summary>
                    <table>
                        <thead><tr><th>Tema</th><th class="num">Baja</th><th class="num">Media</th><th class="num">Alta</th><th class="num">Puntos</th></tr></thead>
                        <tbody>
                            <template x-for="fila in equilibrio(version)" :key="fila.tema">
                                <tr><td x-text="fila.tema"></td><td class="num" x-text="fila.baja || '—'"></td><td class="num" x-text="fila.media || '—'"></td>
                                    <td class="num" x-text="fila.alta || '—'"></td><td class="num" x-text="fila.puntos"></td></tr>
                            </template>
                        </tbody>
                    </table>
                    <p class="suave" style="margin:.3rem 0 0" x-show="deVersion(version).some(f => vista(f))" x-text="deVersion(version).filter(f => vista(f)).length + ' de ' + deVersion(version).length + ' preguntas de esta versión ya las respondieron estudiantes de {{ $destino?->periodo }} en otro periodo.'"></p>
                </details>
            </div>

            <datalist id="temas-banco">@foreach ($temas as $t)<option value="{{ $t }}">@endforeach</datalist>

            <template x-for="(f, n) in deVersion(version)" :key="f.uid">
                <section class="tarjeta item-banco">
                    <div class="fila" style="justify-content:space-between;align-items:flex-start">
                        <button type="button" class="enlace resumen-item" x-on:click="f.abierto = !f.abierto" :aria-expanded="f.abierto">
                            <strong x-text="(n + 1) + '.'"></strong> <span x-text="resumen(f)"></span>
                            <span class="suave" x-text="' · ' + tipos[f.tipo] + ' · ' + f.peso + (f.tema ? ' · ' + f.tema : '') + ' · ' + f.dificultad"></span>
                            <span class="marca-vista" x-show="vista(f)" x-text="textoVista(vista(f))"></span>
                        </button>
                        @unless ($bloqueo)<span class="fila" style="gap:.3rem;flex-wrap:nowrap">
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(f, -1)" :aria-label="'Subir la pregunta ' + (n + 1)">↑</button>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="mover(f, 1)" :aria-label="'Bajar la pregunta ' + (n + 1)">↓</button>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="duplicar(f)" :aria-label="'Duplicar la pregunta ' + (n + 1)">Duplicar</button>
                            <button type="button" class="enlace" x-on:click="quitar(f)" :aria-label="'Quitar la pregunta ' + (n + 1)">Quitar</button>
                        </span>@endunless
                    </div>
                    <fieldset x-show="f.abierto" @disabled($bloqueo) class="sin-marco">
                        <div class="rejilla" style="grid-template-columns:12rem 6rem 1fr 8rem 6rem;margin-top:.75rem">
                            <div class="campo"><label :for="'tipo-' + f.uid">Tipo</label>
                                <select :id="'tipo-' + f.uid" x-model="f.tipo">@foreach ($TIPOS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                            <div class="campo"><label :for="'peso-' + f.uid">Peso</label><input type="text" :id="'peso-' + f.uid" x-model="f.peso" inputmode="decimal"></div>
                            <div class="campo"><label :for="'tema-' + f.uid">Tema</label><input type="text" :id="'tema-' + f.uid" x-model="f.tema" list="temas-banco"></div>
                            <div class="campo"><label :for="'dif-' + f.uid">Dificultad</label>
                                <select :id="'dif-' + f.uid" x-model="f.dificultad"><option value="baja">Baja</option><option value="media">Media</option><option value="alta">Alta</option></select></div>
                            <div class="campo"><label :for="'ver-' + f.uid">Versión</label>
                                <select :id="'ver-' + f.uid" x-model="f.version"><option>A</option><option>B</option><option>2A</option><option>2B</option></select></div>
                        </div>
                        <div class="campo"><label :for="'enun-' + f.uid">Enunciado</label><textarea :id="'enun-' + f.uid" rows="3" x-model="f.enunciado" style="font-family:inherit;font-size:1rem"></textarea></div>

                        {{-- Única y varias correctas --}}
                        <fieldset x-show="f.tipo === 'unica' || f.tipo === 'multiple'" class="opciones-banco">
                            <legend x-text="f.tipo === 'unica' ? 'Opciones (marque la correcta)' : 'Opciones (marque todas las correctas)'"></legend>
                            <template x-for="(o, k) in f.ops" :key="k">
                                <div class="fila" style="gap:.5rem;flex-wrap:nowrap;margin-bottom:.35rem">
                                    <input type="radio" x-show="f.tipo === 'unica'" :name="'ok-' + f.uid" :checked="o.ok" x-on:change="marcarUnica(f, o)" :aria-label="'Opción ' + LETRAS[k] + ' es la correcta'">
                                    <input type="checkbox" x-show="f.tipo === 'multiple'" x-model="o.ok" :aria-label="'Opción ' + LETRAS[k] + ' es correcta'">
                                    <strong x-text="LETRAS[k] + ')'"></strong>
                                    <input type="text" x-model="o.t" :aria-label="'Texto de la opción ' + LETRAS[k]" style="flex:1">
                                    <button type="button" class="enlace" x-on:click="f.ops.splice(k, 1)" x-show="f.ops.length > 2" :aria-label="'Quitar la opción ' + LETRAS[k]">✕</button>
                                </div>
                            </template>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="f.ops.push({ t: '', ok: false })" x-show="f.ops.length < 8">Agregar opción</button>
                        </fieldset>

                        <fieldset x-show="f.tipo === 'vf'" class="opciones-banco"><legend>Respuesta correcta</legend>
                            <label style="display:inline;font-weight:400;margin-right:1rem"><input type="radio" value="true" x-model="f.vf"> Verdadero</label>
                            <label style="display:inline;font-weight:400"><input type="radio" value="false" x-model="f.vf"> Falso</label>
                        </fieldset>

                        {{-- Emparejar: la columna derecha se muestra en el orden de aquí; puede tener distractores --}}
                        <div x-show="f.tipo === 'emparejar'" class="rejilla">
                            <fieldset class="opciones-banco"><legend>Columna izquierda y su pareja</legend>
                                <template x-for="(x, k) in f.izq" :key="k">
                                    <div class="fila" style="gap:.4rem;flex-wrap:nowrap;margin-bottom:.35rem">
                                        <strong x-text="(k + 1) + '.'"></strong><input type="text" x-model="x.t" :aria-label="'Elemento ' + (k + 1)" style="flex:1">
                                        <select x-model="x.par" :aria-label="'Pareja del elemento ' + (k + 1)" style="width:5rem"><option value="">—</option>
                                            <template x-for="d in f.der" :key="d.k"><option :value="d.k" x-text="d.k" :selected="x.par === d.k"></option></template></select>
                                        <button type="button" class="enlace" x-on:click="f.izq.splice(k, 1)" :aria-label="'Quitar el elemento ' + (k + 1)">✕</button>
                                    </div>
                                </template>
                                <button type="button" class="boton boton-sec boton-chico" x-on:click="f.izq.push({ t: '', par: '' })">Agregar elemento</button>
                            </fieldset>
                            <fieldset class="opciones-banco"><legend>Columna derecha <span class="suave">(en el orden en que la verá el estudiante; las que sobran son distractores)</span></legend>
                                <template x-for="(d, k) in f.der" :key="d.k">
                                    <div class="fila" style="gap:.4rem;flex-wrap:nowrap;margin-bottom:.35rem">
                                        <strong x-text="d.k + ')'"></strong><input type="text" x-model="d.t" :aria-label="'Opción ' + d.k + ' de la derecha'" style="flex:1">
                                        <button type="button" class="enlace" x-on:click="f.der.splice(k, 1)" :aria-label="'Quitar la opción ' + d.k">✕</button>
                                    </div>
                                </template>
                                <span class="fila" style="gap:.4rem"><button type="button" class="boton boton-sec boton-chico" x-on:click="nuevaDerecha(f)">Agregar</button>
                                    <button type="button" class="boton boton-sec boton-chico" x-on:click="mezclarDerecha(f)">Mezclar el orden</button></span>
                            </fieldset>
                        </div>

                        <fieldset x-show="f.tipo === 'ordenar'" class="opciones-banco"><legend>Pasos en el orden correcto <span class="suave">(el examen los baraja)</span></legend>
                            <template x-for="(p, k) in f.pasos" :key="k">
                                <div class="fila" style="gap:.4rem;flex-wrap:nowrap;margin-bottom:.35rem">
                                    <strong x-text="(k + 1) + '.'"></strong><input type="text" :value="p" x-on:input="f.pasos[k] = $event.target.value" :aria-label="'Paso ' + (k + 1)" style="flex:1">
                                    <button type="button" class="enlace" x-on:click="f.pasos.splice(k, 1)" x-show="f.pasos.length > 2" :aria-label="'Quitar el paso ' + (k + 1)">✕</button>
                                </div>
                            </template>
                            <button type="button" class="boton boton-sec boton-chico" x-on:click="f.pasos.push('')">Agregar paso</button>
                        </fieldset>

                        <div x-show="f.tipo === 'completar'">
                            <div class="campo"><label :for="'pl-' + f.uid">Texto con un <code>___</code> por cada hueco</label><textarea :id="'pl-' + f.uid" rows="3" x-model="f.plantilla" style="font-family:ui-monospace,Consolas,monospace"></textarea></div>
                            <fieldset class="opciones-banco"><legend>Respuesta de cada hueco <span class="suave">(alternativas separadas por <code>|</code>)</span></legend>
                                <template x-for="(h, k) in f.huecos" :key="k">
                                    <div class="fila" style="gap:.4rem;flex-wrap:nowrap;margin-bottom:.35rem">
                                        <strong x-text="'Hueco ' + (k + 1)"></strong><input type="text" :value="h" x-on:input="f.huecos[k] = $event.target.value" :aria-label="'Respuesta del hueco ' + (k + 1)" style="flex:1">
                                        <button type="button" class="enlace" x-on:click="f.huecos.splice(k, 1)" x-show="f.huecos.length > 1" :aria-label="'Quitar el hueco ' + (k + 1)">✕</button>
                                    </div>
                                </template>
                                <button type="button" class="boton boton-sec boton-chico" x-on:click="f.huecos.push('')">Agregar hueco</button>
                                <p role="status" :class="huecosEnPlantilla(f) === f.huecos.length ? 'suave' : 'texto-alerta'"
                                   x-text="'La plantilla tiene ' + huecosEnPlantilla(f) + ' hueco(s) y hay ' + f.huecos.length + ' respuesta(s).'"></p>
                            </fieldset>
                        </div>

                        <div x-show="f.tipo === 'abierta'" class="rejilla" style="grid-template-columns:1fr 1fr 6rem">
                            <div class="campo"><label :for="'ref-' + f.uid">Respuesta de referencia <span class="suave">(solo la ve usted)</span></label><textarea :id="'ref-' + f.uid" rows="3" x-model="f.referencia" style="font-family:inherit"></textarea></div>
                            <div class="campo"><label :for="'rub-' + f.uid">Qué se espera <span class="suave">(criterios breves)</span></label><textarea :id="'rub-' + f.uid" rows="3" x-model="f.rubrica" style="font-family:inherit"></textarea></div>
                            <div class="campo"><label :for="'lin-' + f.uid">Líneas</label><input :id="'lin-' + f.uid" type="number" min="1" max="40" x-model="f.lineas"></div>
                        </div>

                        <div x-show="f.tipo === 'reto'" class="campo">
                            <label :for="'reto-' + f.uid">Reto de código en JSON <span class="suave">(lenguaje, plantilla, casos visibles y verificaciones; vea docs/formato-banco.md)</span></label>
                            <textarea :id="'reto-' + f.uid" rows="12" x-model="f.reto" x-on:blur="validarReto(f)" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;font-size:.85rem"></textarea>
                            <p class="error" role="alert" x-show="errorReto[f.uid]" x-text="errorReto[f.uid]"></p>
                        </div>

                        <div class="campo"><label :for="'jus-' + f.uid">Justificación <span class="suave">(la línea del detalle autoexplicativo)</span></label><input type="text" :id="'jus-' + f.uid" x-model="f.justificacion"></div>
                        @unless ($bloqueo)<button type="button" class="boton boton-sec boton-chico" x-on:click="agregar(f.tipo, f)">Agregar otra pregunta después de esta</button>@endunless
                    </fieldset>
                </section>
            </template>
            <div class="tarjeta vacio" x-show="deVersion(version).length === 0"><strong x-text="'La versión ' + version + ' aún no tiene preguntas.'"></strong>
                Agréguelas con los botones de abajo o tráigalas de otro examen.</div>

            @unless ($bloqueo)
            <div class="fila" style="gap:.4rem;margin:1rem 0">
                <span class="suave">Agregar a la versión <strong x-text="version"></strong>:</span>
                @foreach ($TIPOS as $k => $v)<button type="button" class="boton boton-sec boton-chico" x-on:click="agregar('{{ $k }}')">{{ $v }}</button>@endforeach
            </div>
            @endunless

            @unless ($bloqueo)
                <div class="fila barra-guardar">
                    <button class="boton" type="submit">{{ $a ? 'Validar y guardar el banco' : 'Validar y crear el examen' }}</button>
                    <span class="suave" x-show="versiones.some(v => !cuadra(v))">Hay versiones que no suman el valor: no se podrá guardar.</span>
                    <a href="{{ route('artefactos.index', $materia) }}">Volver</a>
                </div>
            @endunless
        </form>

        @unless ($bloqueo)
            <details class="tarjeta" style="margin-top:1rem">
                <summary><strong>Traer preguntas de otros exámenes de la materia</strong> <span class="suave">({{ $otras->count() }})</span></summary>
                @if ($otras->isEmpty())
                    <p class="suave">Todavía no hay otros exámenes en esta materia.</p>
                @else
                    <div class="campo" style="margin-top:.5rem"><label for="filtro-otras">Buscar por enunciado, tema, tipo o sigla</label><input id="filtro-otras" type="search" x-model="filtro"></div>
                    @if ($otras->contains(fn ($o) => $o['vista']))
                        <label style="font-weight:400"><input type="checkbox" x-model="ocultarVistas"> Ocultar las que ya respondieron estudiantes de {{ $destino?->periodo }} en otro periodo
                            <span class="suave">({{ $otras->filter(fn ($o) => $o['vista'])->count() }}; las no usadas aparecen primero)</span></label>
                    @endif
                    <ul class="lista-otras">
                        <template x-for="(o, k) in otrasFiltradas" :key="k">
                            <li class="fila" style="justify-content:space-between;flex-wrap:nowrap">
                                <span><span x-text="o.item.enunciado.replace(/\s+/g, ' ').slice(0, 140)"></span>
                                    <span class="suave" x-text="' · ' + tipos[o.item.tipo] + ' · ' + (o.item.tema || 'sin tema') + ' · ' + (o.item.dificultad || 'media') + ' · ' + o.origen + ' ' + o.version"></span>
                                    <span class="marca-vista" x-show="o.vista" x-text="textoVista(o.vista)"></span></span>
                                <button type="button" class="boton boton-sec boton-chico" x-on:click="traer(o)" x-text="'Traer a ' + version"></button>
                            </li>
                        </template>
                    </ul>
                    <p class="suave">Se trae una copia: cambiarla aquí no cambia el examen de origen.</p>
                @endif
            </details>
        @endunless
        @if ($a)
            <p style="margin-top:1rem"><a href="{{ route('bancos.descargar', $a) }}">Descargar el banco en JSON</a> <span class="suave">(con las respuestas: guárdelo solo para usted)</span> ·
                <a href="{{ route('configuracion.edit', $a) }}">Configuración del examen</a></p>
        @endif
    </div>
    @if ($a)@include('versiones._lista', ['modelo' => $a])@endif
</x-app>
