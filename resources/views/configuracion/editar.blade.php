<x-app :titulo="'Configuración — '.$a->sigla">
    @php($CA = \App\Evaluacion\ConfiguracionActividad::class)
    <div class="migas"><a href="{{ route('artefactos.index', $a->materia) }}">Actividades evaluables — {{ $a->materia->nombre }}</a> › Configuración de {{ $a->sigla }}</div>
    <h1>Configuración de «{{ $a->titulo }}»</h1>
    <p class="suave">Opciones de aplicación, aparte de las preguntas: cambiarlas no obliga a rehacer el contenido.
        @if ($repartido) Si ya repartió el examen, vuelva a descargarlo para que los cambios lleguen al archivo.@endif</p>

    <form method="POST" action="{{ route('configuracion.update', $a) }}" x-data="{ reloj: {{ $c['reloj_minutos'] ? 'true' : 'false' }}, desconectado: {{ $c['modo_desconectado'] ? 'true' : 'false' }} }">
        @csrf @method('PUT')
        @if ($errors->any())<div class="aviso aviso-error" role="alert"><ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

        <section class="tarjeta">
            <h2 style="margin-top:0">Portada</h2>
            <div class="campo"><label for="instrucciones">Instrucciones para el estudiante</label>
                <textarea id="instrucciones" name="instrucciones" rows="3" style="font-family:inherit">{{ old('instrucciones', $c['instrucciones']) }}</textarea></div>
        </section>

        <section class="tarjeta">
            <h2 style="margin-top:0">Tiempo y conexión</h2>
            <label style="font-weight:400"><input type="checkbox" name="con_reloj" value="1" x-model="reloj"> Con límite de tiempo y cierre automático <span class="suave">(si no, el reloj cuenta hacia arriba)</span></label>
            <div class="campo" x-show="reloj"><label for="reloj_minutos">Minutos disponibles</label>
                <input id="reloj_minutos" name="reloj_minutos" type="number" min="1" max="600" value="{{ old('reloj_minutos', $c['reloj_minutos'] ?? 90) }}" style="max-width:8rem">
                <p class="suave" style="margin:.2rem 0 0">Avisa a los 10 y a los 5 minutos finales; al terminar, cierra y genera la entrega con lo respondido.</p></div>
            <label style="font-weight:400;margin-top:.6rem"><input type="checkbox" name="modo_desconectado" value="1" x-model="desconectado"> Modo desconectado: el examen no inicia ni continúa mientras el equipo tenga internet</label>
            <div class="rejilla" x-show="desconectado">
                <div class="campo"><label for="sonda">Recurso que se intenta alcanzar</label><input id="sonda" name="sonda" type="url" value="{{ old('sonda', $c['sonda']) }}"></div>
                <div class="campo"><label for="intervalo_sonda">Cada cuántos segundos</label><input id="intervalo_sonda" name="intervalo_sonda" type="number" min="5" max="300" value="{{ old('intervalo_sonda', $c['intervalo_sonda']) }}"></div>
            </div>
            <p class="suave" x-show="desconectado" style="margin:.2rem 0 0">Solo bloquea cuando confirma que hay internet. Es un disuasor y un registro, no un control infranqueable.</p>
        </section>

        <section class="tarjeta">
            <h2 style="margin-top:0">Salidas de la ventana y alertas</h2>
            <label style="font-weight:400"><input type="checkbox" name="contar_salidas" value="1" @checked(old('contar_salidas', $c['contar_salidas']))> Contar las salidas de la ventana, con su duración <span class="suave">(probar código no cuenta)</span></label>
            <div class="rejilla">
                <div class="campo"><label for="umbral_salidas">Alertar desde cuántas salidas</label><input id="umbral_salidas" name="umbral_salidas" type="number" min="1" max="100" value="{{ old('umbral_salidas', $c['umbral_salidas']) }}"></div>
                <div class="campo"><label for="umbral_salidas_segundos">… o desde cuántos segundos fuera en total</label><input id="umbral_salidas_segundos" name="umbral_salidas_segundos" type="number" min="5" max="3600" value="{{ old('umbral_salidas_segundos', $c['umbral_salidas_segundos']) }}"></div>
                <div class="campo"><label for="duracion_minima_minutos">Marcar como anómala una duración menor a (minutos)</label><input id="duracion_minima_minutos" name="duracion_minima_minutos" type="number" min="1" max="600" value="{{ old('duracion_minima_minutos', $c['duracion_minima_minutos']) }}" placeholder="sin mínimo"></div>
            </div>
            <p class="suave" style="margin:0">Las alertas informan; no bajan la nota por sí solas.</p>
        </section>

        <section class="tarjeta">
            <h2 style="margin-top:0">Código</h2>
            @unless ($conRetos)<p class="suave">Este examen no tiene retos de código; estas opciones no lo afectan.</p>@endunless
            <fieldset class="campo" style="border:0;padding:0"><legend style="font-weight:600">Nivel de ayuda de sintaxis</legend>
                @foreach ($CA::NIVELES_AYUDA as $k => $v)
                    <label style="font-weight:400;display:block"><input type="radio" name="nivel_ayuda" value="{{ $k }}" @checked(old('nivel_ayuda', $c['nivel_ayuda']) === $k)> {{ $v }}</label>
                @endforeach
                <p class="suave" style="margin:.2rem 0 0">La ayuda señala y explica; nunca escribe código por el estudiante.</p>
            </fieldset>
            <div class="rejilla">
                <div class="campo"><label for="tiempo_ejecucion_ms">Límite por ejecución (milisegundos)</label><input id="tiempo_ejecucion_ms" name="tiempo_ejecucion_ms" type="number" min="500" max="20000" step="100" value="{{ old('tiempo_ejecucion_ms', $c['tiempo_ejecucion_ms']) }}"></div>
            </div>
            <label style="font-weight:400"><input type="checkbox" name="anexo" value="1" @checked(old('anexo', $c['anexo']))> La parte práctica se entrega como anexo aparte (recordar al finalizar)</label>
        </section>

        <div class="fila">
            <button class="boton" type="submit">Guardar configuración</button>
            @foreach ($a->versiones() as $v)
                <a class="boton boton-sec" href="{{ route('configuracion.imprimible', ['artefacto' => $a, 'version' => $v]) }}" target="_blank">Respaldo imprimible{{ count($a->versiones()) > 1 ? " (versión {$v})" : '' }}</a>
            @endforeach
        </div>
    </form>
</x-app>
