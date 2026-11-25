<x-app :titulo="$contenido->titulo">
    <div class="migas"><a href="{{ route('contenido.index', $contenido->materia) }}">Contenido de {{ $contenido->materia->nombre }}</a> › {{ $contenido->titulo }}</div>
    <h1>{{ $contenido->titulo }}</h1>
    <p class="suave">Texto que verá la IA. Si sale desordenado o vacío (PDF escaneado), conviene cargar una versión en Word o texto.</p>
    <div class="texto-estudiante" style="max-width:none;background:#fff">{{ $contenido->texto_extraido }}</div>
</x-app>
