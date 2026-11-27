<x-app :titulo="'Estructura de '.$dictado->materia->nombre.' '.$dictado->periodo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }}</a> › Estructura</div>
    <h1>Estructura de evaluación</h1>
    <p class="suave">Cambie nombres, pesos y fechas, o agregue divisiones y componentes. Las notas ya digitadas no se tocan:
        las definitivas se vuelven a calcular con los pesos nuevos.</p>

    @if ($notas)
        <div class="aviso aviso-info" role="note">Este periodo ya tiene {{ $notas }} {{ $notas === 1 ? 'nota cargada' : 'notas cargadas' }}.
            Cambiar un peso cambia las definitivas que ya vieron los estudiantes. El cambio queda registrado abajo, con cómo estaba antes.</div>
    @endif

    @if ($errors->any())
        <div class="aviso aviso-error" role="alert"><ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('dictados.estructura.guardar', $dictado) }}" x-data="estructuraPeriodo(@js($divisiones))">
        @csrf @method('PUT')
        <template x-for="(d, i) in divisiones" :key="d.clave">
            <fieldset class="tarjeta" style="box-shadow:none">
                <legend x-text="d.nombre || ('División ' + (i + 1))"></legend>
                <input type="hidden" :name="`divisiones[${i}][id]`" :value="d.id ?? ''">
                <div class="fila">
                    <div style="flex:2"><label :for="'dn'+i">Nombre</label><input :id="'dn'+i" type="text" maxlength="60" :name="`divisiones[${i}][nombre]`" x-model="d.nombre" required></div>
                    <div style="flex:1"><label :for="'dp'+i">Peso (%)</label><input :id="'dp'+i" type="number" step="0.01" min="0" :name="`divisiones[${i}][peso]`" x-model="d.peso" required></div>
                    <div style="flex:1"><label :for="'di'+i">Inicia</label><input :id="'di'+i" type="date" :name="`divisiones[${i}][fecha_inicio]`" x-model="d.fecha_inicio"></div>
                    <div style="flex:1"><label :for="'df'+i">Termina</label><input :id="'df'+i" type="date" :name="`divisiones[${i}][fecha_fin]`" x-model="d.fecha_fin"></div>
                    <button type="button" class="boton boton-sec boton-chico" style="align-self:flex-end" x-show="divisiones.length > 1"
                            :disabled="actividades(d) > 0" :title="actividades(d) ? 'Tiene actividades: muévalas antes de quitar la división' : ''"
                            x-on:click="divisiones.splice(i, 1)" x-text="'Quitar ' + (d.nombre || 'división')"></button>
                </div>
                <p class="suave" style="margin:.5rem 0 .25rem">Componentes</p>
                <template x-for="(c, j) in d.componentes" :key="c.clave">
                    <div class="fila-componente">
                        <input type="hidden" :name="`divisiones[${i}][componentes][${j}][id]`" :value="c.id ?? ''">
                        <input type="hidden" :name="`divisiones[${i}][componentes][${j}][actividades]`" :value="c.actividades">
                        <input type="text" maxlength="60" :aria-label="'Nombre del componente ' + (j+1) + ' de ' + d.nombre" :name="`divisiones[${i}][componentes][${j}][nombre]`" x-model="c.nombre" required>
                        <input type="number" step="0.01" min="0" :aria-label="'Peso en % del componente ' + (j+1) + ' de ' + d.nombre" :name="`divisiones[${i}][componentes][${j}][peso]`" x-model="c.peso" required>
                        <span class="suave" x-text="c.actividades ? c.actividades + (c.actividades === 1 ? ' actividad' : ' actividades') : 'sin actividades'"></span>
                        <button type="button" class="enlace" x-show="d.componentes.length > 1 && ! c.actividades" x-on:click="d.componentes.splice(j, 1)" x-text="'quitar ' + (c.nombre || 'componente')"></button>
                    </div>
                </template>
                <div class="fila">
                    <button type="button" class="enlace" x-on:click="d.componentes.push(nuevo({nombre: '', peso: 0, actividades: 0}))">+ componente</button>
                    <span :class="suma(d.componentes) === 100 ? 'suave' : 'error'" x-text="'Suma: ' + suma(d.componentes) + ' %'"></span>
                </div>
            </fieldset>
        </template>
        <div class="fila" style="margin-bottom:1rem">
            <button type="button" class="boton boton-sec boton-chico" x-on:click="divisiones.push(nuevo({nombre: 'Nueva división', peso: 0, fecha_inicio: '', fecha_fin: '', componentes: [nuevo({nombre: 'Actividades', peso: 100, actividades: 0})]}))">+ división</button>
            <strong :class="suma(divisiones) === 100 ? '' : 'error'" x-text="'Suma de divisiones: ' + suma(divisiones) + ' % (debe ser 100 %)'"></strong>
        </div>
        <p class="suave">Para quitar un componente con actividades, muévalas antes a otro componente desde el menú de la columna en el registro de notas.</p>
        <button class="boton" type="submit">Guardar estructura</button>
        <a class="boton boton-sec" href="{{ route('dictados.show', $dictado) }}">Cancelar</a>
    </form>

    @php($esc = $dictado->escala())
    @php($f = fn ($v) => (string) \Brick\Math\BigDecimal::of($v)->strippedOfTrailingZeros())
    <h2>Escala de notas</h2>
    <form method="POST" action="{{ route('dictados.escala.guardar', $dictado) }}" class="tarjeta">
        @csrf @method('PUT')
        @error('escala')<div class="aviso aviso-error" role="alert">{{ $message }}</div>@enderror
        <div class="rejilla">
            <div class="campo"><label for="escala_min">Mínima</label><input id="escala_min" name="escala_min" type="number" step="0.1" value="{{ old('escala_min', $f($esc->minimo)) }}" required @disabled($notas) ></div>
            <div class="campo"><label for="escala_max">Máxima</label><input id="escala_max" name="escala_max" type="number" step="0.1" value="{{ old('escala_max', $f($esc->maximo)) }}" required @disabled($notas)></div>
            <div class="campo"><label for="nota_aprobatoria">Aprueba con</label><input id="nota_aprobatoria" name="nota_aprobatoria" type="number" step="0.01" value="{{ old('nota_aprobatoria', $f($esc->aprobatoria)) }}" required></div>
            <div class="campo"><label for="decimales">Decimales al mostrar</label>
                <select id="decimales" name="decimales">@foreach ([0, 1, 2] as $n)<option value="{{ $n }}" @selected((int) old('decimales', $esc->decimales) === $n)>{{ $n }}</option>@endforeach</select></div>
            <div class="campo"><label for="redondeo">Redondeo de lo que se muestra</label>
                <select id="redondeo" name="redondeo">@foreach (['cercano' => 'Al más cercano', 'arriba' => 'Hacia arriba', 'abajo' => 'Hacia abajo'] as $k => $v)<option value="{{ $k }}" @selected(old('redondeo', $esc->redondeo) === $k)>{{ $v }}</option>@endforeach</select></div>
        </div>
        @if ($notas)
            {{-- Deshabilitados no se envían: van ocultos con el valor actual --}}
            <input type="hidden" name="escala_min" value="{{ $f($esc->minimo) }}"><input type="hidden" name="escala_max" value="{{ $f($esc->maximo) }}">
            <p class="suave">Con notas cargadas, el mínimo y el máximo ya no se cambian. La nota mínima, los decimales y el redondeo sí:
                las notas guardadas no cambian, cambia cómo se muestran y quién aprueba. El cálculo interno conserva siempre la precisión completa.</p>
        @else
            <p class="suave">Sin notas todavía: puede cambiar también el mínimo y el máximo. Las actividades que valían el máximo pasan a valer el nuevo.</p>
        @endif
        <button class="boton" type="submit">Guardar escala</button>
    </form>

    <h2>Cambios anteriores</h2>
    @forelse ($historial as $h)
        <details class="tarjeta" style="box-shadow:none">
            <summary>{{ $h->created_at->format('Y-m-d H:i') }} · {{ $h->usuario?->name ?? '—' }} · {{ count($h->cambios) }} {{ count($h->cambios) === 1 ? 'cambio' : 'cambios' }}
                @if ($h->notas_cargadas) <span class="suave">(con {{ $h->notas_cargadas }} notas cargadas)</span>@endif</summary>
            <ul>@foreach ($h->cambios as $c)<li>{{ $c }}</li>@endforeach</ul>
            @if (isset($h->antes['escala']))
            <p class="suave" style="margin-bottom:0">Antes: escala {{ $f($h->antes['escala']['minimo']) }} a {{ $f($h->antes['escala']['maximo']) }}, aprueba con {{ $f($h->antes['escala']['aprobatoria']) }},
                {{ $h->antes['escala']['decimales'] }} decimales, redondeo {{ ['cercano' => 'al más cercano', 'arriba' => 'hacia arriba', 'abajo' => 'hacia abajo'][$h->antes['escala']['redondeo']] ?? '' }}.</p>
            @else
            <p class="suave" style="margin-bottom:0">Antes:
                @foreach ($h->antes as $d){{ $d['nombre'] }} {{ \Brick\Math\BigDecimal::of($d['peso'])->multipliedBy(100)->strippedOfTrailingZeros() }} % (@foreach ($d['componentes'] as $c){{ $c['nombre'] }} {{ \Brick\Math\BigDecimal::of($c['peso'])->multipliedBy(100)->strippedOfTrailingZeros() }} %@if (! $loop->last), @endif @endforeach)@if (! $loop->last); @endif @endforeach</p>
            @endif
        </details>
    @empty
        <p class="suave">La estructura no ha cambiado desde el alta.</p>
    @endforelse

    <script>
        function estructuraPeriodo(divisiones) {
            let n = 0;
            const pct = (f) => Math.round(parseFloat(f) * 1000000) / 10000;
            const nuevo = (x) => ({ ...x, clave: ++n });
            return {
                nuevo,
                divisiones: divisiones.map(d => nuevo({ ...d, peso: pct(d.peso), fecha_inicio: d.fecha_inicio ?? '', fecha_fin: d.fecha_fin ?? '',
                    componentes: d.componentes.map(c => nuevo({ ...c, peso: pct(c.peso) })) })),
                actividades(d) { return d.componentes.reduce((s, c) => s + (c.actividades || 0), 0); },
                suma(lista) { return Math.round(lista.reduce((s, x) => s + (parseFloat(x.peso) || 0), 0) * 100) / 100; },
            };
        }
    </script>
</x-app>
