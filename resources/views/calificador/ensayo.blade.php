<x-app :titulo="'Ensayo — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Modo de ensayo</div>
    <h1>Modo de ensayo — {{ $actividad->nombre }}</h1>
    <p class="suave">Pruebe el examen antes de aplicarlo: primero las pruebas automáticas, luego el examen de ensayo en un equipo igual al de la sala
        y, por último, su entrega aquí para cerrar el ciclo. Nada de esto crea notas.</p>

    <section class="tarjeta">
        <h2 style="margin-top:0">1. Pruebas automáticas
            <span @class(['estado', 'estado-aprobado' => $aprobadas, 'estado-reprobado' => ! $aprobadas])>{{ $aprobadas ? '✓ Pasan' : '✗ Hay fallas: corrija antes de aplicar' }}</span></h2>
        <table>
            <thead><tr><th class="num">#</th><th>Prueba</th><th>Resultado</th><th>Detalle</th></tr></thead>
            <tbody>
                @foreach ($pruebas as $p)
                    <tr>
                        <td class="num">{{ $p['numero'] }}</td>
                        <td>{{ $p['nombre'] }}</td>
                        <td>@switch($p['estado'])
                                @case('ok')<span class="estado estado-aprobado">✓ Pasa</span>@break
                                @case('falla')<span class="estado estado-reprobado">✗ Falla</span>@break
                                @default<span class="estado estado-incompleto">En el ensayo</span>
                            @endswitch</td>
                        <td class="suave">{{ $p['detalle'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="rejilla">
        <section class="tarjeta">
            <h2 style="margin-top:0">2. Examen de ensayo</h2>
            <p>Ábralo en un equipo de la sala, con el navegador que usarán los estudiantes. Al iniciar verifica solo el autoguardado, el cifrado y el entorno de código.</p>
            <ol class="suave" style="padding-left:1.2rem">
                <li>Escriba <code>console.log("hola")</code> en un reto y pruébelo varias veces: no debe contar salidas.</li>
                <li>Vaya a otra pestaña unos segundos y vuelva: debe contar exactamente una salida, con su duración.</li>
                <li>Responda algunas preguntas y finalice: se descarga <code>ENSAYO_{{ $actividad->artefacto->sigla }}_….json</code>.</li>
            </ol>
            <div class="fila">
                @foreach ($actividad->artefacto->bancos() as $b)
                    <a @class(['boton', 'boton-sec' => $b === 2]) href="{{ route('calificador.ensayo.examen', ['actividad' => $actividad, 'banco' => $b]) }}">Descargar examen de ensayo{{ count($actividad->artefacto->bancos()) > 1 ? " (banco {$b})" : '' }}</a>
                @endforeach
            </div>
            @if (count($actividad->artefacto->versiones()) > 1)<p class="suave">La versión sale del documento que escriba: pruebe con dos documentos distintos para ver las dos versiones.</p>@endif
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">3. Cerrar el ciclo</h2>
            <p>Suba la entrega de ensayo: se descifra con la clave del periodo y se califica como lo haría el calificador, sin crear notas.</p>
            <form method="POST" action="{{ route('calificador.ensayo.subir', $actividad) }}" enctype="multipart/form-data">
                @csrf
                <label for="entrega">Entrega de ensayo (.json)</label>
                <input id="entrega" name="entrega" type="file" accept=".json,application/json" required>
                @error('entrega')<div class="error" role="alert">{{ $message }}</div>@enderror
                <p><button class="boton" type="submit">Revisar la entrega de ensayo</button></p>
            </form>
        </section>
    </div>

    <h2>Ensayos registrados</h2>
    @forelse ($ensayos as $e)
        @if ($loop->first)<table><thead><tr><th>Fecha</th><th>Equipo</th><th>Equipo listo</th><th>Cifrada</th><th class="num">Salidas</th><th class="num">Ejecuciones</th></tr></thead><tbody>@endif
        <tr>
            <td>{{ $e->created_at->format('Y-m-d H:i') }}</td>
            <td>{{ $e->equipo }}</td>
            <td>{!! $e->equipo_listo ? '<span class="estado estado-aprobado">✓ Sí</span>' : '<span class="estado estado-reprobado">✗ No</span>' !!}</td>
            <td>{{ $e->cifrada ? 'Sí' : 'No' }}</td>
            <td class="num">{{ $e->salidas }}@if ($e->salidas) ({{ $e->segundos_fuera }} s)@endif</td>
            <td class="num">{{ $e->ejecuciones }}</td>
        </tr>
        @if ($loop->last)</tbody></table>@endif
    @empty
        <div class="tarjeta vacio"><strong>Este examen aún no se ha ensayado en un equipo.</strong></div>
    @endforelse
</x-app>
