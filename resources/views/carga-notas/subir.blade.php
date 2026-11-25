<x-app titulo="Cargar notas desde archivo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('registro', $grupo) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }} — {{ $grupo->nombre }}</a> ›
        Cargar notas</div>
    <h1>Cargar notas desde archivo — {{ $grupo->nombre }}</h1>

    <div class="tarjeta">
        <p style="margin-top:0">Sirve para el CSV del calificador, una exportación de Classroom o cualquier hoja con una fila por estudiante.
            Se crea una <strong>columna nueva</strong> en el componente que elija.</p>
        <ul>
            <li>El cruce es por <strong>documento</strong>. Si el archivo no lo trae, por <strong>apellidos y nombres completos</strong>; nunca por apellido solo.</li>
            <li>Si el archivo tiene columna de grupo, las filas de otro grupo no se cargan aquí.</li>
            <li>Notas en escala 0–10, 0–50 o 0–100 se convierten a la del curso.</li>
            <li>Verá una vista previa antes de guardar nada.</li>
        </ul>

        <form method="POST" action="{{ route('carga-notas.subir', $grupo) }}" enctype="multipart/form-data" style="max-width:520px">
            @csrf
            <div class="campo">
                <label for="archivo">Archivo (.xlsx o .csv)</label>
                <input id="archivo" type="file" name="archivo" accept=".xlsx,.csv" required>
                @error('archivo') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="componente_id">Componente donde se crea la columna</label>
                <select id="componente_id" name="componente_id" required>
                    @foreach ($grupo->dictado->divisiones as $d)
                        <optgroup label="{{ $d->nombre }}">
                            @foreach ($d->componentes as $c)
                                <option value="{{ $c->id }}" @selected(old('componente_id') ? old('componente_id') == $c->id : ($division === $d->id && $loop->first))>{{ $d->nombre }} — {{ $c->nombre }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="nombre">Nombre de la columna <span class="suave">(opcional; por defecto, el del archivo)</span></label>
                <input id="nombre" name="nombre" type="text" value="{{ old('nombre') }}" maxlength="120" placeholder="Parcial 2">
            </div>
            <button class="boton" type="submit">Ver vista previa</button>
        </form>
    </div>
</x-app>
