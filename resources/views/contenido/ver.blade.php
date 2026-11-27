<x-app :titulo="$contenido->titulo">
    <div class="migas"><a href="{{ route('contenido.index', $contenido->materia) }}">Contenido de {{ $contenido->materia->nombre }}</a> › {{ $contenido->titulo }}</div>
    <h1>{{ $contenido->titulo }}</h1>
    <p><a href="{{ route('contenido.descargar', $contenido) }}">Descargar el original</a></p>
    @if ($otrasMaterias->isNotEmpty())
        <form method="POST" action="{{ route('contenido.copiar', $contenido) }}" class="fila tarjeta" style="gap:.5rem;align-items:flex-end">
            @csrf
            <div class="campo" style="margin:0"><label for="destino">Usar también en otra materia</label>
                <select id="destino" name="destino" required><option value="">Elija la materia…</option>@foreach ($otrasMaterias as $om)<option value="{{ $om->id }}">{{ $om->nombre }}</option>@endforeach</select></div>
            <button class="boton boton-sec">Copiar</button>
        </form>
    @endif
    <p class="suave">Texto que verá la IA. Si sale desordenado o vacío (PDF escaneado), conviene cargar una versión en Word o texto.</p>
    <div class="texto-estudiante" style="max-width:none;background:#fff">{{ $contenido->texto_extraido }}</div>
</x-app>
