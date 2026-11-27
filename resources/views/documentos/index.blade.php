<x-app :titulo="'Guías, manuales y otros documentos — '.$materia->nombre">
    @php($G = \App\Material\Generadores::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Guías, manuales y otros documentos</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Guías, manuales y otros documentos</h1>
        <a class="boton boton-sec" href="{{ route('documentos.create', ['materia' => $materia, 'temas' => $marcados]) }}">Nuevo documento a mano</a>
    </div>
    <p class="suave">Cada documento sale de un generador: una guía de laboratorio, un manual interactivo con ejercicios que se comprueban solos,
        un boletín o los que usted cree en <a href="{{ route('generadores.index') }}">Generadores de documentos</a>.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('documentos.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conjuntos->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Documento</th><th>Generador</th><th class="num">Secciones</th><th class="num">Ejercicios</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($conjuntos as $c)
                    <tr>
                        <td><a href="{{ route('documentos.show', $c) }}">{{ $c->titulo }}</a></td>
                        <td>{{ $c->contenido['generador'] ?: '—' }}</td>
                        <td class="num">{{ count($c->contenido['secciones'] ?? []) }}</td>
                        <td class="num">{{ app(\App\Material\DocumentoGenerado::class)->interactivas($c->contenido) ?: '—' }}</td>
                        <td class="suave">{{ $c->generacion_ia_id ? 'IA, revisado' : 'A mano' }}</td>
                        <td><a href="{{ route('documentos.edit', $c) }}">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay documentos en esta materia.</strong> Genere uno con IA o escríbalo a mano.</div>
    @endif

    <h2 id="generar">Generar un documento con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>.</div>
    @endif
    @error('tema')<div class="aviso aviso-error" role="alert">{{ $message }}</div>@enderror
    <form method="POST" action="{{ route('documentos.preparar', $materia) }}" class="tarjeta"
          x-data="{ generadores: @js(collect($generadores)->map(fn ($g) => \Illuminate\Support\Arr::except($g, ['modelo', 'instrucciones']))), elegido: @js(old('generador', request('generador', array_key_first($generadores)))), get g() { return this.generadores[this.elegido] || null; } }">
        @csrf
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Generador</legend>
            @foreach ($generadores as $k => $g)
                <label style="font-weight:400;display:block"><input type="radio" name="generador" value="{{ $k }}" x-model="elegido">
                    <strong>{{ $g['nombre'] }}</strong>@if ($g['funciono']) <span class="estado estado-aprobado">✓ Funcionó</span>@endif <span class="suave">— {{ $g['descripcion'] }}</span></label>
            @endforeach
            <p class="suave" style="margin:.3rem 0 0"><a href="{{ route('generadores.create') }}">Crear un generador propio</a></p>
        </fieldset>
        <template x-if="g && g.campos.length">
            <div class="rejilla">
                <template x-for="c in g.campos" :key="elegido + c.clave">
                    <div class="campo"><label :for="'campo-' + c.clave" x-text="c.etiqueta"></label>
                        <input type="text" :id="'campo-' + c.clave" :name="'campos[' + c.clave + ']'" :value="c.defecto" maxlength="1000"></div>
                </template>
            </div>
        </template>
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
            <input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="Recorrido de arreglos con for y forEach"></div>
        <div class="rejilla">
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}" data-tema="{{ $c->temario_item_id }}"> {{ $c->titulo }}@if ($c->tema) <span class="suave">· {{ $c->tema->titulo }}</span>@endif</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
