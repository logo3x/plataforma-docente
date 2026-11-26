<x-app :titulo="'Devolución — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Devolución</div>
    <h1>Devolución a los estudiantes — {{ $actividad->nombre }}</h1>
    <p class="suave">Cada estudiante recibe solo su informe: su nota, el detalle de cada ítem con la respuesta correcta y los comentarios.
        Nunca ve las notas de los demás ni las alertas internas.</p>
    @if (session('error_devolucion'))<div class="aviso aviso-error" role="alert">{{ session('error_devolucion') }}</div>@endif

    <div class="rejilla">
        <section class="tarjeta">
            <h2 style="margin-top:0">1. Liberar</h2>
            @if ($actividad->devolucion_liberada_en)
                <p><span class="estado estado-aprobado">✓ Liberada el {{ $actividad->devolucion_liberada_en->format('d/m/Y H:i') }}</span></p>
                <form method="POST" action="{{ route('calificador.devolucion.revocar', $actividad) }}">@csrf
                    <button class="boton boton-sec boton-chico" onclick="return confirm('¿Retirar la devolución? Los enlaces dejarán de funcionar.')">Retirar la devolución</button></form>
            @else
                @if ($pendientes)
                    <div class="aviso aviso-error" role="alert"><strong>{{ $pendientes }} estudiante(s) aún no han presentado.</strong>
                        El informe trae las respuestas correctas: si alguien presentará después (por ejemplo, un supletorio), espere antes de liberar.</div>
                @endif
                <form method="POST" action="{{ route('calificador.devolucion.liberar', $actividad) }}" class="fila">@csrf
                    <label for="dias" style="margin:0">Enlaces válidos por</label>
                    <input id="dias" name="dias" type="number" min="1" max="180" value="30" style="width:5rem"> días
                    <button class="boton" @if ($pendientes) onclick="return confirm('Hay estudiantes sin presentar. ¿Liberar de todos modos?')" @endif>Liberar devolución</button>
                </form>
            @endif
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">2. Entregar</h2>
            <p>Por archivo (uno por estudiante, para Classroom o correo) o por enlace personal que cada uno abre sin cuenta.</p>
            <div class="fila">
                <a class="boton boton-sec" href="{{ route('calificador.devolucion.zip', $actividad) }}" @unless ($actividad->devolucion_liberada_en) aria-disabled="true" style="opacity:.5;pointer-events:none" @endunless>Descargar informes (.zip)</a>
                <a class="boton boton-sec" href="{{ route('calificador.devolucion.enlaces', $actividad) }}" @unless ($actividad->devolucion_liberada_en) aria-disabled="true" style="opacity:.5;pointer-events:none" @endunless>Enlaces personales (.csv)</a>
            </div>
        </section>
        <section class="tarjeta" style="grid-column:1 / -1">
            <h2 style="margin-top:0">3. Enviar por correo institucional</h2>
            <p>Cada estudiante recibe un correo con su enlace personal; el correo no lleva la nota. Las respuestas le llegan a usted
                ({{ auth()->user()->email }}).@if ($dominio) Solo se envía a direcciones {{ "@".$dominio }}.@endif</p>
            @if ($correoSinConfigurar)
                <div class="aviso aviso-info" role="status"><strong>El correo de la plataforma no está configurado:</strong> los mensajes quedan en el registro del servidor y no llegan a nadie.
                    Configure el servidor de correo institucional (variables <code>MAIL_*</code>, ver <code>docs/instalacion.md</code>).</div>
            @endif
            @if ($actividad->devolucion_liberada_en)
                <form method="POST" action="{{ route('calificador.devolucion.correo', $actividad) }}">
                    @csrf
                    <div class="campo"><label for="mensaje">Mensaje para todos <span class="suave">(opcional; va en el correo, antes del enlace)</span></label>
                        <textarea id="mensaje" name="mensaje" rows="2" maxlength="1000" style="font-family:inherit" placeholder="Revisen sobre todo la pregunta 4; la retomamos el jueves.">{{ old('mensaje') }}</textarea></div>
                    <div class="fila">
                        <label style="font-weight:400"><input type="radio" name="alcance" value="pendientes" checked> Solo a quienes aún no lo tienen</label>
                        <label style="font-weight:400"><input type="radio" name="alcance" value="todos"> A todos de nuevo</label>
                        <button class="boton" @disabled(! $enviables) onclick="return confirm('¿Enviar los correos?')">Enviar correos</button>
                        <span class="suave">{{ $enviables }} estudiante(s) con correo válido</span>
                    </div>
                </form>
            @else
                <p class="suave">Disponible al liberar la devolución.</p>
            @endif
        </section>
        <section class="tarjeta">
            <h2 style="margin-top:0">Opciones del informe</h2>
            <form method="POST" action="{{ route('calificador.devolucion.configurar', $actividad) }}">@csrf
                <label style="font-weight:400"><input type="checkbox" name="informe_muestra_original" value="1" @checked($actividad->informe_muestra_original) onchange="this.form.submit()">
                    Con supletorio o recuperación, mostrar también la nota original (3.6.5)</label>
            </form>
        </section>
    </div>

    <h2>Estudiantes <span class="suave">· {{ $recibidas }} de {{ $filas->count() }} recibieron su informe</span></h2>
    <table>
        <thead><tr><th>Estudiante</th><th>Presentó</th><th>Informe</th><th>Correo</th><th>Recibido</th><th></th></tr></thead>
        <tbody>
            @foreach ($filas as $f)
                @php($e = $f['estudiante'])
                @php($d = $f['devolucion'])
                <tr>
                    <td>{{ $e->nombreCompleto() }}</td>
                    <td>{{ $f['presento'] ? 'Sí' : 'No' }}</td>
                    <td><a href="{{ route('calificador.devolucion.ver', [$actividad, $e]) }}" target="_blank">Ver</a></td>
                    <td>
                        @if ($d?->correo_error)<span class="estado estado-reprobado">✗ Falló</span> <span class="suave">{{ $d->correo_error }}</span>
                        @elseif ($d?->correo_en_cola_en)<span class="estado estado-incompleto">En cola</span>
                        @elseif ($d?->correo_enviado_en)<span class="estado estado-aprobado">✓ Enviado {{ $d->correo_enviado_en->format('d/m H:i') }}</span> <span class="suave">a {{ $d->correo_destino }}@if ($d->correos > 1) ({{ $d->correos }} veces)@endif</span>
                        @elseif ($f['impedimento'] && $f['presento'])<span class="suave">{{ $f['impedimento'] }}</span>
                        @else — @endif
                        @if ($d && $actividad->devolucion_liberada_en && ! $f['impedimento'])
                            <form method="POST" action="{{ route('calificador.devolucion.correo.uno', $d) }}" style="display:inline">@csrf
                                <button class="enlace">{{ $d->correo_enviado_en || $d->correo_error ? 'Reenviar' : 'Enviar' }}</button></form>
                        @endif
                    </td>
                    <td>
                        @if ($d?->abierta_en)<span class="estado estado-aprobado">✓ Lo abrió el {{ $d->abierta_en->format('d/m H:i') }}</span>@if ($d->aperturas > 1) <span class="suave">({{ $d->aperturas }} veces)</span>@endif
                        @elseif ($d?->entregada_en)<span class="estado estado-aprobado">✓ Entregado el {{ $d->entregada_en->format('d/m') }}</span>
                        @elseif ($d && ! $d->revocada_en)<span class="estado estado-incompleto">Pendiente</span>
                        @else — @endif
                    </td>
                    <td>
                        @if ($d && $actividad->devolucion_liberada_en && ! $d->abierta_en)
                            <form method="POST" action="{{ route('calificador.devolucion.marcar', $d) }}">@csrf
                                <button class="enlace">{{ $d->entregada_en ? 'Desmarcar' : 'Marcar como entregado' }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-app>
