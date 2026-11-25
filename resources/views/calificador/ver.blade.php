@php($dictado = $actividad->componente->division->dictado)
@php($tiposAlerta = ['manipulado' => '⚠ Identidad no coincide', 'grupo_no_cuadra' => 'Otro grupo', 'sin_cifrar' => 'Sin cifrar', 'texto_identico' => 'Texto idéntico',
    'salidas' => 'Salidas', 'pegados' => 'Pegados', 'duracion_anomala' => 'Duración corta', 'cierre_automatico' => 'Cierre por tiempo', 'reconexion' => 'Reconexión',
    'integrante_desconocido' => 'Integrante no está en la lista', 'estudiante_en_dos_entregas' => 'En dos entregas'])
<x-app :titulo="'Calificador — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('artefactos.index', $dictado->materia) }}">Actividades evaluables</a> › {{ $actividad->nombre }}</div>
    <h1>Calificador — {{ $actividad->nombre }}</h1>
    <p class="suave">{{ $actividad->artefacto?->sigla }} · {{ $dictado->periodo }} › {{ $actividad->componente->division->nombre }} › {{ $actividad->componente->nombre }}
        · {{ $actividad->grupos->pluck('nombre')->join(', ') ?: 'todos los grupos' }} @if ($actividad->es_grupal)· entrega grupal @endif</p>

    @if (session('resultados'))
        <div class="tarjeta" role="status">
            <h2 style="margin-top:0">Resultado de la carga</h2>
            <ul>@foreach (session('resultados') as $r)<li><span class="estado {{ ['procesada' => 'estado-aprobado', 'duplicada' => 'estado-sin_actividades', 'rechazada' => 'estado-reprobado'][$r['estado']] }}">{{ ['procesada' => '✓ Procesada', 'duplicada' => '= Ya estaba', 'rechazada' => '✗ Rechazada'][$r['estado']] }}</span> {{ $r['mensaje'] }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="rejilla">
        <section class="tarjeta">
            <h2 style="margin-top:0">1. Examen</h2>
            <p>Un archivo HTML que funciona sin internet y no lleva respuestas. Ábralo en un equipo de la sala antes de aplicarlo.</p>
            <a class="boton" href="{{ route('calificador.examen', $actividad) }}">Descargar examen</a>
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">2. Entregas</h2>
            <form method="POST" action="{{ route('calificador.subir', $actividad) }}" enctype="multipart/form-data">
                @csrf
                <label for="entregas">Archivos .json de los estudiantes (puede seleccionar varios)</label>
                <input id="entregas" type="file" name="entregas[]" multiple accept=".json,application/json" required>
                @error('entregas')<div class="error" role="alert">{{ $message }}</div>@enderror
                <p><button class="boton" type="submit">Procesar entregas</button></p>
            </form>
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">3. Calificar y registrar</h2>
            <p>Procesadas {{ $indicadores['procesadas'] }} · calificadas {{ $indicadores['calificadas'] }} · aprobadas {{ $indicadores['aprobados'] }}
                @if ($indicadores['promedio']) · promedio {{ $indicadores['promedio'] }}@endif · ítems por calificar {{ $indicadores['pendientes'] }}</p>
            <div class="fila">
                <button class="boton boton-sec" id="ejecutar-retos" type="button">Ejecutar retos de código</button>
                @if ($tieneAbiertas)<a class="boton boton-sec" href="{{ route('calificador.abiertas', $actividad) }}">Calificar abiertas por pregunta</a>@endif
                <form method="POST" action="{{ route('calificador.registro', $actividad) }}">@csrf
                    <button class="boton" type="submit" @disabled($indicadores['calificadas'] === 0)>Pasar notas al registro</button></form>
            </div>
            <p id="estado-retos" class="suave" role="status"></p>
        </section>
    </div>

    <h2>Planilla</h2>
    @if ($filas->isEmpty())
        <div class="tarjeta vacio"><strong>Aún no hay entregas procesadas.</strong>Suba los archivos que generó el examen al finalizar.</div>
    @else
        <p class="suave">Las alertas informan; ninguna sanciona por sí sola. Esta planilla no se proyecta en el aula.</p>
        <div class="tabla-notas"><table>
            <thead><tr><th>Estudiante(s)</th><th class="num">Duración</th><th class="num">Puntaje servidor</th><th>Pendiente</th><th class="num">Nota</th><th>Alertas</th><th></th></tr></thead>
            <tbody>
                @foreach ($filas as $f)
                    @php($e = $f['entrega'])
                    <tr>
                        <td>{{ $e->integrantes->map->nombreCompleto()->join(' · ') }}@if (! $e->cifrada) <span class="alerta-chip">sin cifrar</span>@endif</td>
                        <td class="num">{{ $e->duracion_seg === null ? '—' : sprintf('%d:%02d', intdiv($e->duracion_seg, 60), $e->duracion_seg % 60) }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format($f['servidor'], 2, '.', ''), '0'), '.') }}</td>
                        <td>@if ($f['retos'])<span class="alerta-chip">{{ $f['retos'] }} reto(s)</span>@endif @if ($f['abiertas'])<span class="alerta-chip">{{ $f['abiertas'] }} abierta(s)</span>@endif @if (! $f['retos'] && ! $f['abiertas'])—@endif</td>
                        <td class="num definitiva">{{ $f['nota'] === null ? '—' : $escala->mostrar($f['nota']) }}</td>
                        <td>@foreach ($e->alertas as $a)<span class="alerta-chip" title="{{ json_encode($a->datos, JSON_UNESCAPED_UNICODE) }}">{{ $tiposAlerta[$a->tipo] ?? $a->tipo }}@if ($a->tipo === 'salidas') ({{ $a->datos['cantidad'] }}, {{ $a->datos['segundos'] }} s)@endif</span>@endforeach</td>
                        <td><a href="{{ route('calificador.detalle', $e) }}">Detalle</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif

    <script>
    /* Calificación híbrida: los retos se ejecutan aquí, en el navegador del docente, aislados en un Worker
       sin red y con límite de tiempo. Solo se envía qué verificaciones pasaron; el puntaje lo calcula el servidor. */
    (function () {
        const boton = document.getElementById('ejecutar-retos');
        const estado = document.getElementById('estado-retos');
        const token = '{{ csrf_token() }}';
        const W = `self.fetch=undefined;self.XMLHttpRequest=undefined;self.WebSocket=undefined;self.importScripts=undefined;
          const L=[];console.log=console.info=console.warn=console.error=(...a)=>{if(L.length<200)L.push(a.map(x=>typeof x==='object'&&x!==null?JSON.stringify(x):String(x)).join(' '))};
          self.onmessage=(ev)=>{const {codigo,prueba}=ev.data;
            try{const r=new Function(codigo+'\\n;return ('+prueba+');')();self.postMessage({ok:r===true,consola:L});}
            catch(e){self.postMessage({ok:false,error:String(e&&e.message||e),consola:L});}};`;
        const url = URL.createObjectURL(new Blob([W], {type: 'text/javascript'}));
        const correr = (codigo, prueba, ms) => new Promise(res => {
            const w = new Worker(url);
            const t = setTimeout(() => { w.terminate(); res({ok: false, error: 'tiempo agotado', consola: []}); }, ms);
            w.onmessage = ev => { clearTimeout(t); w.terminate(); res(ev.data); };
            w.onerror = ev => { clearTimeout(t); w.terminate(); ev.preventDefault(); res({ok: false, error: ev.message, consola: []}); };
            w.postMessage({codigo, prueba});
        });
        boton?.addEventListener('click', async () => {
            boton.disabled = true; estado.textContent = 'Buscando retos pendientes…';
            const datos = await (await fetch('{{ route('calificador.retos', $actividad) }}', {headers: {Accept: 'application/json'}})).json();
            if (!datos.pendientes.length) { estado.textContent = 'No hay retos pendientes.'; boton.disabled = false; return; }
            let n = 0;
            const porEntrega = {};
            for (const p of datos.pendientes) {
                const verificaciones = {}; const casos = [];
                for (const v of p.verificaciones) verificaciones[v.id] = (await correr(p.codigo, v.prueba, datos.tiempo_ms)).ok;
                for (const c of p.casos) { const r = await correr(p.codigo, c.entrada || 'undefined', datos.tiempo_ms); casos.push({entrada: c.entrada, esperada: c.esperada ?? null, consola: r.consola, error: r.error ?? null}); }
                (porEntrega[p.entrega] ||= []).push({item: p.item, verificaciones, casos});
                estado.textContent = 'Ejecutados ' + (++n) + ' de ' + datos.pendientes.length + '…';
            }
            for (const [entrega, resultados] of Object.entries(porEntrega)) {
                await fetch('{{ url('calificador/entregas') }}/' + entrega + '/resultados-navegador', {method: 'POST',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify({resultados})});
            }
            estado.textContent = '✓ ' + n + ' retos calificados. Actualizando…';
            location.reload();
        });
    })();
    </script>
</x-app>
