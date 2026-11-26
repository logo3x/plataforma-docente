<x-app :titulo="$u->titulo.' — Unidad didáctica'">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('unidades.index', $u->materia) }}">Unidades didácticas — {{ $u->materia->nombre }}</a> › {{ $u->titulo }}</div>
    <div class="fila" style="justify-content:space-between">
        <h1 style="margin-bottom:0">{{ $u->titulo }}</h1>
        <div class="fila no-imprimir">
            <a class="boton boton-sec" href="{{ route('unidades.edit', $u) }}">Editar</a>
            <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
        </div>
    </div>
    <p class="suave">{{ $u->materia->nombre }}@if ($u->unidad) · {{ $u->unidad->titulo }}@endif · {{ count($c['sesiones']) }} sesión(es), {{ $minutos }} minutos
        · {{ $u->generacion_ia_id ? 'generada con IA ('.$u->generacion?->motor?->nombre.') y revisada por el docente' : 'hecha a mano' }}</p>

    @if ($advertencias)
        <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <section class="tarjeta">
        @if ($c['proposito'])<p style="margin-top:0"><strong>Propósito.</strong> {{ $c['proposito'] }}</p>@endif
        <div class="rejilla">
            @if ($c['resultados'])<div><strong>Resultados de aprendizaje</strong><ul>@foreach ($c['resultados'] as $r)<li>{{ $r }}</li>@endforeach</ul></div>@endif
            @if ($c['previos'])<div><strong>Conocimientos previos</strong><ul>@foreach ($c['previos'] as $r)<li>{{ $r }}</li>@endforeach</ul></div>@endif
        </div>
    </section>

    @foreach ($c['sesiones'] as $i => $s)
        @php($ids = collect($s['temas'])->map(fn ($t) => $servicio->tema($temario, $t)?->id)->filter()->values())
        <section class="tarjeta sesion-unidad">
            <div class="fila" style="justify-content:space-between;align-items:flex-start">
                <h2>Sesión {{ $i + 1 }}. {{ $s['titulo'] }} <span class="suave">· {{ $s['duracion'] }} min</span></h2>
                @if ($ids->isNotEmpty())
                    <span class="fila no-imprimir" style="gap:.4rem">
                        <a class="boton boton-sec boton-chico" href="{{ route('ejemplos.index', ['materia' => $u->materia, 'temas' => $ids->all()]) }}#generar">Ejemplos prácticos</a>
                        <a class="boton boton-sec boton-chico" href="{{ route('debates.index', ['materia' => $u->materia, 'temas' => $ids->all()]) }}#generar">Preguntas de debate</a>
                        <a class="boton boton-sec boton-chico" href="{{ route('generacion.create', ['materia' => $u->materia, 'temas' => $ids->all()]) }}">Quiz de estos temas</a>
                    </span>
                @endif
            </div>
            <dl>
                <dt>Objetivo</dt><dd>{{ $s['objetivo'] }}</dd>
                @if ($s['temas'])<dt>Temas</dt><dd>{{ implode(' · ', $s['temas']) }}</dd>@endif
                @if ($s['inicio'])<dt>Inicio</dt><dd>{{ $s['inicio'] }}</dd>@endif
                <dt>Desarrollo</dt><dd>{{ $s['desarrollo'] }}</dd>
                @if ($s['cierre'])<dt>Cierre</dt><dd>{{ $s['cierre'] }}</dd>@endif
                @if ($s['recursos'])<dt>Recursos</dt><dd>{{ implode(' · ', $s['recursos']) }}</dd>@endif
                @if ($s['evaluacion']['tipo'] !== 'ninguna' || $s['evaluacion']['descripcion'])
                    <dt>Evaluación</dt><dd>{{ \App\Planificacion\UnidadesDidacticas::EVALUACION_SESION[$s['evaluacion']['tipo']] ?? $s['evaluacion']['tipo'] }}@if ($s['evaluacion']['descripcion']): {{ $s['evaluacion']['descripcion'] }}@endif</dd>
                @endif
            </dl>
        </section>
    @endforeach

    @if ($c['evaluacion'])
        <section class="tarjeta">
            <h2 style="margin-top:0">Evaluación de la unidad</h2>
            <table><thead><tr><th>Actividad</th><th>Tipo</th><th>Qué evalúa</th></tr></thead><tbody>
                @foreach ($c['evaluacion'] as $e)
                    <tr><td>{{ $e['actividad'] }}</td><td>{{ \App\Planificacion\UnidadesDidacticas::TIPOS_ACTIVIDAD[$e['tipo']] ?? $e['tipo'] }}</td><td>{{ $e['descripcion'] }}</td></tr>
                @endforeach
            </tbody></table>
        </section>
    @endif
</x-app>
