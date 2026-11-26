<x-app :titulo="'Classroom — '.$grupo->nombre">
    <div class="migas"><a href="{{ route('classroom.index') }}">Google Classroom</a> › {{ $grupo->dictado->materia->nombre }} — {{ $grupo->nombre }}</div>
    <h1>Classroom — {{ $grupo->dictado->materia->nombre }} {{ $grupo->nombre }}</h1>
    @error('classroom')<div class="aviso aviso-error" role="alert">{{ $message }}</div>@enderror
    @if ($error)<div class="aviso aviso-error" role="alert">{{ $error }}</div>@endif

    <section class="tarjeta">
        <h2 style="margin-top:0">Curso</h2>
        @if ($grupo->classroom_curso_id)<p>Vinculado con <strong>{{ $grupo->classroom_curso_nombre }}</strong>. {{ $cruzados }} de {{ $total }} estudiantes cruzados por correo.</p>@endif
        <form method="POST" action="{{ route('classroom.grupo.vincular', $grupo) }}" class="fila">@csrf
            <select name="curso" aria-label="Curso de Classroom" style="max-width:28rem">
                @foreach ($cursos as $c)<option value="{{ $c['id'] }}" @selected($grupo->classroom_curso_id === $c['id'])>{{ $c['nombre'] }}{{ $c['seccion'] ? ' · '.$c['seccion'] : '' }}</option>@endforeach
            </select>
            <button class="boton" type="submit" @disabled($cursos === [])>{{ $grupo->classroom_curso_id ? 'Cambiar y volver a cruzar' : 'Vincular y cruzar estudiantes' }}</button>
        </form>
        @if ($grupo->classroom_curso_id)
            <form method="POST" action="{{ route('classroom.grupo.cruzar', $grupo) }}" style="margin-top:.5rem">@csrf
                <button class="boton boton-sec boton-chico" type="submit">Volver a cruzar (si cambió la lista)</button></form>
        @endif
    </section>

    @if ($c = session('cruce'))
        <section class="tarjeta">
            <h2 style="margin-top:0">Cruce por correo</h2>
            <p><strong>{{ $c['cruzados'] }}</strong> cruzados.</p>
            @if ($c['sin_correo'])<p><strong>Sin correo en la plataforma ({{ count($c['sin_correo']) }}):</strong> {{ implode(' · ', $c['sin_correo']) }}. Agréguelo en sus datos o en la lista de la secretaría.</p>@endif
            @if ($c['no_estan'])<p><strong>No están en el curso de Classroom ({{ count($c['no_estan']) }}):</strong> {{ implode(' · ', $c['no_estan']) }}.</p>@endif
            @if ($c['solo_en_classroom'])<p><strong>Solo en Classroom ({{ count($c['solo_en_classroom']) }}):</strong> {{ implode(' · ', $c['solo_en_classroom']) }}. No se cruzan por nombre: si son estudiantes del grupo, revise su correo.</p>@endif
        </section>
    @endif
</x-app>
