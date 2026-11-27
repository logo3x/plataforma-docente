<x-app :titulo="$g ? 'Editar generador' : 'Nuevo generador'">
    @php($G = \App\Material\Generadores::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('generadores.index') }}">Generadores de documentos</a> › {{ $g ? $g->nombre : 'Nuevo' }}</div>
    <h1>{{ $g ? 'Editar generador' : 'Nuevo generador' }}</h1>
    @if ($errors->any())<div class="aviso aviso-error" role="alert"><ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ $g ? route('generadores.update', $g) : route('generadores.store') }}" class="tarjeta">
        @csrf @if ($g) @method('PUT') @endif
        <input type="hidden" name="origen" value="{{ old('origen', $datos['origen']) }}">
        <div class="rejilla">
            <div class="campo"><label for="nombre">Nombre</label><input id="nombre" name="nombre" type="text" maxlength="80" required value="{{ old('nombre', $datos['nombre']) }}" placeholder="Taller de repaso"></div>
            <div class="campo"><label for="descripcion">Para qué sirve <span class="suave">(opcional)</span></label><input id="descripcion" name="descripcion" type="text" maxlength="300" value="{{ old('descripcion', $datos['descripcion']) }}"></div>
        </div>
        <div class="campo"><label for="instrucciones">Instrucciones para la IA</label>
            <textarea id="instrucciones" name="instrucciones" rows="9" required maxlength="5000" style="font-family:inherit">{{ old('instrucciones', $datos['instrucciones']) }}</textarea>
            <p class="suave" style="margin:.2rem 0 0">Escriba qué documento quiere y con qué secciones. Use <code>{{ '{{' }}campo}}</code> para lo que cambia cada vez.
                El tema, el grupo, el material de referencia, el estilo de la materia y lo que ya existe los pone la plataforma sola.</p></div>
        <div class="campo"><label for="campos">Campos <span class="suave">(uno por línea: clave | etiqueta | valor por defecto)</span></label>
            <textarea id="campos" name="campos" rows="4" maxlength="3000" spellcheck="false" placeholder="duracion | Duración | 2 horas">{{ old('campos', $datos['campos']) }}</textarea></div>
        <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Código</legend>
            @foreach ($G::CODIGO as $k => $v)
                <label style="font-weight:400;display:block"><input type="radio" name="codigo" value="{{ $k }}" @checked(old('codigo', $datos['codigo']) === $k)> {{ $v }}</label>
            @endforeach
        </fieldset>
        <div class="fila">
            <button class="boton" type="submit">Guardar generador</button>
            <a href="{{ route('generadores.index') }}">Cancelar</a>
        </div>
    </form>
    @if ($g)
        <form method="POST" action="{{ route('generadores.destroy', $g) }}" onsubmit="return confirm('¿Eliminar este generador? Los documentos que creó se conservan.')" style="margin-top:1rem">
            @csrf @method('DELETE')<button class="enlace">Eliminar generador</button></form>
    @endif
</x-app>
