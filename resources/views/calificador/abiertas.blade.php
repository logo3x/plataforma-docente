<x-app :titulo="'Abiertas — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Respuestas abiertas</div>
    <h1>Respuestas abiertas — {{ $actividad->nombre }}</h1>
    <p class="suave">Por pregunta: una pregunta en todas las entregas, sin abrir paneles. Por estudiante: todas las abiertas de una entrega. Cada puntaje se guarda al escribirlo.
        Los comentarios llegan al estudiante: escríbalos como los diría en clase.</p>
    <livewire:calificar-abiertas :actividad="$actividad" />
    <p class="fila"><a class="boton" href="{{ route('calificador.show', $actividad) }}">Volver al calificador</a>
        <a class="boton boton-sec" href="{{ route('calificador.abiertas.csv', $actividad) }}">Descargar respuestas, puntajes y comentarios (CSV)</a></p>
</x-app>
