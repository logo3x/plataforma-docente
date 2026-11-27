<x-app :titulo="'Textos y apuntes — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Textos y apuntes</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Textos y apuntes</h1>
        <a class="boton boton-sec" href="{{ route('apuntes.create', ['materia' => $materia, 'temas' => $marcados]) }}">Nuevo texto a mano</a>
    </div>
    <p class="suave">Lecturas explicativas de un tema, por secciones, con las ideas clave y preguntas de comprensión. Un apunte aprobado puede pasar al
        <a href="{{ route('contenido.index', $materia) }}">repositorio de contenido</a> y servir de material de referencia para lo que genere después.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores de la IA pendientes de revisar:</strong>
            @foreach ($borradores as $b)<a href="{{ route('apuntes.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conjuntos->isNotEmpty())
        <table class="tarjeta">
            <thead><tr><th>Texto</th><th class="num">Secciones</th><th class="num">Lectura</th><th>Origen</th><th></th></tr></thead>
            <tbody>
                @foreach ($conjuntos as $c)
                    <tr>
                        <td><a href="{{ route('apuntes.show', $c) }}">{{ $c->titulo }}</a></td>
                        <td class="num">{{ count($c->contenido['secciones'] ?? []) }}</td>
                        <td class="num">{{ app(\App\Material\TextoInformativo::class)->minutos($c->contenido) }} min</td>
                        <td class="suave">{{ $c->generacion_ia_id ? 'IA, revisado' : 'A mano' }}</td>
                        <td><a href="{{ route('apuntes.edit', $c) }}">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="tarjeta vacio"><strong>Aún no hay textos ni apuntes en esta materia.</strong> Genere uno con IA a partir del temario y su material, o escríbalo a mano.</div>
    @endif

    <h2 id="generar">Generar un texto con IA</h2>
    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>. Mientras tanto puede <a href="{{ route('apuntes.create', $materia) }}">escribirlo a mano</a>.</div>
    @endif
    <form method="POST" action="{{ route('apuntes.preparar', $materia) }}" class="tarjeta">
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
            <input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="Normalización hasta la tercera forma normal">
            @error('tema')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <div class="rejilla">
            <div class="campo"><label for="extension">Extensión</label>
                <select id="extension" name="extension">@foreach (['corta' => 'Corta (~600 palabras)', 'media' => 'Media (~1.200 palabras)', 'larga' => 'Larga (~2.000 palabras)'] as $k => $v)<option value="{{ $k }}" @selected(old('extension', 'media') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="campo"><label for="nivel">Nivel</label>
                <select id="nivel" name="nivel">@foreach (\App\Material\TextoInformativo::NIVELES as $k => $v)<option value="{{ $k }}" @selected(old('nivel') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="enfoque">Enfoque (opcional)</label>
            <input id="enfoque" name="enfoque" type="text" value="{{ old('enfoque') }}" placeholder="para estudiantes que faltaron a la clase; con ejemplos de inventarios"></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            <p class="suave" style="margin:.1rem 0 .3rem">Recomendado: el texto se basa en este material y lo cita como fuente.</p>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}" data-tema="{{ $c->temario_item_id }}"> {{ $c->titulo }}@if ($c->tema) <span class="suave">· {{ $c->tema->titulo }}</span>@endif</label>
            @empty <p class="suave">El repositorio está vacío.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
