<x-app :titulo="'Rúbricas — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Rúbricas</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Rúbricas</h1>
        <a class="boton boton-sec" href="{{ route('rubricas.create', $materia) }}">Nueva rúbrica a mano</a>
    </div>
    <p class="suave">Una rúbrica tiene criterios con su peso y, en cada uno, niveles de desempeño. Asociada a una pregunta abierta,
        se califica eligiendo un nivel por criterio y la plataforma calcula el puntaje.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('rubricas.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @forelse ($rubricas as $r)
        <section class="tarjeta">
            <div class="fila" style="justify-content:space-between;align-items:flex-start">
                <div>
                    <h2 style="margin:0">{{ $r->titulo }}</h2>
                    @if ($r->proposito)<p class="suave" style="margin:.2rem 0">{{ $r->proposito }}</p>@endif
                </div>
                <div class="fila">
                    <span class="suave">{{ $r->generacion_ia_id ? 'Generada con IA y revisada' : 'Hecha a mano' }} ·
                        {{ $r->preguntas_count ? "en {$r->preguntas_count} pregunta(s)" : 'sin asociar' }}</span>
                    <a class="boton boton-sec boton-chico" href="{{ route('rubricas.edit', $r) }}">Editar y asociar</a>
                </div>
            </div>
            <table style="margin-top:.5rem"><thead><tr><th>Criterio</th><th class="num">Peso</th><th>Niveles</th></tr></thead><tbody>
                @foreach ($r->criterios as $c)
                    <tr><td>{{ $c['nombre'] }}</td><td class="num">{{ $c['peso'] }} %</td>
                        <td class="suave">{{ collect($c['niveles'])->map(fn ($n) => "{$n['nombre']} ({$n['porcentaje']} %)")->join(' · ') }}</td></tr>
                @endforeach
            </tbody></table>
        </section>
    @empty
        <div class="tarjeta vacio"><strong>Aún no hay rúbricas en esta materia.</strong> Genere una con IA a partir de una pregunta abierta, o hágala a mano.</div>
    @endforelse

    <h2 id="generar">Generar una rúbrica con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('rubricas.create', $materia) }}">hacerla a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('rubricas.preparar', $materia) }}" class="tarjeta">
        @csrf
        <div class="campo"><label for="pregunta_id">Pregunta abierta del banco</label>
            <select id="pregunta_id" name="pregunta_id">
                <option value="">— Ninguna: describo la actividad abajo —</option>
                @foreach ($abiertas as $p)
                    <option value="{{ $p->id }}" @selected((int) old('pregunta_id', request('pregunta')) === $p->id)>{{ \Illuminate\Support\Str::limit($p->enunciado['texto'] ?? '', 110) }}@if ($p->rubrica) · ya tiene «{{ $p->rubrica->titulo }}»@endif</option>
                @endforeach
            </select>
            <p class="suave" style="margin:.2rem 0 0">La IA recibe el enunciado y la respuesta de referencia. Al aprobar, la rúbrica queda asociada a esa pregunta.</p></div>
        <div class="campo"><label for="proposito">O describa la actividad que se califica</label>
            <textarea id="proposito" name="proposito" rows="3" placeholder="Sustentación del proyecto final: explicar el diseño de la base de datos y justificar las decisiones">{{ old('proposito') }}</textarea>
            @error('proposito')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="criterios">Número de criterios</label><input id="criterios" name="criterios" type="number" min="1" max="{{ \App\Evaluacion\Rubricas::MAX_CRITERIOS }}" value="{{ old('criterios', 4) }}" required></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="niveles">Niveles, de mayor a menor</label>
            <input id="niveles" name="niveles" type="text" value="{{ old('niveles', implode(', ', \App\Evaluacion\Rubricas::NIVELES_POR_DEFECTO)) }}"></div>
        @if ($unidades->flatMap->hijos->isNotEmpty())
            <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Temas del temario</legend>
                @foreach ($unidades as $u)
                    @continue($u->hijos->isEmpty())
                    <p style="margin:.3rem 0 .1rem" class="suave">{{ $u->titulo }}</p>
                    @foreach ($u->hijos as $t)<label style="font-weight:400;display:inline-block;margin-right:1rem"><input type="checkbox" name="temas[]" value="{{ $t->id }}" @checked(in_array($t->id, old('temas', [])))> {{ $t->titulo }}</label>@endforeach
                @endforeach
            </fieldset>
        @endif
        <div class="campo"><label for="tema">Precisiones del tema (opcional)</label><input id="tema" name="tema" type="text" value="{{ old('tema') }}"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}"> {{ $c->titulo }}</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
