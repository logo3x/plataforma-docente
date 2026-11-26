<x-app :titulo="$m->titulo.' — Preguntas de debate'">
    @php($P = \App\Material\PreguntasDebate::class)
    <div x-data="{ proyectando: false, n: 0, total: {{ count($c['preguntas']) }},
                   abrir() { this.n = 0; this.proyectando = true; this.$nextTick(() => this.$refs.proyector.focus()); },
                   tecla(e) { if (['ArrowRight', 'PageDown', ' '].includes(e.key)) { e.preventDefault(); this.n = Math.min(this.n + 1, this.total - 1); }
                              else if (['ArrowLeft', 'PageUp'].includes(e.key)) { e.preventDefault(); this.n = Math.max(this.n - 1, 0); }
                              else if (e.key === 'Escape') this.proyectando = false; } }">
        <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('debates.index', $m->materia) }}">Preguntas de debate — {{ $m->materia->nombre }}</a> › {{ $m->titulo }}</div>
        <div class="fila" style="justify-content:space-between">
            <h1 style="margin-bottom:0">{{ $m->titulo }}@if ($estudiante) <span class="suave">· para estudiantes</span>@endif</h1>
            <div class="fila no-imprimir">
                <button class="boton" type="button" x-on:click="abrir()">Proyectar en clase</button>
                <a class="boton boton-sec" href="{{ route('debates.edit', $m) }}">Editar</a>
                @if ($estudiante)
                    <a class="boton boton-sec" href="{{ route('debates.show', $m) }}">Guía del docente</a>
                @else
                    <a class="boton boton-sec" href="{{ route('debates.show', ['material' => $m, 'version' => 'estudiante']) }}">Versión para estudiantes</a>
                @endif
                <button class="boton boton-sec" type="button" onclick="window.print()">Imprimir</button>
            </div>
        </div>
        <p class="suave">{{ $m->materia->nombre }} · {{ count($c['preguntas']) }} pregunta(s) · {{ collect($c['preguntas'])->sum(fn ($p) => (int) $p['minutos']) }} minutos
            <span class="no-imprimir">· {{ $m->generacion_ia_id ? 'generadas con IA ('.$m->generacion?->motor?->nombre.') y revisadas por el docente' : 'escritas a mano' }}</span></p>
        @if ($c['proposito'])<p>{{ $c['proposito'] }}</p>@endif

        @if (! $estudiante && $advertencias)
            <div class="aviso aviso-info no-imprimir"><strong>Para revisar:</strong>
                <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
        @endif

        @foreach ($c['preguntas'] as $i => $p)
            <section class="tarjeta sesion-unidad">
                <h2>{{ $i + 1 }}. {{ $p['pregunta'] }}</h2>
                <p class="suave" style="margin:0 0 .4rem">{{ $P::ENFOQUES[$p['enfoque']] ?? $p['enfoque'] }} · {{ $P::DINAMICAS[$p['dinamica']] ?? $p['dinamica'] }} · {{ $p['minutos'] }} min @if ($p['tema']) · {{ $p['tema'] }}@endif</p>
                @if ($p['contexto'])<p style="white-space:pre-wrap">{{ $p['contexto'] }}</p>@endif
                @unless ($estudiante)
                    <div class="rejilla">
                        @foreach ($p['posturas'] as $po)
                            <div class="postura"><strong>{{ $po['postura'] }}</strong><ul>@foreach ($po['argumentos'] as $a)<li>{{ $a }}</li>@endforeach</ul></div>
                        @endforeach
                    </div>
                    @if ($p['seguimiento'])<p style="margin-bottom:.2rem"><strong>Para profundizar</strong></p><ul>@foreach ($p['seguimiento'] as $s)<li>{{ $s }}</li>@endforeach</ul>@endif
                    <p><strong>Al cierre debe quedar:</strong> {{ $p['cierre'] }}</p>
                @endunless
            </section>
        @endforeach

        {{-- Modo proyector: una pregunta por pantalla, solo lo que ven los estudiantes; flechas para avanzar, Esc para salir --}}
        <div class="proyector" x-show="proyectando" x-cloak x-ref="proyector" tabindex="-1" x-on:keydown="tecla($event)" role="dialog" aria-modal="true" aria-label="Proyección de las preguntas">
            @foreach ($c['preguntas'] as $i => $p)
                <div x-show="n === {{ $i }}">
                    <p class="proyector-num">Pregunta {{ $i + 1 }} de {{ count($c['preguntas']) }} · {{ $P::DINAMICAS[$p['dinamica']] ?? $p['dinamica'] }} · {{ $p['minutos'] }} min</p>
                    <p class="proyector-pregunta">{{ $p['pregunta'] }}</p>
                    @if ($p['contexto'])<p class="proyector-contexto">{{ $p['contexto'] }}</p>@endif
                </div>
            @endforeach
            <div class="proyector-controles">
                <button type="button" x-on:click="n = Math.max(n - 1, 0)" x-bind:disabled="n === 0">← Anterior</button>
                <button type="button" x-on:click="n = Math.min(n + 1, total - 1)" x-bind:disabled="n === total - 1">Siguiente →</button>
                <button type="button" x-on:click="proyectando = false">Salir (Esc)</button>
            </div>
        </div>
    </div>
</x-app>
