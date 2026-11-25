<x-app titulo="Cargar lista de clase">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('dictados.show', $grupo->dictado) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }}</a> ›
        {{ $grupo->nombre }}</div>
    <h1>Cargar lista de clase — {{ $grupo->nombre }}</h1>

    <div class="tarjeta">
        <p style="margin-top:0">Use la plantilla oficial de secretaría (XLSX o CSV). Las columnas se reconocen por su nombre, en cualquier orden:
            <code>nombre_alumno</code>, <code>numero_documento</code>, <code>numero_matriculas</code>, <code>numero_convocatorias</code>,
            <code>observacion</code>, <code>email</code>, <code>telefono</code>.</p>
        <p>El documento es la única identidad. Si vuelve a cargar la lista, se agregan los nuevos, los existentes conservan sus notas
            y los que ya no aparecen solo se listan: nunca se borran.</p>
        <p><a href="{{ route('importacion.plantilla') }}">Descargar la plantilla en blanco</a></p>

        <form method="POST" action="{{ route('importacion.previa', $grupo) }}" enctype="multipart/form-data">
            @csrf
            <div class="campo" style="max-width: 480px">
                <label for="archivo">Archivo</label>
                <input id="archivo" type="file" name="archivo" accept=".xlsx,.csv" required>
                @error('archivo') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>
            <button class="boton" type="submit">Ver vista previa</button>
            <span class="suave">Nada se importa hasta que usted confirme.</span>
        </form>
    </div>
</x-app>
