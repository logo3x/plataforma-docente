<x-app :titulo="'Temario — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Temario</div>
    <h1>Plan curricular y temario — {{ $materia->nombre }}</h1>
    <p class="suave">El temario pertenece a la materia y se reutiliza en cada periodo. El avance (qué vio cada grupo) se marca en el registro de notas de cada grupo.</p>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif

    <div class="rejilla">
        <section class="tarjeta">
            <h2 style="margin-top:0">Extraer del plan curricular</h2>
            @if ($planes->isEmpty())
                <p>Cargue primero la guía de la asignatura en <a href="{{ route('contenido.index', $materia) }}">Contenido</a> (tipo «Plan curricular»). Es opcional: también puede escribir el temario a mano abajo.</p>
            @elseif ($motores->isEmpty())
                <p>Configure un <a href="{{ route('ia.motores') }}">motor de IA</a> para extraer el temario automáticamente.</p>
            @else
                <form method="POST" action="{{ route('temario.extraer', $materia) }}" x-data="{ enviando: false }" x-on:submit="enviando = true">
                    @csrf
                    <div class="campo"><label for="contenido_id">Documento</label>
                        <select id="contenido_id" name="contenido_id">@foreach ($planes as $p)<option value="{{ $p->id }}">{{ $p->titulo }} ({{ $p->tipo }})</option>@endforeach</select></div>
                    <div class="campo"><label for="motor_id">Motor</label>
                        <select id="motor_id" name="motor_id">@foreach ($motores as $m)<option value="{{ $m->id }}">{{ $m->nombre }} ({{ $m->es_local ? 'local' : 'nube' }})</option>@endforeach</select></div>
                    <button class="boton" type="submit" x-bind:disabled="enviando" x-text="enviando ? 'Leyendo el plan…' : 'Extraer temario'">Extraer temario</button>
                </form>
            @endif
            @foreach ($borradores as $b)<p><a href="{{ route('temario.borrador', $b) }}">Borrador pendiente del {{ $b->created_at->format('Y-m-d H:i') }}</a></p>@endforeach
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">Sugerir cómo organizar la clase</h2>
            <p>Para cada tema, la IA propone orden, material del repositorio y una actividad. Son sugerencias: usted las toma o las descarta.</p>
            <form method="POST" action="{{ route('temario.sugerir', $materia) }}" x-data="{ enviando: false }" x-on:submit="enviando = true">
                @csrf
                @if ($motores->isNotEmpty())<input type="hidden" name="motor_id" value="{{ $motores->first()->id }}">@endif
                <button class="boton boton-sec" type="submit" @disabled(! $hayTemas || $motores->isEmpty()) x-bind:disabled="enviando || {{ ! $hayTemas || $motores->isEmpty() ? 'true' : 'false' }}">Pedir sugerencias</button>
            </form>
        </section>
    </div>

    <h2>Temario</h2>
    <livewire:editor-temario :materia="$materia" />
</x-app>
