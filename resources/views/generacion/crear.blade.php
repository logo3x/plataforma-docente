<x-app :titulo="'Generar con IA — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Generar con IA</div>
    <h1>Generar un cuestionario o parcial con IA</h1>
    <p class="suave">La IA produce un <strong>borrador</strong> que usted revisa antes de guardarlo. Puede editar el prompt antes de enviarlo.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($motores->isEmpty())
        <div class="aviso aviso-info">Primero configure un motor de IA en <a href="{{ route('ia.motores') }}">Motores de IA</a>.</div>
    @endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores pendientes:</strong>
            @foreach ($borradores as $b)<a href="{{ route('generacion.show', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    <form method="POST" action="{{ route('generacion.preparar', $materia) }}" class="tarjeta">
        @csrf
        <div class="rejilla">
            <div class="campo"><label for="dictado_id">Periodo</label>
                <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
            <div class="campo"><label for="tipo">Tipo</label>
                <select id="tipo" name="tipo">@foreach (['quiz' => 'Quiz', 'parcial' => 'Parcial', 'taller' => 'Taller'] as $v => $t)<option value="{{ $v }}" @selected(old('tipo') === $v)>{{ $t }}</option>@endforeach</select></div>
            <div class="campo"><label for="valor">Valor</label><input id="valor" name="valor" type="text" value="{{ old('valor', '5.0') }}" required></div>
            <div class="campo"><label for="grupo">Grupo <span class="suave">(para ceñirse a su alcance)</span></label>
                <select id="grupo" name="grupo"><option value="">Todos</option>@foreach ($dictados->flatMap->grupos->unique('nombre') as $g)<option>{{ $g->nombre }}</option>@endforeach</select></div>
            <div class="campo"><label for="motor_id">Motor</label>
                <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
        </div>
        @if ($unidades->flatMap->hijos->isNotEmpty())
            <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Temas del temario</legend>
                @foreach ($unidades as $u)
                    @continue($u->hijos->isEmpty())
                    <p style="margin:.3rem 0 .1rem" class="suave">{{ $u->titulo }}</p>
                    @foreach ($u->hijos as $t)<label style="font-weight:400;display:inline-block;margin-right:1rem"><input type="checkbox" name="temas[]" value="{{ $t->id }}" @checked(in_array($t->id, array_map('intval', (array) old('temas', request('temas', []))), true))> {{ $t->titulo }}</label>@endforeach
                @endforeach
                <p class="suave" style="margin:.3rem 0 0">Si elige un grupo, la IA recibe además qué temas ya vio según la lista de chequeo y cuáles no debe evaluar.</p>
            </fieldset>
        @endif
        <div class="campo"><label for="tema">{{ $unidades->flatMap->hijos->isNotEmpty() ? 'Precisiones del tema (opcional)' : 'Tema' }}</label><input id="tema" name="tema" type="text" value="{{ old('tema') }}" placeholder="Arreglos, recorridos y funciones de orden superior"></div>

        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Cantidad de ítems por tipo</legend>
            <div class="rejilla" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
                @foreach (\App\Evaluacion\GeneradorBanco::TIPOS as $t => $etiqueta)
                    <label style="font-weight:400">{{ $etiqueta }} <input type="number" min="0" max="40" name="cantidades[{{ $t }}]" value="{{ old("cantidades.$t", ['unica' => 6, 'vf' => 4][$t] ?? 0) }}" style="width:5rem"></label>
                @endforeach
            </div>
            @error('cantidades')<div class="error" role="alert">{{ $message }}</div>@enderror
        </fieldset>
        <div class="rejilla">
            <div class="campo"><label for="obligatorio">Debe incluir</label><input id="obligatorio" name="obligatorio" type="text" value="{{ old('obligatorio') }}" placeholder="una situación problema por ejercicio práctico"></div>
            <div class="campo"><label for="excluido">No debe incluir</label><input id="excluido" name="excluido" type="text" value="{{ old('excluido') }}" placeholder="CSS, formularios"></div>
        </div>

        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Material de referencia (<a href="{{ route('contenido.index', $materia) }}">repositorio</a>)</legend>
            @forelse ($contenidos as $c)
                <label style="font-weight:400"><input type="checkbox" name="contenidos[]" value="{{ $c->id }}"> {{ $c->titulo }} <span class="suave">({{ number_format(mb_strlen((string) $c->texto_extraido), 0, ',', '.') }} caracteres)</span></label>
            @empty <p class="suave">El repositorio está vacío: la IA solo contará con el tema.</p> @endforelse
        </fieldset>
        <button class="boton" type="submit" @disabled($motores->isEmpty())>Ver el prompt antes de enviar</button>
    </form>
</x-app>
