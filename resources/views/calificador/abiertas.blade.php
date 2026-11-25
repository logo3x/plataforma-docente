<x-app :titulo="'Abiertas — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Respuestas abiertas</div>
    <h1>Respuestas abiertas — {{ $actividad->nombre }}</h1>
    <p class="suave">Califique una pregunta en todas las entregas sin abrir paneles. Cada puntaje se guarda al escribirlo.
        Los comentarios llegan al estudiante: escríbalos como los diría en clase.</p>
    <livewire:calificar-abiertas :actividad="$actividad" />
    <p><a class="boton" href="{{ route('calificador.show', $actividad) }}">Volver al calificador</a></p>
</x-app>
