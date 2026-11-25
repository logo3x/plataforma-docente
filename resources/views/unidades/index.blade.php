<x-app :titulo="'Unidades didácticas — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Unidades didácticas</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Unidades didácticas</h1>
        <a class="boton boton-sec" href="{{ route('unidades.create', $materia) }}">Nueva unidad a mano</a>
    </div>
    <p class="suave">La secuencia de sesiones de una unidad del temario: objetivo, inicio, desarrollo y cierre de cada clase, recursos y evaluación.
        Pertenece a la materia y sirve para cualquier periodo.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('unidades.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($planeadas->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Unidad didáctica</th><th>Unidad del temario</th><th class="num">Sesiones</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($planeadas as $p)
                    <tr>
                        <td><a href="{{ route('unidades.show', $p) }}">{{ $p->titulo }}</a></td>
                        <td>{{ $p->unidad?->titulo ?? '—' }}</td>
                        <td class="num">{{ count($p->contenido['sesiones'] ?? []) }}</td>
                        <td class="suave">{{ $p->generacion_ia_id ? 'IA, revisada' : 'A mano' }}</td>
                        <td><a href="{{ route('unidades.edit', $p) }}">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay unidades planeadas.</strong> Genere una con IA a partir del temario, o hágala a mano.</div>
    @endif

    <h2 id="generar">Planear una unidad con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('unidades.create', $materia) }}">hacerla a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('unidades.preparar', $materia) }}" class="tarjeta">
        @csrf
        @if ($unidades->isNotEmpty())
            <div class="campo"><label for="unidad_id">Unidad del temario</label>
                <select id="unidad_id" name="unidad_id">
                    <option value="">— Ninguna: escribo el tema abajo —</option>
                    @foreach ($unidades as $u)
                        <option value="{{ $u->id }}" @selected((int) old('unidad_id', request('unidad')) === $u->id)>{{ $u->titulo }} ({{ $u->hijos->where('tipo', 'tema')->count() }} temas)</option>
                    @endforeach
                </select>
                <p class="suave" style="margin:.2rem 0 0">La IA recibe los temas, los resultados de aprendizaje y las sugerencias aceptadas de esa unidad.</p></div>
        @else
            <div class="aviso aviso-info">La materia no tiene temario. Puede <a href="{{ route('temario.show', $materia) }}">armarlo</a> o escribir el tema abajo.</div>
        @endif
        <div class="campo"><label for="tema">{{ $unidades->isNotEmpty() ? 'O escriba el tema de la unidad' : 'Tema de la unidad' }}</label>
            <textarea id="tema" name="tema" rows="2" placeholder="Pilas y colas: implementación con arreglos y aplicaciones">{{ old('tema') }}</textarea>
            @error('tema')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="sesiones">Número de sesiones</label><input id="sesiones" name="sesiones" type="number" min="1" max="{{ \App\Planificacion\UnidadesDidacticas::MAX_SESIONES }}" value="{{ old('sesiones', 4) }}" required></div>
            <div class="campo"><label for="duracion">Minutos por sesión</label><input id="duracion" name="duracion" type="number" min="10" max="600" step="5" value="{{ old('duracion', 120) }}" required></div>
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="enfasis">Énfasis (opcional)</label>
            <input id="enfasis" name="enfasis" type="text" value="{{ old('enfasis') }}" placeholder="más práctica en el laboratorio; el grupo nocturno trabaja"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}"> {{ $c->titulo }}</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
