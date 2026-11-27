<x-app :titulo="'Vocabulario — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Vocabulario</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Vocabulario</h1>
        <a class="boton boton-sec" href="{{ route('vocabularios.create', ['materia' => $materia, 'temas' => $marcados]) }}">Nueva lista a mano</a>
    </div>
    <p class="suave">Términos clave del tema con su definición y un ejemplo. Se entregan como lista, tarjetas para repasar u hoja de repaso,
        y pueden pasar al <a href="{{ route('materias.estilo', $materia) }}">vocabulario propio de la materia</a> para que la IA use las mismas palabras.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('vocabularios.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conjuntos->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Lista</th><th>Temas</th><th class="num">Términos</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($conjuntos as $c)
                    <tr>
                        <td><a href="{{ route('vocabularios.show', $c) }}">{{ $c->titulo }}</a></td>
                        <td class="suave">{{ collect($c->contenido['terminos'] ?? [])->pluck('tema')->filter()->unique()->join(', ') ?: '—' }}</td>
                        <td class="num">{{ count($c->contenido['terminos'] ?? []) }}</td>
                        <td class="suave">{{ $c->generacion_ia_id ? 'IA, revisada' : 'A mano' }}</td>
                        <td><a href="{{ route('vocabularios.edit', $c) }}">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay listas de vocabulario.</strong> Genere una con IA a partir del temario, o escríbala a mano.</div>
    @endif

    <h2 id="generar">Generar vocabulario con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('vocabularios.create', $materia) }}">escribirla a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('vocabularios.preparar', $materia) }}" class="tarjeta">
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
            <input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="Bases de datos relacionales">
            @error('tema')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="cantidad">Cantidad de términos</label><input id="cantidad" name="cantidad" type="number" min="3" max="{{ \App\Material\ListaVocabulario::MAX_TERMINOS }}" value="{{ old('cantidad', 12) }}" required></div>
            <div class="campo"><label for="nivel">Nivel</label>
                <select id="nivel" name="nivel">@foreach (\App\Material\ListaVocabulario::NIVELES as $k => $v)<option value="{{ $k }}" @selected(old('nivel') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <label style="font-weight:400"><input type="checkbox" name="ingles" value="1" @checked(old('ingles', true))> Incluir el término equivalente en inglés</label>
        <div class="campo"><label for="contexto">Contexto de los ejemplos (opcional)</label>
            <input id="contexto" name="contexto" type="text" value="{{ old('contexto') }}" placeholder="inventario de repuestos en una planta"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}" data-tema="{{ $c->temario_item_id }}"> {{ $c->titulo }}@if ($c->tema) <span class="suave">· {{ $c->tema->titulo }}</span>@endif</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
