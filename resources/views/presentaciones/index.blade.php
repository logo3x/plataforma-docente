<x-app :titulo="'Presentaciones — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Presentaciones</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Presentaciones</h1>
        <a class="boton boton-sec" href="{{ route('presentaciones.create', ['materia' => $materia, 'temas' => $marcados]) }}">Nueva presentación a mano</a>
    </div>
    <p class="suave">Diapositivas para la clase, generadas dentro de la plataforma a partir del temario y del material de referencia.
        Cada diapositiva lleva la idea y, solo para usted, las notas de lo que se dice. Se proyectan desde aquí o se descargan
        en PowerPoint para seguir editándolas.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('presentaciones.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conjuntos->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Presentación</th><th>Temas</th><th class="num">Diapositivas</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($conjuntos as $c)
                    <tr>
                        <td><a href="{{ route('presentaciones.show', $c) }}">{{ $c->titulo }}</a></td>
                        <td class="suave">{{ collect($c->contenido['diapositivas'] ?? [])->pluck('tema')->filter()->unique()->join(', ') ?: '—' }}</td>
                        <td class="num">{{ count($c->contenido['diapositivas'] ?? []) + 1 }}</td>
                        <td class="suave">{{ $c->generacion_ia_id ? 'IA, revisada' : 'A mano' }}</td>
                        <td><a href="{{ route('presentaciones.edit', $c) }}">Editar</a> · <a href="{{ route('presentaciones.pptx', $c) }}">PowerPoint</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay presentaciones en esta materia.</strong> Genérelas con IA a partir del temario, o escríbalas a mano.</div>
    @endif

    <h2 id="generar">Generar una presentación con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('presentaciones.create', $materia) }}">escribirla a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('presentaciones.preparar', $materia) }}" class="tarjeta">
        @csrf
        @if ($unidades->flatMap->hijos->isNotEmpty())
            <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Temas del temario</legend>
                @foreach ($unidades as $u)
                    @continue($u->hijos->isEmpty())
                    <p style="margin:.3rem 0 .1rem" class="suave">{{ $u->titulo }}</p>
                    @foreach ($u->hijos as $t)<label style="font-weight:400;display:inline-block;margin-right:1rem"><input type="checkbox" name="temas[]" data-unidad="{{ $u->id }}" value="{{ $t->id }}" @checked(in_array($t->id, $marcados, true))> {{ $t->titulo }}</label>@endforeach
                @endforeach
            </fieldset>
        @endif
        <div class="campo"><label for="tema">{{ $unidades->flatMap->hijos->isNotEmpty() ? 'Precisiones del tema (opcional)' : 'Tema' }}</label>
            <input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="Pilas y sus usos">
            @error('tema')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="cantidad">Diapositivas</label><input id="cantidad" name="cantidad" type="number" min="3" max="{{ \App\Material\Presentacion::MAX_DIAPOSITIVAS }}" value="{{ old('cantidad', 12) }}" required></div>
            <div class="campo"><label for="minutos">Minutos de clase</label><input id="minutos" name="minutos" type="number" min="5" max="240" step="5" value="{{ old('minutos', 45) }}" required></div>
            <div class="campo"><label for="enfoque">Enfoque</label>
                <select id="enfoque" name="enfoque">@foreach (\App\Material\Presentacion::ENFOQUES as $k => $v)<option value="{{ $k }}" @selected(old('enfoque') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="campo"><label style="font-weight:400;margin-top:1.6rem"><input type="hidden" name="codigo" value="0"><input type="checkbox" name="codigo" value="1" @checked(old('codigo', $materia->modalidad_practica !== 'ninguna'))> Con diapositivas de código</label></div>
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="contexto">Contexto de los ejemplos (opcional)</label>
            <input id="contexto" name="contexto" type="text" value="{{ old('contexto') }}" placeholder="mantenimiento en plantas del sector energético"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}" data-tema="{{ $c->temario_item_id }}"> {{ $c->titulo }}@if ($c->tema) <span class="suave">· {{ $c->tema->titulo }}</span>@endif</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
