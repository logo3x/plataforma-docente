@php($dictado = $actividad->componente->division->dictado)
@php($maximo = (string) \Brick\Math\BigDecimal::of($actividad->valor_max)->strippedOfTrailingZeros())
<x-app :titulo="'Entregas — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }}</a> › {{ $actividad->nombre }}</div>
    <h1>Entregas — {{ $actividad->nombre }}</h1>
    <p class="suave">{{ $actividad->componente->division->nombre }} › {{ $actividad->componente->nombre }} · sobre {{ $maximo }}
        · {{ $actividad->grupos->pluck('nombre')->join(', ') ?: 'todos los grupos' }} @if ($actividad->es_grupal)· entrega grupal @endif</p>

    @if (session('resultados'))
        <div class="tarjeta" role="status">
            <h2 style="margin-top:0">Resultado de la carga</h2>
            <ul>@foreach (session('resultados') as $r)<li><span class="estado {{ ['recibida' => 'estado-aprobado', 'sin_identificar' => 'estado-incompleto', 'duplicada' => 'estado-sin_actividades', 'rechazada' => 'estado-reprobado'][$r['estado']] }}">{{ ['recibida' => '✓ Recibida', 'sin_identificar' => '? Sin identificar', 'duplicada' => '= Ya estaba', 'rechazada' => '✗ Rechazada'][$r['estado']] }}</span> {{ $r['mensaje'] }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="rejilla">
        <section class="tarjeta">
            <h2 style="margin-top:0">1. Cómo se entrega</h2>
            <form method="POST" action="{{ route('calificador.archivos.configurar', $actividad) }}" x-data="{ grupal: {{ $actividad->es_grupal ? 'true' : 'false' }} }">
                @csrf @method('PUT')
                <fieldset style="border:0;padding:0;margin:0">
                    <legend>Formato de entrega</legend>
                    @foreach (\App\Calificacion\EntregasArchivo::FORMATOS as $clave => $nombre)
                        <label style="font-weight:normal"><input type="radio" name="formato_entrega" value="{{ $clave }}" @checked($actividad->formato_entrega === $clave)> {{ $nombre }}</label>
                    @endforeach
                    <p class="suave" style="margin:.25rem 0">Con PDF consolidado se rechaza lo que no sea un PDF: nada de capturas sueltas.</p>
                </fieldset>
                <label for="rubrica_id">Rúbrica para calificar</label>
                <select id="rubrica_id" name="rubrica_id">
                    <option value="">Sin rúbrica: escribo la nota</option>
                    @foreach ($rubricas as $ru)<option value="{{ $ru->id }}" @selected($actividad->rubrica_id === $ru->id)>{{ $ru->titulo }}</option>@endforeach
                </select>
                @if ($rubricas->isEmpty())<p class="suave" style="margin:.25rem 0">La materia aún no tiene rúbricas. <a href="{{ route('rubricas.index', $dictado->materia) }}">Crear o generar una</a>.</p>@endif
                <label style="font-weight:normal;margin-top:.5rem"><input type="hidden" name="es_grupal" value="0"><input type="checkbox" name="es_grupal" value="1" x-model="grupal"> Entrega grupal</label>
                <div class="fila" x-show="grupal">
                    <div><label for="tamano_grupo">Integrantes previstos</label><input id="tamano_grupo" name="tamano_grupo" type="number" min="2" max="10" value="{{ $actividad->tamano_grupo }}" style="width:6rem"></div>
                    <div><label for="tope_exceso">Nota máxima si se excede</label><input id="tope_exceso" name="tope_exceso" type="text" inputmode="decimal" value="{{ $actividad->tope_exceso === null ? '' : (string) \Brick\Math\BigDecimal::of($actividad->tope_exceso)->strippedOfTrailingZeros() }}" style="width:6rem"></div>
                </div>
                @foreach (['formato_entrega', 'rubrica_id', 'tamano_grupo', 'tope_exceso'] as $campo)@error($campo)<div class="error" role="alert">{{ $message }}</div>@enderror @endforeach
                <p><button class="boton boton-sec" type="submit">Guardar</button></p>
            </form>
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">2. Cargar los archivos</h2>
            <form method="POST" action="{{ route('calificador.archivos.subir', $actividad) }}" enctype="multipart/form-data">
                @csrf
                <label for="archivos">Archivos de los estudiantes (puede seleccionar varios; hasta 20 MB cada uno)</label>
                <input id="archivos" type="file" name="archivos[]" multiple required @if ($actividad->formato_entrega === 'pdf') accept=".pdf,application/pdf" @endif>
                <p class="suave" style="margin:.25rem 0">Si el nombre lleva el documento del estudiante («1098765432_informe.pdf»; en grupo, los de todos),
                    la entrega queda a su nombre. Si no, se asigna al calificarla.</p>
                @error('archivos')<div class="error" role="alert">{{ $message }}</div>@enderror
                @error('archivos.*')<div class="error" role="alert">{{ $message }}</div>@enderror
                <p><button class="boton" type="submit">Cargar</button></p>
            </form>
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">3. Calificar y registrar</h2>
            @php($calificadas = $entregas->whereNotNull('puntaje')->count())
            <p>Entregas {{ $entregas->count() }} · calificadas {{ $calificadas }} · sin entregar {{ $sinEntrega->count() }}</p>
            <div class="fila">
                @php($primera = $entregas->firstWhere('puntaje', null) ?? $entregas->first())
                @if ($primera)<a class="boton boton-sec" href="{{ route('calificador.archivos.calificar', $primera) }}">{{ $entregas->firstWhere('puntaje', null) ? 'Calificar la próxima' : 'Revisar' }}</a>@endif
                <a class="boton boton-sec" href="{{ route('calificador.devolucion', $actividad) }}">Devolución a los estudiantes</a>
                <form method="POST" action="{{ route('calificador.registro', $actividad) }}">@csrf
                    <button class="boton" type="submit" @disabled($calificadas === 0)>Pasar notas al registro</button></form>
            </div>
        </section>
    </div>

    <h2>Entregas</h2>
    @if ($entregas->isEmpty())
        <div class="tarjeta vacio"><strong>Aún no hay entregas.</strong>Cargue los archivos que recibió de los estudiantes.</div>
    @else
        <div class="tabla-notas"><table>
            <thead><tr><th>Estudiante(s)</th><th>Archivo</th><th class="num">Páginas</th><th class="num">Nota</th><th>Comentario</th><th>Alertas</th><th></th></tr></thead>
            <tbody>
                @foreach ($entregas as $e)
                    @php($nota = $calificador->nota($e))
                    <tr>
                        <td>@if ($e->integrantes->isEmpty())<span class="alerta-chip">Sin identificar</span>@else{{ $e->integrantes->map->nombreCompleto()->join(' · ') }}@endif
                            @if ($actividad->tamano_grupo && $e->integrantes->count() > $actividad->tamano_grupo)<span class="alerta-chip">{{ $e->integrantes->count() }} integrantes</span>@endif</td>
                        <td>{{ $e->archivo_nombre }} <span class="suave">· {{ number_format($e->archivo_bytes / 1024, 0, ',', '.') }} KB</span></td>
                        <td class="num">{{ $e->paginas ?? '—' }}</td>
                        <td class="num definitiva">{{ $nota === null ? '—' : $escala->mostrar($nota) }}</td>
                        <td>{{ $e->comentario ? \Illuminate\Support\Str::limit($e->comentario, 60) : '—' }}</td>
                        <td>@foreach ($e->alertas as $a)<span class="alerta-chip">{{ $a->tipo === 'estudiante_en_dos_entregas' ? 'En dos entregas' : $a->tipo }}</span>@endforeach</td>
                        <td><a href="{{ route('calificador.archivos.calificar', $e) }}">{{ $e->puntaje === null ? 'Calificar' : 'Ver' }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif

    @if ($sinEntrega->isNotEmpty() && $entregas->isNotEmpty())
        <details class="tarjeta"><summary>Sin entrega ({{ $sinEntrega->count() }})</summary>
            <p>{{ $sinEntrega->map->nombreCompleto()->join(' · ') }}</p>
            <p class="suave">Al pasar al registro su nota queda vacía; si corresponde un cero, conviértalas desde el registro de notas.</p>
        </details>
    @endif
</x-app>
