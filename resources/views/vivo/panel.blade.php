@php($dictado = $sesion->actividad->componente->division->dictado)
<x-app :titulo="'En vivo — '.$sesion->actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $sesion->actividad) }}">Calificador — {{ $sesion->actividad->nombre }}</a> › Sesión en vivo</div>
    <h1>En vivo — {{ $sesion->actividad->nombre }}</h1>
    <div class="tarjeta fila" style="justify-content:space-between">
        <div><div class="suave">Los estudiantes abren</div>
            <div class="codigo-vivo">{{ \App\Evaluacion\SesionEnVivo::url($sesion) }}</div>
            <div class="suave">Código <strong>{{ $sesion->codigo }}</strong>@if (count($sesion->actividad->artefacto->bancos()) > 1) · banco {{ $sesion->banco }}@endif · abierta a las {{ $sesion->created_at->format('H:i') }}</div></div>
        <a class="boton" href="{{ route('calificador.vivo.proyectar', $sesion) }}" target="_blank" rel="noopener">Proyectar el código</a>
    </div>
    <p class="suave">Las entregas llegan solas y se califican al instante (la parte del servidor; los retos de código y las abiertas siguen en el calificador).
        Esta página no se proyecta: muestra nombres y avance.</p>
    <livewire:panel-en-vivo :sesion="$sesion" />
    <p><a class="boton boton-sec" href="{{ route('calificador.show', $sesion->actividad) }}">Ir al calificador</a></p>
</x-app>
