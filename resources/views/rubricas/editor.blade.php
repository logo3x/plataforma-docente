<x-app :titulo="($generacion ? 'Borrador de rúbrica' : ($existente ? 'Editar rúbrica' : 'Nueva rúbrica')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('rubricas.index', $materia) }}">Rúbricas — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nueva') }}</div>
    <h1>{{ $generacion ? 'Borrador de rúbrica generado con IA' : ($existente ? 'Editar rúbrica' : 'Nueva rúbrica') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif
    @if ($usada)
        <div class="aviso aviso-info">Esta rúbrica ya se usó para calificar {{ $usada }} respuesta(s). Si la cambia, esos puntajes no se modifican y el informe
            del estudiante conserva los criterios con los que se calificó.</div>
    @endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>La rúbrica tiene {{ count($errores) }} observación(es):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El borrador es válido. Lea cada nivel: la IA puede describir algo que usted no pidió o que el grupo no ha visto.</div>
    @endif

    <script>
        window.editorRubrica = function (inicial) {
            const nivelesBase = () => (this.r.criterios[0]?.niveles ?? [{nombre: 'Excelente', porcentaje: '100'}, {nombre: 'Insuficiente', porcentaje: '0'}])
                .map(n => ({nombre: n.nombre, descripcion: '', porcentaje: n.porcentaje}));
            return {
                r: Object.assign({titulo: '', proposito: '', criterios: []}, inicial),
                maximo: '1',
                init() { this.r.criterios.forEach(c => { c.niveles ??= []; c.descripcion ??= ''; }); },
                suma() { return Math.round(this.r.criterios.reduce((s, c) => s + (parseFloat(String(c.peso).replace(',', '.')) || 0), 0) * 100) / 100; },
                igualar() {
                    const n = this.r.criterios.length; if (!n) return;
                    const base = Math.floor(100 / n);
                    this.r.criterios.forEach((c, i) => c.peso = String(i === n - 1 ? 100 - base * (n - 1) : base));
                },
                agregarCriterio() { this.r.criterios.push({nombre: '', descripcion: '', peso: '0', niveles: nivelesBase.call(this)}); },
                quitarCriterio(i) { if (confirm('¿Quitar este criterio?')) this.r.criterios.splice(i, 1); },
                agregarNivel(c) { c.niveles.push({nombre: '', descripcion: '', porcentaje: ''}); },
                quitarNivel(c, j) { c.niveles.splice(j, 1); },
                valor(c, n) {
                    const v = (parseFloat(String(this.maximo).replace(',', '.')) || 0) * (parseFloat(c.peso) || 0) * (parseFloat(n.porcentaje) || 0) / 10000;
                    return Math.round(v * 100) / 100;
                },
                json() { return JSON.stringify(this.r); },
            };
        };
    </script>

    <div x-data="editorRubrica(@js($rubrica))">
        <form method="POST" action="{{ $accion }}" id="form-rubrica">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="rubrica" x-bind:value="json()">
            <div class="tarjeta">
                <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="r.titulo" required maxlength="255" placeholder="Explicación de requisitos y restricciones"></div>
                <div class="campo"><label for="proposito">Qué califica</label><input id="proposito" type="text" x-model="r.proposito" maxlength="1000" placeholder="Respuesta abierta del parcial 1, pregunta 4"></div>
                <div class="fila">
                    <span role="status" x-bind:class="suma() === 100 ? 'estado estado-aprobado' : 'estado estado-incompleto'"
                          x-text="suma() === 100 ? '✓ Los pesos suman 100 %' : '⚠ Los pesos suman ' + suma() + ' %; deben sumar 100 %'"></span>
                    <button type="button" class="boton boton-sec boton-chico" x-on:click="igualar()">Repartir los pesos por igual</button>
                    <label for="maximo" style="margin:0 0 0 auto">Vista previa para un ítem que vale</label>
                    <input id="maximo" type="text" inputmode="decimal" x-model="maximo" style="width:5rem">
                </div>
            </div>

            <template x-for="(c, i) in r.criterios" :key="i">
                <section class="tarjeta criterio-rubrica">
                    <div class="rejilla" style="grid-template-columns:1fr 7rem auto;align-items:end">
                        <div class="campo"><label x-bind:for="'c-nombre-' + i" x-text="'Criterio ' + (i + 1)"></label>
                            <input x-bind:id="'c-nombre-' + i" type="text" x-model="c.nombre" placeholder="Claridad de la explicación"></div>
                        <div class="campo"><label x-bind:for="'c-peso-' + i">Peso (%)</label>
                            <input x-bind:id="'c-peso-' + i" type="text" inputmode="decimal" x-model="c.peso"></div>
                        <div class="campo"><button type="button" class="enlace" x-on:click="quitarCriterio(i)" x-bind:aria-label="'Quitar criterio ' + (i + 1)">Quitar criterio</button></div>
                    </div>
                    <div class="campo"><label x-bind:for="'c-desc-' + i">Qué evalúa <span class="suave">(opcional)</span></label>
                        <input x-bind:id="'c-desc-' + i" type="text" x-model="c.descripcion"></div>
                    <table>
                        <thead><tr><th style="width:11rem">Nivel</th><th style="width:6rem">% del criterio</th><th>Qué se observa en la respuesta</th><th class="num" style="width:5rem">Puntos</th><th style="width:4rem"></th></tr></thead>
                        <tbody>
                            <template x-for="(n, j) in c.niveles" :key="j">
                                <tr>
                                    <td><input type="text" x-model="n.nombre" x-bind:aria-label="'Nombre del nivel ' + (j + 1) + ' del criterio ' + (i + 1)"></td>
                                    <td><input type="text" inputmode="decimal" x-model="n.porcentaje" x-bind:aria-label="'Porcentaje del nivel ' + (j + 1) + ' del criterio ' + (i + 1)"></td>
                                    <td><textarea rows="2" x-model="n.descripcion" x-bind:aria-label="'Descripción del nivel ' + (j + 1) + ' del criterio ' + (i + 1)"></textarea></td>
                                    <td class="num" x-text="valor(c, n)"></td>
                                    <td><button type="button" class="enlace" x-on:click="quitarNivel(c, j)" x-bind:aria-label="'Quitar el nivel ' + (j + 1)">Quitar</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <button type="button" class="boton boton-sec boton-chico" x-on:click="agregarNivel(c)" style="margin-top:.4rem">Agregar nivel</button>
                </section>
            </template>
            <p><button type="button" class="boton boton-sec" x-on:click="agregarCriterio()">Agregar criterio</button></p>

            <fieldset class="tarjeta" style="border:0">
                <legend style="font-weight:600">Preguntas abiertas que se califican con esta rúbrica</legend>
                @forelse ($abiertas as $p)
                    <label style="font-weight:400;display:block">
                        <input @if ($generacion) type="radio" @else type="checkbox" @endif name="preguntas[]" value="{{ $p->id }}" @checked(in_array($p->id, $seleccionadas, true))>
                        {{ \Illuminate\Support\Str::limit($p->enunciado['texto'] ?? '', 120) }}
                        @if ($p->rubrica && $p->rubrica_id !== $existente?->id)<span class="suave">· hoy usa «{{ $p->rubrica->titulo }}»; se reemplaza</span>@endif
                    </label>
                @empty
                    <p class="suave">La materia aún no tiene preguntas abiertas. Puede guardar la rúbrica y asociarla después.</p>
                @endforelse
                @if ($generacion && $abiertas->isNotEmpty())<label style="font-weight:400;display:block"><input type="radio" name="preguntas[]" value="" @checked($seleccionadas === [])> Ninguna por ahora</label>@endif
            </fieldset>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar rúbrica</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar rúbrica</button>
                    @endif
                    <a href="{{ route('rubricas.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('rubricas.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que la corrija</button></form>@endif
                <form method="POST" action="{{ route('rubricas.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('rubricas.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar la rúbrica? Las respuestas ya calificadas conservan su detalle.')">
                @csrf @method('DELETE')<button class="enlace">Eliminar rúbrica</button>
            </form>
        @endif
    </div>
</x-app>
