Hola, {{ $estudiante->nombres }}:

Ya puede ver su informe de «{{ $actividad->nombre }}» de {{ $materia->nombre }}: su nota, el detalle de cada pregunta y mis comentarios.
@if ($mensaje)

{{ $mensaje }}
@endif

Ver mi informe: {{ $enlace }}

El enlace es personal: no lo comparta. Vale hasta el {{ $vence?->format('d/m/Y') }}.
Si tiene dudas sobre su calificación, responda este correo.

{{ $docente->name }}
