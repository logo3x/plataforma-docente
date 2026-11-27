<x-app :titulo="$m->titulo.' — '.($c['generador'] ?: 'Documento')">
    @php($TI = \App\Material\TextoInformativo::class)
    @php($DG = \App\Material\DocumentoGenerado::class)
    @php($interactivas = app($DG)->interactivas($c))
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('documentos.index', $m->materia) }}">Guías, manuales y otros documentos — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
    @include('ia._generacion-funciono')
    <div class="fila no-imprimir" style="justify-content:flex-end">
        <a class="boton boton-sec" href="{{ route('documentos.edit', $m) }}">Editar</a>
        @if ($estudiante)
            <a class="boton boton-sec" href="{{ route('documentos.show', $m) }}">Con soluciones</a>
        @else
            <a class="boton boton-sec" href="{{ route('documentos.show', ['material' => $m, 'version' => 'estudiante']) }}">Versión para estudiantes</a>
        @endif
        <a class="boton boton-sec" href="{{ route('publicacion.edit', $m) }}">Publicar</a>
        <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
    </div>
    @if (! $estudiante && $advertencias)
        <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong><ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif
    @if (! $estudiante && $interactivas)
        <div class="tarjeta no-imprimir fila">
            <span style="flex:1">Compruebe que cada ejercicio funciona: su solución debe pasar la prueba y el código para empezar no.</span>
            <button class="boton boton-sec" type="button" id="comprobar-ejercicios">Comprobar los ejercicios</button>
        </div>
        <div id="resultado-ejercicios" role="status" aria-live="polite" class="no-imprimir"></div>
    @endif

    <article class="apunte">
        <h1>{{ $c['titulo'] }}</h1>
        <p class="suave">{{ $m->materia->nombre }}@if ($c['generador']) · {{ $c['generador'] }}@endif
            <span class="no-imprimir">· {{ $m->generacion_ia_id ? 'generado con IA ('.$m->generacion?->motor?->nombre.') y revisado por el docente' : 'escrito por el docente' }}</span></p>
        @if ($c['proposito'])<p class="apunte-proposito">{{ $c['proposito'] }}</p>@endif

        @foreach ($c['secciones'] as $i => $s)
            @php($ejecutable = in_array($s['lenguaje'], $DG::EJECUTABLES, true))
            <section class="seccion-documento" data-lenguaje="{{ $s['lenguaje'] }}">
                <h2>{{ $s['titulo'] }}</h2>
                @foreach ($TI::bloques($s['texto']) as $b)
                    @if ($b['tipo'] === 'lista')<ul>@foreach ($b['contenido'] as $li)<li>{{ $li }}</li>@endforeach</ul>
                    @else<p style="white-space:pre-line">{{ $b['contenido'] }}</p>@endif
                @endforeach
                @if ($s['codigo'] !== '')
                    <pre class="codigo">{{ $s['codigo'] }}</pre>
                    @if ($ejecutable)
                        <div class="no-imprimir"><button type="button" class="boton boton-sec boton-chico ejecutar-ejemplo" data-codigo="{{ $s['codigo'] }}">▶ Ejecutar el ejemplo</button>
                            <div class="salida-ejemplo" aria-live="polite"></div></div>
                    @endif
                @endif
                @if ($x = $s['ejercicio'])
                    <div class="tarjeta ejercicio" data-prueba="{{ $x['prueba'] }}" data-solucion="{{ $estudiante ? '' : $x['solucion'] }}" data-plantilla="{{ $x['plantilla'] }}">
                        <p style="margin-top:0"><strong>Ejercicio {{ $i + 1 }}.</strong> {{ $x['enunciado'] }}</p>
                        @if ($ejecutable)
                            <label class="sr-only" for="ej-{{ $i }}">Su código para el ejercicio {{ $i + 1 }}</label>
                            <textarea id="ej-{{ $i }}" rows="6" spellcheck="false" class="no-imprimir">{{ $x['plantilla'] }}</textarea>
                            <pre class="codigo solo-imprimir">{{ $x['plantilla'] }}</pre>
                            <p class="no-imprimir"><button type="button" class="boton boton-chico probar-ejercicio">▶ Probar mi solución</button> <span class="resultado-ejercicio" aria-live="polite"></span></p>
                        @elseif ($x['plantilla'] !== '')
                            <pre class="codigo">{{ $x['plantilla'] }}</pre>
                        @endif
                        @if (! $estudiante && $x['solucion'] !== '')
                            <details class="no-imprimir"><summary>Solución</summary><pre class="codigo">{{ $x['solucion'] }}</pre>
                                @if ($x['prueba'] !== '')<p class="suave">Prueba: <code>{{ $x['prueba'] }}</code></p>@endif</details>
                        @endif
                    </div>
                @endif
            </section>
        @endforeach
        @if ($c['fuentes'])<p class="suave"><strong>Fuentes:</strong> {{ implode(' · ', $c['fuentes']) }}</p>@endif
    </article>

    @if ($c['secciones'] && collect($c['secciones'])->contains(fn ($s) => in_array($s['lenguaje'], $DG::EJECUTABLES, true)))
        <script src="{{ asset('js/ejecutor.js') }}"></script>
        <script src="{{ asset('js/ejecutor-dom.js') }}"></script>
        <script>
        /* 7.5: el entorno de prueba de la plataforma dentro del documento. El código corre aislado, sin red y con
           límite de tiempo; en HTML, en una vista previa aislada. */
        (function () {
            const correr = async (lenguaje, codigo, prueba, vista) => {
                if (lenguaje === 'html') { const r = await window.ejecutarHTML(codigo, prueba || '', 3000, vista); return { ok: r.ok, consola: (r.consola || []).map(l => l.texto), error: r.estado === 'con_salida' || r.estado === 'sin_salida' ? null : r.mensaje }; }
                return window.ejecutarJS(codigo, prueba || 'true', 3000);
            };
            const lenguajeDe = (el) => el.closest('.seccion-documento').dataset.lenguaje;
            document.querySelectorAll('.ejecutar-ejemplo').forEach(b => b.addEventListener('click', async () => {
                const salida = b.nextElementSibling; salida.textContent = 'Ejecutando…';
                const vista = lenguajeDe(b) === 'html' ? document.createElement('div') : null;
                const r = await correr(lenguajeDe(b), b.dataset.codigo, '', vista);
                salida.replaceChildren(...(vista ? [vista] : []), Object.assign(document.createElement('pre'), { className: 'consola', textContent: (r.consola.length ? r.consola.join('\n') : '(sin salida en la consola)') + (r.error ? '\n⚠ ' + r.error : '') }));
            }));
            document.querySelectorAll('.probar-ejercicio').forEach(b => b.addEventListener('click', async () => {
                const caja = b.closest('.ejercicio'), res = caja.querySelector('.resultado-ejercicio');
                res.textContent = 'Probando…';
                const r = await correr(lenguajeDe(b), caja.querySelector('textarea').value, caja.dataset.prueba);
                res.textContent = r.ok ? '✓ Lo resolvió' : '✗ Todavía no' + (r.error ? ': ' + r.error : '');
                res.className = 'resultado-ejercicio ' + (r.ok ? 'texto-bien' : 'texto-alerta');
            }));
            document.getElementById('comprobar-ejercicios')?.addEventListener('click', async (ev) => {
                const salida = document.getElementById('resultado-ejercicios'); ev.target.disabled = true; salida.textContent = 'Comprobando…';
                const filas = [];
                for (const caja of document.querySelectorAll('.ejercicio[data-prueba]')) {
                    if (!caja.querySelector('textarea')) continue;
                    const n = caja.querySelector('strong').textContent.replace('.', '');
                    const sol = caja.dataset.solucion ? (await correr(lenguajeDe(caja), caja.dataset.solucion, caja.dataset.prueba)).ok : null;
                    const ini = (await correr(lenguajeDe(caja), caja.dataset.plantilla, caja.dataset.prueba)).ok;
                    filas.push(n + ': ' + (sol === null ? 'sin solución para comprobar' : sol ? 'la solución pasa' : '✗ la solución NO pasa la prueba') + ' · ' + (ini ? '✗ el código para empezar ya pasa la prueba' : 'el código para empezar no pasa'));
                }
                const ok = filas.every(f => !f.includes('✗'));
                salida.innerHTML = ''; const d = document.createElement('div'); d.className = 'aviso ' + (ok ? 'aviso-ok' : 'aviso-error');
                d.append(ok ? '✓ Todos los ejercicios funcionan.' : 'Hay ejercicios por revisar:'); const ul = document.createElement('ul'); ul.style.margin = '.25rem 0 0';
                filas.forEach(f => ul.append(Object.assign(document.createElement('li'), { textContent: f }))); d.append(ul); salida.append(d); ev.target.disabled = false;
            });
        })();
        </script>
    @endif
</x-app>
