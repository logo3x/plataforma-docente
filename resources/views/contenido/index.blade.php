<x-app :titulo="'Contenido — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Contenido</div>
    <h1>Contenido de {{ $materia->nombre }}</h1>
    <p class="suave">Microcurrículo, presentaciones, lecturas y código de ejemplo. De aquí toma la IA el contexto, así lo que genera se ciñe a lo que usted enseñó.
        El material es suyo: se puede descargar o borrar en cualquier momento.</p>
    @foreach (session('avisos', []) as $a)<div class="aviso aviso-info">{{ $a }}</div>@endforeach

    <form method="POST" action="{{ route('contenido.store', $materia) }}" enctype="multipart/form-data" class="tarjeta fila">
        @csrf
        <div style="flex:2"><label for="archivos">Archivos (PDF, Word, PowerPoint, texto, código)</label>
            <input id="archivos" type="file" name="archivos[]" multiple required></div>
        <div><label for="tipo">Tipo</label>
            <select id="tipo" name="tipo">
                @foreach (['plan_curricular' => 'Plan curricular', 'syllabus' => 'Syllabus', 'presentacion' => 'Presentación', 'lectura' => 'Lectura', 'codigo' => 'Código de ejemplo', 'guia' => 'Guía', 'otro' => 'Otro'] as $v => $t)
                    <option value="{{ $v }}">{{ $t }}</option>
                @endforeach
            </select></div>
        <button class="boton" type="submit" style="align-self:flex-end">Cargar</button>
    </form>
    @if ($errors->any())<div class="aviso aviso-error" role="alert">{{ $errors->first() }}</div>@endif

    @if ($contenidos->isEmpty())
        <div class="tarjeta vacio"><strong>El repositorio está vacío.</strong>Sin material, la IA solo cuenta con el tema que usted escriba.</div>
    @else
        <table>
            <thead><tr><th>Título</th><th>Unidad o tema</th><th>Tipo</th><th class="num">Texto extraído</th><th>Cargado</th><th></th></tr></thead>
            <tbody>
                @foreach ($contenidos as $c)
                    <tr>
                         <td><a href="{{ route('contenido.show', $c) }}">{{ $c->titulo }}</a>@if ($c->version > 1) <span class="suave">versión {{ $c->version }}</span>@endif · <a href="{{ route('contenido.descargar', $c) }}" aria-label="Descargar «{{ $c->titulo }}»">Descargar</a></td>
                        <td><form method="POST" action="{{ route('contenido.tema', $c) }}">@csrf @method('PUT')
                            <select name="temario_item_id" aria-label="Unidad o tema de «{{ $c->titulo }}»" onchange="this.form.submit()" style="max-width:16rem">
                                <option value="">Sin asignar</option>
                                @foreach ($temario as $u)
                                    <option value="{{ $u->id }}" @selected($c->temario_item_id === $u->id)>{{ $u->titulo }} (toda la unidad)</option>
                                    @foreach ($u->hijos as $t)<option value="{{ $t->id }}" @selected($c->temario_item_id === $t->id)>&nbsp;&nbsp;{{ $t->titulo }}</option>@endforeach
                                @endforeach
                            </select><noscript><button class="boton boton-sec boton-chico">Asignar</button></noscript></form></td>
                        <td>{{ $c->tipo }}</td>
                        <td class="num">{{ number_format(mb_strlen((string) $c->texto_extraido), 0, ',', '.') }} caracteres</td>
                        <td>{{ $c->created_at->format('Y-m-d') }}</td>
                        <td><form method="POST" action="{{ route('contenido.destroy', $c) }}">@csrf @method('DELETE')<button class="enlace">Eliminar</button></form></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p><a class="boton" href="{{ route('generacion.create', $materia) }}">Generar un cuestionario o parcial con IA</a></p>
</x-app>
