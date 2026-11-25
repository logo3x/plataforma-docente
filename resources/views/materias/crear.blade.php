<x-app titulo="Nueva materia">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › Nueva materia</div>
    <h1>Nueva materia</h1>
    <p class="suave">La materia guarda lo estable (temario, contenido). El periodo trae su lista, sus cortes y sus notas.
        La estructura sugerida se puede cambiar libremente.</p>

    @if ($errors->any())
        <div class="aviso aviso-error" role="alert">
            <ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('materias.store') }}"
          x-data="altaMateria(@js($plantillas), @js(old('divisiones')), @js(old('nivel', 'universidad')))">
        @csrf
        <div class="tarjeta">
            <h2 style="margin-top:0">Materia y periodo</h2>
            <div class="rejilla">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" name="nombre" type="text" value="{{ old('nombre') }}" required maxlength="150">
                </div>
                <div class="campo">
                    <label for="codigo">Código <span class="suave">(opcional)</span></label>
                    <input id="codigo" name="codigo" type="text" value="{{ old('codigo') }}" maxlength="30">
                </div>
                <div class="campo">
                    <label for="periodo">Periodo</label>
                    <input id="periodo" name="periodo" type="text" value="{{ old('periodo', now()->year.(now()->month <= 6 ? 'A' : 'B')) }}" required maxlength="20">
                </div>
                <div class="campo">
                    <label for="grupos">Grupos <span class="suave">(separados por coma)</span></label>
                    <input id="grupos" name="grupos" type="text" value="{{ old('grupos', 'Diurno, Nocturno') }}" maxlength="300">
                </div>
            </div>
        </div>

        <div class="tarjeta">
            <h2 style="margin-top:0">Escala de notas</h2>
            <div class="rejilla">
                <div class="campo"><label for="escala_min">Mínima</label><input id="escala_min" name="escala_min" type="number" step="0.1" value="{{ old('escala_min', '0') }}" required></div>
                <div class="campo"><label for="escala_max">Máxima</label><input id="escala_max" name="escala_max" type="number" step="0.1" value="{{ old('escala_max', '5') }}" required></div>
                <div class="campo"><label for="nota_aprobatoria">Aprueba con</label><input id="nota_aprobatoria" name="nota_aprobatoria" type="number" step="0.1" value="{{ old('nota_aprobatoria', '3') }}" required></div>
                <div class="campo"><label for="decimales">Decimales al mostrar</label>
                    <select id="decimales" name="decimales">
                        @foreach ([0, 1, 2] as $d)<option value="{{ $d }}" @selected(old('decimales', 1) == $d)>{{ $d }}</option>@endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="tarjeta">
            <h2 style="margin-top:0">Divisiones del periodo</h2>
            <div class="campo" style="max-width: 320px">
                <label for="nivel">Nivel educativo (plantilla sugerida)</label>
                <select id="nivel" name="nivel" x-model="nivel" x-on:change="cargar()">
                    <option value="universidad">Universidad — 3 cortes 30/30/40</option>
                    <option value="colegio">Colegio — 4 periodos</option>
                    <option value="otro">Otro — estructura libre</option>
                </select>
            </div>

            <template x-for="(d, i) in divisiones" :key="i">
                <fieldset class="tarjeta" style="box-shadow:none">
                    <legend class="sr-only" x-text="'División ' + (i + 1)"></legend>
                    <div class="fila">
                        <div style="flex:2"><label :for="'dn'+i">Nombre</label><input :id="'dn'+i" type="text" :name="`divisiones[${i}][nombre]`" x-model="d.nombre" required></div>
                        <div style="flex:1"><label :for="'dp'+i">Peso (%)</label><input :id="'dp'+i" type="number" step="0.01" :name="`divisiones[${i}][peso]`" x-model="d.peso" required></div>
                        <button type="button" class="boton boton-sec boton-chico" x-on:click="divisiones.splice(i, 1)" x-show="divisiones.length > 1">Quitar</button>
                    </div>
                    <p class="suave" style="margin:.5rem 0 .25rem">Componentes</p>
                    <template x-for="(c, j) in d.componentes" :key="j">
                        <div class="fila" style="margin-bottom:.4rem">
                            <input type="text" :aria-label="'Nombre del componente ' + (j+1)" :name="`divisiones[${i}][componentes][${j}][nombre]`" x-model="c.nombre" required style="flex:2">
                            <input type="number" step="0.01" :aria-label="'Peso en % del componente ' + (j+1)" :name="`divisiones[${i}][componentes][${j}][peso]`" x-model="c.peso" required style="flex:1">
                            <button type="button" class="enlace" x-on:click="d.componentes.splice(j, 1)" x-show="d.componentes.length > 1">quitar</button>
                        </div>
                    </template>
                    <div class="fila">
                        <button type="button" class="enlace" x-on:click="d.componentes.push({nombre: '', peso: 0})">+ componente</button>
                        <span :class="suma(d.componentes) === 100 ? 'suave' : 'error'" x-text="'Suma: ' + suma(d.componentes) + ' %'"></span>
                    </div>
                </fieldset>
            </template>
            <div class="fila">
                <button type="button" class="boton boton-sec boton-chico" x-on:click="divisiones.push({nombre: 'Nueva división', peso: 0, componentes: [{nombre: 'Actividades', peso: 100}]})">+ división</button>
                <strong :class="suma(divisiones) === 100 ? '' : 'error'" x-text="'Suma de divisiones: ' + suma(divisiones) + ' % (debe ser 100 %)'"></strong>
            </div>
        </div>

        <button class="boton" type="submit">Crear materia</button>
    </form>

    <script>
        function altaMateria(plantillas, anteriores, nivel) {
            const aPorcentaje = (lista) => lista.map(d => ({
                nombre: d.nombre,
                peso: Math.round(parseFloat(d.peso) * 10000) / 100,
                componentes: d.componentes.map(c => ({ nombre: c.nombre, peso: Math.round(parseFloat(c.peso) * 10000) / 100 })),
            }));
            return {
                nivel,
                divisiones: anteriores
                    ? Object.values(anteriores).map(d => ({ ...d, componentes: Object.values(d.componentes) }))
                    : aPorcentaje(plantillas[nivel]),
                cargar() { this.divisiones = aPorcentaje(plantillas[this.nivel]); },
                suma(lista) { return Math.round(lista.reduce((s, x) => s + (parseFloat(x.peso) || 0), 0) * 100) / 100; },
            };
        }
    </script>
</x-app>
