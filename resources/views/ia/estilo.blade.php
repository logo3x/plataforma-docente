<x-app :titulo="'Estilo — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Estilo para la IA</div>
    <h1>Estilo de {{ $materia->nombre }} para la IA</h1>
    <p class="suave">Capa 2 del prompt (8.2). Lo que escriba aquí acompaña cada generación de esta materia.</p>
    <form method="POST" action="{{ route('materias.estilo.guardar', $materia) }}" class="tarjeta" style="max-width:820px">
        @csrf @method('PUT')
        <div class="campo"><label for="modalidad_practica">Modalidad práctica</label>
            <select id="modalidad_practica" name="modalidad_practica">
                @foreach (['integrada' => 'Integrada: el código corre en el navegador', 'anexo' => 'Anexo externo (proyecto o documento aparte)', 'ninguna' => 'Sin código (materia teórica)'] as $v => $t)
                    <option value="{{ $v }}" @selected($materia->modalidad_practica === $v)>{{ $t }}</option>
                @endforeach
            </select></div>
        @foreach (\App\Http\Controllers\PlantillasIaController::CAMPOS_ESTILO as $campo => $etiqueta)
            <div class="campo"><label for="es-{{ $campo }}">{{ $etiqueta }}</label>
                <textarea id="es-{{ $campo }}" name="{{ $campo }}" rows="{{ in_array($campo, ['unidades', 'alcance_grupo']) ? 3 : 2 }}" style="font-family:inherit">{{ old($campo, $materia->estilo[$campo] ?? '') }}</textarea></div>
        @endforeach
        <button class="boton" type="submit">Guardar estilo</button>
    </form>
</x-app>
