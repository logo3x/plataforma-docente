<x-app :titulo="'Versiones de '.$a->sigla">
    <div class="migas"><a href="{{ route('artefactos.index', $a->materia) }}">Actividades evaluables — {{ $a->materia->nombre }}</a> › Versiones de {{ $a->sigla }}</div>
    <h1>Versiones de «{{ $a->titulo }}»</h1>
    <p>Tiene la(s) versión(es) <strong>{{ implode(', ', $a->versiones()) }}</strong>. La IA escribe otra con los mismos temas, tipos, pesos y dificultad,
        pero con otros datos y contextos, para que la respuesta de una no sirva en la otra. Usted la revisa ítem por ítem, junto al original, antes de agregarla.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    @if ($borradores->isNotEmpty())
        <div class="tarjeta"><strong>Borradores pendientes:</strong>
            @foreach ($borradores as $b)<a href="{{ route('variantes.borrador', $b) }}">{{ $b->created_at->format('Y-m-d H:i') }}</a>@if (! $loop->last) · @endif @endforeach</div>
    @endif

    @if ($conEntregas)
        <div class="aviso aviso-info">Este examen ya tiene entregas: una versión nueva cambiaría a qué estudiante le toca cada una. Importe una copia del examen con otra sigla y genere ahí la versión.</div>
    @elseif ($posibles === [])
        <div class="aviso aviso-info">Este examen ya tiene todas sus versiones.</div>
    @else
        <form method="POST" action="{{ route('variantes.preparar', $a) }}" class="tarjeta">
            @csrf
            <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Qué versión generar</legend>
                @foreach ($posibles as $v)
                    <label style="font-weight:400;display:block"><input type="radio" name="version" value="{{ $v }}" @checked($loop->first) required>
                        <strong>{{ $v }}</strong> — {{ ['B' => 'segunda versión para el mismo salón: dos vecinos no pueden copiarse', '2A' => 'segundo banco, a partir de A, para la otra jornada', '2B' => 'segundo banco, a partir de B, para la otra jornada'][$v] }}</label>
                @endforeach
                @error('version')<div class="error" role="alert">{{ $message }}</div>@enderror
            </fieldset>
            <div class="rejilla">
                <div class="campo"><label for="dictado_id">Periodo <span class="suave">(para el estilo y el alcance)</span></label>
                    <select id="dictado_id" name="dictado_id">@foreach ($dictados as $d)<option value="{{ $d->id }}">{{ $d->periodo }}</option>@endforeach</select></div>
                <div class="campo"><label for="motor_id">Motor</label>
                    <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
            </div>
            <div class="campo"><label for="indicaciones">Indicaciones (opcional)</label>
                <input id="indicaciones" name="indicaciones" type="text" value="{{ old('indicaciones') }}" placeholder="contextos del sector energético; en el reto, otra lista de datos"></div>
            <button class="boton" type="submit" @disabled($motores->isEmpty() || $dictados->isEmpty())>Ver el prompt antes de enviar</button>
        </form>
    @endif
</x-app>
