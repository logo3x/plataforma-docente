<x-app titulo="Google Classroom">
    <h1>Google Classroom</h1>
    <p class="suave">La plataforma puede leer las entregas de una tarea de Classroom y escribirle las notas. Los estudiantes se cruzan por correo institucional,
        nunca por nombre. Las notas van como borrador: usted las devuelve desde Classroom cuando quiera.</p>
    @error('classroom')<div class="aviso aviso-error" role="alert">{{ $message }}</div>@enderror

    <section class="tarjeta">
        <h2 style="margin-top:0">Conexión</h2>
        @if (! $configurada)
            <p>La integración no está configurada en este servidor: falta crear las credenciales de Google de la institución.
                Los pasos están en <code>docs/classroom.md</code> (una sola vez, por quien administra la plataforma).</p>
        @elseif ($cuenta)
            <p>Conectada como <strong>{{ $cuenta->email }}</strong>.</p>
            <form method="POST" action="{{ route('classroom.desconectar') }}">@csrf
                <button class="boton boton-sec boton-chico" type="submit">Desconectar y revocar el acceso</button></form>
        @else
            <p>Conecte su cuenta institucional de Google. La plataforma pedirá permiso para ver sus cursos y sus listas, leer y calificar las tareas
                de sus estudiantes y leer los archivos que adjuntan. Nada más.</p>
            <p><a class="boton" href="{{ route('classroom.conectar') }}">Conectar con Google</a></p>
        @endif
    </section>

    <h2>Grupos</h2>
    <div class="tabla-notas"><table>
        <thead><tr><th>Grupo</th><th>Curso de Classroom</th><th></th></tr></thead>
        <tbody>@foreach ($grupos as $g)<tr>
            <td>{{ $g->dictado->materia->nombre }} {{ $g->dictado->periodo }} — {{ $g->nombre }}</td>
            <td>{{ $g->classroom_curso_nombre ?? '—' }}</td>
            <td>@if ($cuenta)<a href="{{ route('classroom.grupo', $g) }}">{{ $g->classroom_curso_id ? 'Ver' : 'Vincular' }}</a>@endif</td></tr>@endforeach</tbody>
    </table></div>
</x-app>
