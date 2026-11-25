@php
    $etiquetas = ['documento' => 'Documento', 'nombre' => 'Nombre completo', 'apellidos' => 'Apellidos', 'nombres' => 'Nombres', 'nota' => 'Nota', 'grupo' => 'Grupo'];
    $estados = [
        'ok' => ['estado-aprobado', '✓ Se carga'],
        'sin_nota' => ['estado-sin_actividades', '— Vacía'],
        'no_cruzo' => ['estado-reprobado', '✗ No cruzó'],
        'ambiguo' => ['estado-reprobado', '✗ Ambiguo'],
        'otro_grupo' => ['estado-incompleto', '↷ Otro grupo'],
        'repetida' => ['estado-incompleto', '⧉ Repetida'],
        'fuera_de_rango' => ['estado-reprobado', '✗ Fuera de rango'],
        'no_numerica' => ['estado-reprobado', '✗ No numérica'],
    ];
    $escala = $grupo->dictado->escala();
@endphp
<x-app titulo="Vista previa de notas">
    <div class="migas"><a href="{{ route('registro', $grupo) }}">{{ $grupo->dictado->materia->nombre }} {{ $grupo->dictado->periodo }} — {{ $grupo->nombre }}</a> ›
        <a href="{{ route('carga-notas.create', $grupo) }}">Cargar notas</a> › Vista previa</div>
    <h1>Vista previa — «{{ $carga['archivo'] }}»</h1>
    <p class="suave">Columna nueva en <strong>{{ $componente->division->nombre }} — {{ $componente->nombre }}</strong>. Nada se ha guardado todavía.</p>

    <form method="GET" action="{{ route('carga-notas.previa', $grupo) }}" class="tarjeta">
        <input type="hidden" name="token" value="{{ $token }}">
        <h2 style="margin-top:0">Columnas del archivo</h2>
        <p class="suave">Detectadas automáticamente. Corríjalas si hace falta y pulse «Volver a analizar».</p>
        <div class="rejilla" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr))">
            @foreach ($etiquetas as $campo => $etiqueta)
                <div class="campo">
                    <label for="col_{{ $campo }}">{{ $etiqueta }}</label>
                    <select id="col_{{ $campo }}" name="col_{{ $campo }}">
                        <option value="">— no hay —</option>
                        @foreach ($carga['encabezados'] as $i => $h)
                            <option value="{{ $i }}" @selected($columnas[$campo] === $i)>{{ $h }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <div class="campo">
                <label for="escala">Escala del archivo</label>
                <select id="escala" name="escala">
                    @foreach ($escalas as $e)
                        <option value="{{ $e }}" @selected($escalaOrigen === $e)>0 a {{ $e }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <button class="boton boton-sec" type="submit">Volver a analizar</button>
    </form>

    @if (session('error_columnas'))
        <div class="aviso aviso-error" role="alert">{{ session('error_columnas') }}</div>
    @endif

    @if ($analisis)
        @php($r = $analisis['resumen'])
        <div class="tarjeta">
            <p style="margin-top:0"><strong>{{ $r['total'] }}</strong> filas.
                <strong>{{ $r['importables'] }}</strong> se cargan
                ({{ $r['por_documento'] }} por documento, {{ $r['por_nombre'] }} por nombre completo).
                @if ($r['no_cruzo']) <strong>{{ $r['no_cruzo'] }}</strong> no cruzaron. @endif
                @if ($r['otro_grupo']) <strong>{{ $r['otro_grupo'] }}</strong> son de otro grupo. @endif
                @if ($r['repetidas']) <strong>{{ $r['repetidas'] }}</strong> repetidas. @endif
                @if ($r['fuera_de_rango']) <strong>{{ $r['fuera_de_rango'] }}</strong> con valores inválidos. @endif
                @if ($r['sin_nota']) <strong>{{ $r['sin_nota'] }}</strong> vacías. @endif
                @if ($escalaOrigen !== (string) \Brick\Math\BigDecimal::of($escala->maximo)->strippedOfTrailingZeros())
                    <br>Las notas se convierten de 0–{{ $escalaOrigen }} a {{ $escala->mostrar($escala->minimo) }}–{{ $escala->mostrar($escala->maximo) }}.
                @endif
            </p>
            <form method="POST" action="{{ route('carga-notas.confirmar', $grupo) }}" class="fila">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                @foreach ($columnas as $campo => $i)<input type="hidden" name="col_{{ $campo }}" value="{{ $i }}">@endforeach
                <input type="hidden" name="escala" value="{{ $escalaOrigen }}">
                <div><label for="nombre">Nombre de la columna</label>
                    <input id="nombre" name="nombre" type="text" value="{{ $carga['nombre'] }}" required maxlength="120"></div>
                <button class="boton" type="submit" style="align-self:flex-end" @disabled($r['importables'] === 0)>
                    Crear columna con {{ $r['importables'] }} notas
                </button>
                <a class="boton boton-sec" style="align-self:flex-end" href="{{ route('registro', $grupo) }}">Cancelar</a>
            </form>
        </div>

        <table>
            <caption class="sr-only">Filas del archivo</caption>
            <thead><tr><th class="num">Fila</th><th>En el archivo</th><th>Estudiante</th><th>Cruce</th><th class="num">Nota archivo</th><th class="num">Queda</th><th>Resultado</th></tr></thead>
            <tbody>
                @foreach ($analisis['filas'] as $f)
                    <tr>
                        <td class="num">{{ $f['numero'] }}</td>
                        <td>{{ $f['documento'] }} @if ($f['documento'] && $f['nombre'])·@endif {{ $f['nombre'] }} @if ($f['grupo'])<span class="suave">({{ $f['grupo'] }})</span>@endif</td>
                        <td>{{ $f['estudiante'] ?? '—' }}</td>
                        <td>{{ ['documento' => 'Documento', 'nombre' => 'Nombre completo'][$f['cruce']] ?? '—' }}</td>
                        <td class="num">{{ $f['original'] }}</td>
                        <td class="num">{{ $f['valor'] === null ? '—' : $escala->mostrar($f['valor']) }}</td>
                        <td><span class="estado {{ $estados[$f['estado']][0] }}">{{ $estados[$f['estado']][1] }}</span>
                            @if ($f['detalle'])<div class="suave" style="font-size:.85rem">{{ $f['detalle'] }}</div>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($analisis['sin_nota'])
            <h2>Estudiantes del grupo sin nota en el archivo</h2>
            <p class="suave">Su celda queda vacía (no promedia). Revise si entregaron en otra actividad.</p>
            <ul>@foreach ($analisis['sin_nota'] as $e)<li>{{ $e->documento }} — {{ $e->nombreCompleto() }}</li>@endforeach</ul>
        @endif
    @endif
</x-app>
