<x-app :titulo="($generacion ? 'Borrador de ejemplos' : ($existente ? 'Editar ejemplos' : 'Nuevos ejemplos')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('ejemplos.index', $materia) }}">Ejemplos prácticos — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nuevos') }}</div>
    <h1>{{ $generacion ? 'Borrador de ejemplos generado con IA' : ($existente ? 'Editar ejemplos prácticos' : 'Nuevos ejemplos prácticos') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Lea cada solución: la IA puede razonar mal aunque el formato sea correcto.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script src="{{ asset('js/ejecutor.js') }}"></script>
    <script>
        window.editorEjemplos = function (inicial, verificable) {
            const pasoVacio = () => ({explicacion: '', codigo: ''});
            return {
                d: Object.assign({titulo: '', proposito: '', ejemplos: []}, inicial),
                verificable, resultados: {}, verificando: false,
                init() { if (this.verificable) this.verificar(); },
                lineas(a) { return (a || []).join('\n'); },
                partir(t) { return t.split('\n').map(x => x.trim()).filter(x => x !== ''); },
                agregarEjemplo() { this.d.ejemplos.push({titulo: '', tema: '', dificultad: 'media', enunciado: '', pasos: [pasoVacio(), pasoVacio()], respuesta: '', errores: [], practica: '', codigo: '', comprobacion: ''}); },
                quitarEjemplo(i) { if (confirm('¿Quitar este ejemplo?')) { this.d.ejemplos.splice(i, 1); this.resultados = {}; } },
                async verificar() {
                    this.verificando = true; const r = {};
                    for (const [i, e] of this.d.ejemplos.entries()) {
                        if (e.codigo.trim() && e.comprobacion.trim()) r[i] = await window.ejecutarJS(e.codigo, e.comprobacion, 3000);
                    }
                    this.resultados = r; this.verificando = false;
                },
                fallidos() { return Object.entries(this.resultados).filter(([, r]) => !r.ok).map(([i, r]) => ({numero: Number(i) + 1, error: r.error || null})); },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorEjemplos(@js($contenido), @js($materia->codigoVerificable()))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="ejemplos" x-bind:value="json()">
            <div class="tarjeta">
                <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255" placeholder="Pilas en problemas cotidianos"></div>
                <div class="campo"><label for="proposito">Qué aprende el estudiante</label><input id="proposito" type="text" x-model="d.proposito"></div>
                @if ($materia->codigoVerificable())
                    <div class="fila">
                        <button type="button" class="boton boton-sec boton-chico" x-on:click="verificar()" x-bind:disabled="verificando">Verificar el código en el navegador</button>
                        <span role="status" x-text="verificando ? 'Ejecutando…' : (Object.keys(resultados).length ? (fallidos().length ? '⚠ ' + fallidos().length + ' comprobación(es) fallaron' : '✓ Todas las comprobaciones pasan') : 'Ningún ejemplo trae código comprobable')"
                              x-bind:class="Object.keys(resultados).length ? (fallidos().length ? 'estado estado-reprobado' : 'estado estado-aprobado') : 'suave'"></span>
                    </div>
                @endif
            </div>

            <datalist id="temas-temario">@foreach ($temas as $t)<option value="{{ $t }}">@endforeach</datalist>
            <template x-for="(e, i) in d.ejemplos" :key="i">
                <section class="tarjeta">
                    <div class="fila" style="justify-content:space-between">
                        <h2 style="margin:0" x-text="'Ejemplo ' + (i + 1)"></h2>
                        <div class="fila">
                            <template x-if="resultados[i]"><span x-bind:class="resultados[i].ok ? 'estado estado-aprobado' : 'estado estado-reprobado'"
                                x-text="resultados[i].ok ? '✓ El código pasa la comprobación' : '✗ No pasa: ' + (resultados[i].error || 'la comprobación no dio true')"></span></template>
                            <button type="button" class="enlace" x-on:click="quitarEjemplo(i)" x-bind:aria-label="'Quitar el ejemplo ' + (i + 1)">Quitar</button>
                        </div>
                    </div>
                    <div class="rejilla" style="grid-template-columns:2fr 1fr 9rem">
                        <div class="campo"><label x-bind:for="'e-tit-' + i">Título</label><input x-bind:id="'e-tit-' + i" type="text" x-model="e.titulo"></div>
                        <div class="campo"><label x-bind:for="'e-tema-' + i">Tema</label><input x-bind:id="'e-tema-' + i" type="text" list="temas-temario" x-model="e.tema"></div>
                        <div class="campo"><label x-bind:for="'e-dif-' + i">Dificultad</label>
                            <select x-bind:id="'e-dif-' + i" x-model="e.dificultad">@foreach (\App\Material\EjemplosPracticos::DIFICULTADES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    </div>
                    <div class="campo"><label x-bind:for="'e-enu-' + i">Enunciado</label><textarea x-bind:id="'e-enu-' + i" rows="3" x-model="e.enunciado" style="font-family:inherit"></textarea></div>
                    <fieldset style="border:0;padding:0;margin:0"><legend style="font-weight:600">Pasos</legend>
                        <template x-for="(p, j) in e.pasos" :key="j">
                            <div class="rejilla" style="grid-template-columns:1fr 1fr auto;align-items:start;margin-bottom:.4rem">
                                <textarea rows="3" x-model="p.explicacion" x-bind:aria-label="'Explicación del paso ' + (j + 1) + ' del ejemplo ' + (i + 1)" x-bind:placeholder="'Paso ' + (j + 1) + ': qué se decide y por qué'" style="font-family:inherit"></textarea>
                                <textarea rows="3" x-model="p.codigo" x-bind:aria-label="'Código del paso ' + (j + 1) + ' del ejemplo ' + (i + 1)" placeholder="Código del paso (opcional)" spellcheck="false"></textarea>
                                <button type="button" class="enlace" x-on:click="e.pasos.splice(j, 1)" x-bind:aria-label="'Quitar el paso ' + (j + 1)">Quitar</button>
                            </div>
                        </template>
                        <button type="button" class="boton boton-sec boton-chico" x-on:click="e.pasos.push({explicacion: '', codigo: ''})">Agregar paso</button>
                    </fieldset>
                    <div class="campo" style="margin-top:.6rem"><label x-bind:for="'e-res-' + i">Respuesta final</label><textarea x-bind:id="'e-res-' + i" rows="2" x-model="e.respuesta" style="font-family:inherit"></textarea></div>
                    <div class="rejilla">
                        <div class="campo"><label x-bind:for="'e-err-' + i">Errores comunes <span class="suave">(uno por línea)</span></label>
                            <textarea x-bind:id="'e-err-' + i" rows="3" x-bind:value="lineas(e.errores)" x-on:change="e.errores = partir($event.target.value)" style="font-family:inherit"></textarea></div>
                        <div class="campo"><label x-bind:for="'e-pra-' + i">Ejercicio para practicar <span class="suave">(sin resolver)</span></label>
                            <textarea x-bind:id="'e-pra-' + i" rows="3" x-model="e.practica" style="font-family:inherit"></textarea></div>
                    </div>
                    @if ($materia->codigoVerificable())
                        <details x-bind:open="e.codigo !== ''">
                            <summary>Código final y comprobación</summary>
                            <div class="rejilla">
                                <div class="campo"><label x-bind:for="'e-cod-' + i">Código final completo</label><textarea x-bind:id="'e-cod-' + i" rows="6" x-model="e.codigo" spellcheck="false"></textarea></div>
                                <div class="campo"><label x-bind:for="'e-com-' + i">Comprobación <span class="suave">(expresión que da true)</span></label>
                                    <input x-bind:id="'e-com-' + i" type="text" x-model="e.comprobacion" placeholder="invertir('hola') === 'aloh'" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace"></div>
                            </div>
                        </details>
                    @endif
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="agregarEjemplo()">Agregar ejemplo</button></p>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar ejemplos</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar ejemplos</button>
                    @endif
                    <a href="{{ $existente ? route('ejemplos.show', $existente) : route('ejemplos.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                <form method="POST" action="{{ route('ejemplos.corregir', $generacion) }}" @if (! $errores) x-show="fallidos().length" @endif>
                    @csrf<input type="hidden" name="fallidos" x-bind:value="JSON.stringify(fallidos())">
                    <button class="boton boton-sec">Pedir a la IA que los corrija</button>
                </form>
                <form method="POST" action="{{ route('ejemplos.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('ejemplos.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar estos ejemplos?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar ejemplos</button>
            </form>
        @endif
    </div>
</x-app>
