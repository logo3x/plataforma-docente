<x-app :titulo="'Ideas de proyectos — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Ideas de proyectos</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Ideas de proyectos</h1>
        <a class="boton boton-sec" href="{{ route('proyectos.create', ['materia' => $materia, 'temas' => $marcados]) }}">Nuevo proyecto a mano</a>
    </div>
    <p class="suave">Propuestas de trabajo que integran lo que el grupo ya vio, con el problema que resuelven, entregables, hitos y criterios.
        De cada una sale el enunciado para los estudiantes y su rúbrica.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('proyectos.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conjuntos->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Ideas</th><th>Proyectos</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($conjuntos as $c)
                    <tr>
                        <td><a href="{{ route('proyectos.show', $c) }}">{{ $c->titulo }}</a></td>
                        <td class="suave">{{ collect($c->contenido['proyectos'] ?? [])->pluck('titulo')->join(' · ') }}</td>
                        <td class="suave">{{ $c->generacion_ia_id ? 'IA, revisadas' : 'A mano' }}</td>
                        <td><a href="{{ route('proyectos.edit', $c) }}">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay ideas de proyectos en esta materia.</strong> Genérelas con IA a partir de lo que el grupo ya vio, o escríbalas a mano.</div>
    @endif

    <h2 id="generar">Generar ideas de proyectos con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('proyectos.create', $materia) }}">escribirlas a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('proyectos.preparar', $materia) }}" class="tarjeta">
        @csrf
        <div class="rejilla">
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo</label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option @selected(old('grupo') === $g->nombre)>{{ $g->nombre }}</option>@endforeach</select></div>
        </div>
        @if ($unidades->flatMap->hijos->isNotEmpty())
            <label style="font-weight:400"><input type="checkbox" name="vistos" value="1" @checked(old('vistos', true))> Usar los temas que el grupo ya vio según su lista de chequeo</label>
            <fieldset class="campo" style="border:0;padding:0;margin-top:.4rem"><legend style="font-weight:600">Temas adicionales del temario</legend>
                @foreach ($unidades as $u)
                    @continue($u->hijos->isEmpty())
                    <p style="margin:.3rem 0 .1rem" class="suave">{{ $u->titulo }}</p>
                    @foreach ($u->hijos as $t)<label style="font-weight:400;display:inline-block;margin-right:1rem"><input type="checkbox" name="temas[]" data-unidad="{{ $u->id }}" value="{{ $t->id }}" @checked(in_array($t->id, $marcados, true))> {{ $t->titulo }}</label>@endforeach
                @endforeach
            </fieldset>
        @endif
        <div class="campo"><label for="tema">{{ $unidades->flatMap->hijos->isNotEmpty() ? 'Precisiones (opcional)' : 'Temas que debe integrar' }}</label>
            <input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="que incluya persistencia de datos">
            @error('tema')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="cantidad">Cantidad de ideas</label><input id="cantidad" name="cantidad" type="number" min="1" max="{{ \App\Material\IdeasProyecto::MAX_PROYECTOS }}" value="{{ old('cantidad', 3) }}" required></div>
            <div class="campo"><label for="semanas">Semanas</label><input id="semanas" name="semanas" type="number" min="1" max="{{ \App\Material\IdeasProyecto::MAX_SEMANAS }}" value="{{ old('semanas', 4) }}" required></div>
            <div class="campo"><label for="integrantes">Integrantes</label><input id="integrantes" name="integrantes" type="text" value="{{ old('integrantes', '2-3') }}" required pattern="[1-8](-[1-8])?" placeholder="1, 2 o 2-3">
                @error('integrantes')<div class="error" role="alert">Escriba un número o un rango, por ejemplo 2-3.</div>@enderror</div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="contexto">Contexto (opcional)</label>
            <input id="contexto" name="contexto" type="text" value="{{ old('contexto') }}" placeholder="empresas contratistas del sector petrolero en Barrancabermeja"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}" data-tema="{{ $c->temario_item_id }}"> {{ $c->titulo }}@if ($c->tema) <span class="suave">· {{ $c->tema->titulo }}</span>@endif</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
