<x-app titulo="Editar estudiante">
    <div class="migas"><a href="{{ route('registro', $grupo) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }} — {{ $grupo->nombre }}</a> › Estudiante</div>
    <h1>{{ $estudiante->nombreCompleto() }}</h1>
    <p class="suave">Documento {{ $estudiante->documento }} (es la identidad del estudiante y no se edita aquí).</p>
    @if ($estudiante->revisar_nombre)
        <div class="aviso aviso-info" role="note">La plantilla traía el nombre sin coma, así que todo quedó en «apellidos». Sepárelo en apellidos y nombres.</div>
    @endif

    <form method="POST" action="{{ route('estudiantes.update', [$grupo, $estudiante]) }}" class="tarjeta" style="max-width:640px">
        @csrf @method('PUT')
        @foreach (['apellidos' => 'Apellidos', 'nombres' => 'Nombres', 'email' => 'Correo', 'telefono' => 'Teléfono', 'observacion' => 'Observación'] as $campo => $etiqueta)
            <div class="campo">
                <label for="{{ $campo }}">{{ $etiqueta }}</label>
                <input id="{{ $campo }}" name="{{ $campo }}" type="{{ $campo === 'email' ? 'email' : 'text' }}" value="{{ old($campo, $estudiante->$campo) }}"
                       @required(in_array($campo, ['apellidos', 'nombres']))>
                @error($campo) <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>
        @endforeach
        <fieldset class="campo" style="border:0;padding:0">
            <legend style="font-weight:600">Situación en el grupo</legend>
            <label style="font-weight:400"><input type="radio" name="activo" value="1" @checked($activo)> Activo</label>
            <label style="font-weight:400"><input type="radio" name="activo" value="0" @checked(! $activo)> Retirado (sale de la planilla; sus notas se conservan)</label>
        </fieldset>
        <button class="boton" type="submit">Guardar</button>
        <a class="boton boton-sec" href="{{ route('registro', $grupo) }}">Cancelar</a>
    </form>
</x-app>
