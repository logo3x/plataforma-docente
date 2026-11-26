<x-app :titulo="'Preguntas de debate — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Preguntas de debate</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Preguntas de debate</h1>
        <a class="boton boton-sec" href="{{ route('debates.create', ['materia' => $materia, 'temas' => $marcados]) }}">Nuevas preguntas a mano</a>
    </div>
    <p class="suave">Preguntas abiertas para discutir en clase. Cada una trae un caso para proyectar y, solo para usted, las posturas
        con sus argumentos, repreguntas y la idea que debe quedar al cierre.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('debates.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conjuntos->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Preguntas</th><th>Temas</th><th class="num">Cantidad</th><th class="num">Minutos</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($conjuntos as $c)
                    <tr>
                        <td><a href="{{ route('debates.show', $c) }}">{{ $c->titulo }}</a></td>
                        <td class="suave">{{ collect($c->contenido['preguntas'] ?? [])->pluck('tema')->filter()->unique()->join(', ') ?: '—' }}</td>
                        <td class="num">{{ count($c->contenido['preguntas'] ?? []) }}</td>
                        <td class="num">{{ collect($c->contenido['preguntas'] ?? [])->sum(fn ($p) => (int) $p['minutos']) }}</td>
                        <td class="suave">{{ $c->generacion_ia_id ? 'IA, revisadas' : 'A mano' }}</td>
                        <td><a href="{{ route('debates.edit', $c) }}">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay preguntas de debate en esta materia.</strong> Genérelas con IA a partir del temario, o escríbalas a mano.</div>
    @endif

    <h2 id="generar">Generar preguntas de debate con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('debates.create', $materia) }}">escribirlas a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('debates.preparar', $materia) }}" class="tarjeta">
        @csrf
        @if ($unidades->flatMap->hijos->isNotEmpty())
            <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Temas del temario</legend>
                @foreach ($unidades as $u)
                    @continue($u->hijos->isEmpty())
                    <p style="margin:.3rem 0 .1rem" class="suave">{{ $u->titulo }}</p>
                    @foreach ($u->hijos as $t)<label style="font-weight:400;display:inline-block;margin-right:1rem"><input type="checkbox" name="temas[]" value="{{ $t->id }}" @checked(in_array($t->id, $marcados, true))> {{ $t->titulo }}</label>@endforeach
                @endforeach
            </fieldset>
        @endif
        <div class="campo"><label for="tema">{{ $unidades->flatMap->hijos->isNotEmpty() ? 'Precisiones del tema (opcional)' : 'Tema' }}</label>
            <input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="Privacidad de los datos en las aplicaciones móviles">
            @error('tema')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="cantidad">Cantidad</label><input id="cantidad" name="cantidad" type="number" min="1" max="{{ \App\Material\PreguntasDebate::MAX_PREGUNTAS }}" value="{{ old('cantidad', 4) }}" required></div>
            <div class="campo"><label for="enfoque">Enfoque</label>
                <select id="enfoque" name="enfoque"><option value="mixto">Variado</option>@foreach (\App\Material\PreguntasDebate::ENFOQUES as $k => $v)<option value="{{ $k }}" @selected(old('enfoque') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="campo"><label for="dinamica">Dinámica</label>
                <select id="dinamica" name="dinamica"><option value="mixta">La que convenga</option>@foreach (\App\Material\PreguntasDebate::DINAMICAS as $k => $v)<option value="{{ $k }}" @selected(old('dinamica') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="campo"><label for="minutos">Minutos por pregunta</label><input id="minutos" name="minutos" type="number" min="5" max="120" step="5" value="{{ old('minutos', 15) }}" required></div>
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="contexto">Contexto de los casos (opcional)</label>
            <input id="contexto" name="contexto" type="text" value="{{ old('contexto') }}" placeholder="empresas del sector energético de la región"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}"> {{ $c->titulo }}</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
