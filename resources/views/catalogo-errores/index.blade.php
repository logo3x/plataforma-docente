@php($lenguajes = \App\Evaluacion\CatalogoErrores::LENGUAJES)
<x-app titulo="Errores frecuentes">
    <h1>Catálogo de errores frecuentes</h1>
    <p class="suave">Cuando un estudiante ve un error en un reto, el examen busca aquí su explicación en español y le dice qué revisar
        (solo en el nivel de ayuda <strong>completo</strong>; en el intermedio ve el mensaje original). Nunca le dice cómo resolver el reto.
        Escriba el mensaje tal como lo da el motor; <code>{x}</code> representa un nombre: <code>{x} is not defined</code>.</p>

    <nav class="fila" aria-label="Lenguajes" style="margin-bottom:1rem">
        @foreach ($lenguajes as $clave => $nombre)
            <a @class(['boton', 'boton-chico', 'boton-sec' => $clave !== $lenguaje]) href="{{ route('catalogo-errores.index', ['lenguaje' => $clave]) }}"
               @if ($clave === $lenguaje) aria-current="page" @endif>{{ $nombre }} <span @class(["suave" => $clave !== $lenguaje])>({{ $cuentas[$clave] ?? 0 }})</span>
                @if ($sinExplicarTotal[$clave] ?? 0)<span class="alerta-chip">{{ $sinExplicarTotal[$clave] }} sin explicar</span>@endif</a>
        @endforeach
    </nav>

    @if ($sinExplicar->isNotEmpty())
        <section class="tarjeta">
            <h2 style="margin-top:0">Mensajes que aparecieron sin explicación</h2>
            <p class="suave">Salieron en los retos de sus estudiantes y ninguna entrada los explica. Agregue los que se repiten.</p>
            <div class="tabla-notas"><table>
                <thead><tr><th>Mensaje</th><th class="num">Entregas</th><th class="num">Veces</th><th></th></tr></thead>
                <tbody>
                    @foreach ($sinExplicar->take(30) as $s)
                        <tr><td><code>{{ $s['mensaje'] }}</code></td><td class="num">{{ $s['entregas'] }}</td><td class="num">{{ $s['veces'] }}</td>
                            <td><a href="{{ route('catalogo-errores.index', ['lenguaje' => $lenguaje, 'nuevo' => \App\Evaluacion\CatalogoErrores::sugerirPatron($s['mensaje'])]) }}#nueva">Agregar al catálogo</a></td></tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    <h2>{{ $lenguajes[$lenguaje] }}</h2>
    @if ($entradas->isEmpty())
        <div class="tarjeta vacio"><strong>No hay entradas para {{ $lenguajes[$lenguaje] }}.</strong>Agregue una abajo o recupere las de la base.</div>
    @else
        <div class="tabla-notas"><table class="catalogo-errores">
            <thead><tr><th>Mensaje del motor</th><th>Explicación para el estudiante</th><th>Qué revisar</th><th>Ámbito</th><th class="num">Mostrada</th><th></th></tr></thead>
            <tbody>
                @foreach ($entradas as $e)
                    <tr @class(['inactiva' => ! $e->activo])>
                        <td><code>{{ $e->patron }}</code>@if ($e->es_regex) <span class="suave">(expresión regular)</span>@endif @if (! $e->activo)<span class="alerta-chip">desactivada</span>@endif</td>
                        <td>{{ $e->explicacion }}</td>
                        <td>{{ $e->revision ?? '—' }}</td>
                        <td>{{ $e->materia?->nombre ?? 'Todas mis materias' }}</td>
                        <td class="num">{{ $usos[$e->id] ?? 0 }}</td>
                        <td>
                            <details class="menu"><summary aria-label="Editar «{{ $e->patron }}»">Editar</summary>
                                <div class="contenido">
                                    <form method="POST" action="{{ route('catalogo-errores.update', $e) }}">
                                        @csrf @method('PUT')
                                        @include('catalogo-errores._campos', ['e' => $e, 'sufijo' => $e->id])
                                        <label style="font-weight:normal"><input type="checkbox" name="activo" value="1" @checked($e->activo)> Activa</label>
                                        <p><button class="boton boton-chico" type="submit">Guardar</button></p>
                                    </form>
                                    <form method="POST" action="{{ route('catalogo-errores.destroy', $e) }}" onsubmit="return confirm('¿Eliminar esta entrada?')">
                                        @csrf @method('DELETE')<button class="boton boton-peligro boton-chico" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif

    <div class="rejilla" style="margin-top:1rem">
        <section class="tarjeta" id="nueva">
            <h2 style="margin-top:0">Nueva entrada</h2>
            <form method="POST" action="{{ route('catalogo-errores.store') }}">
                @csrf
                <input type="hidden" name="lenguaje" value="{{ $lenguaje }}">
                @include('catalogo-errores._campos', ['e' => null, 'sufijo' => 'nueva', 'patron' => request('nuevo')])
                @foreach (['patron', 'explicacion', 'revision', 'materia_id'] as $campo)@error($campo)<div class="error" role="alert">{{ $message }}</div>@enderror @endforeach
                <p><button class="boton" type="submit">Agregar</button></p>
            </form>
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">Probar un mensaje</h2>
            <form method="GET" action="{{ route('catalogo-errores.index') }}">
                <input type="hidden" name="lenguaje" value="{{ $lenguaje }}">
                <label for="mensaje">Mensaje de error, tal como lo da el motor</label>
                <input id="mensaje" name="mensaje" type="text" value="{{ $prueba['mensaje'] ?? '' }}" placeholder="ReferenceError: total is not defined">
                <label for="materia">Como lo vería un estudiante de</label>
                <select id="materia" name="materia"><option value="">Cualquier materia</option>
                    @foreach ($materias as $m)<option value="{{ $m->id }}" @selected((string) request('materia') === (string) $m->id)>{{ $m->nombre }}</option>@endforeach</select>
                <p><button class="boton boton-sec" type="submit">Probar</button></p>
            </form>
            @if ($prueba)
                @if ($prueba['resultado'])
                    <div class="aviso aviso-ok" role="status"><strong>El estudiante vería:</strong> {{ $prueba['resultado']['texto'] }}
                        <div class="suave">Entrada: <code>{{ $prueba['resultado']['entrada']->patron }}</code></div></div>
                @else
                    <div class="aviso aviso-info" role="status">Ninguna entrada lo explica: el estudiante vería el mensaje original.</div>
                @endif
            @endif
            <hr>
            <form method="POST" action="{{ route('catalogo-errores.restaurar') }}">@csrf
                <button class="boton boton-sec boton-chico" type="submit">Recuperar las entradas de la base</button>
                <span class="suave">Agrega las del anexo A que haya eliminado; no toca las suyas.</span></form>
        </section>
    </div>
</x-app>
