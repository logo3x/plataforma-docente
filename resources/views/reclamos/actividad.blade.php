@php use App\Models\Reclamo as R; @endphp
<x-app :titulo="'Reclamos — '.$actividad->nombre">
    <div class="migas"><a href="{{ route('calificador.show', $actividad) }}">Calificador — {{ $actividad->nombre }}</a> › Reclamos</div>
    <h1>Reclamos — {{ $actividad->nombre }}</h1>
    <p class="suave">Si el reclamo revela un error del examen (enunciado ambiguo, respuesta válida que la clave no contemplaba),
        la corrección se aplica a <strong>todo el grupo</strong>, no solo al reclamante, y las notas del registro se actualizan con el motivo.
        Todo queda en el <a href="{{ route('reclamos.materia', $actividad->componente->division->dictado->materia) }}">registro de la materia</a> para no repetir el error.</p>
    @if (session('error_reclamo'))<div class="aviso aviso-error" role="alert">{{ session('error_reclamo') }}</div>@endif

    <form method="POST" action="{{ route('calificador.reclamos.store', $actividad) }}" class="tarjeta" style="max-width:820px">
        @csrf
        <h2 style="margin-top:0">Registrar un reclamo</h2>
        <div class="rejilla">
            <div class="campo"><label for="estudiante_id">Estudiante</label>
                <select id="estudiante_id" name="estudiante_id" required>
                    @foreach ($estudiantes as $e)<option value="{{ $e->id }}" @selected(old('estudiante_id') == $e->id)>{{ $e->nombreCompleto() }}</option>@endforeach
                </select></div>
            <div class="campo"><label for="artefacto_pregunta_id">Ítem <span class="suave">(si aplica)</span></label>
                <select id="artefacto_pregunta_id" name="artefacto_pregunta_id">
                    <option value="">— general —</option>
                    @foreach ($actividad->artefacto?->items ?? [] as $i)
                        <option value="{{ $i->id }}" @selected(old('artefacto_pregunta_id') == $i->id)>{{ $loop->iteration }}. {{ \Illuminate\Support\Str::limit($i->pregunta->enunciado['texto'], 70) }}</option>
                    @endforeach
                </select></div>
        </div>
        <div class="campo"><label for="descripcion">Qué alega</label>
            <textarea id="descripcion" name="descripcion" rows="3" required style="font-family:inherit">{{ old('descripcion') }}</textarea>
            @error('descripcion')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <button class="boton" type="submit">Registrar</button>
    </form>

    @forelse ($reclamos as $r)
        <section class="tarjeta" id="reclamo-{{ $r->id }}">
            <div class="fila" style="justify-content:space-between">
                <strong>#{{ $r->id }} · {{ $r->estudiante->nombreCompleto() }}@if ($r->item) · ítem {{ $numero($r->item) }}@endif</strong>
                <span class="estado {{ ['abierto' => 'estado-incompleto', 'aceptado' => 'estado-aprobado', 'rechazado' => 'estado-sin_actividades'][$r->estado] }}">{{ ucfirst($r->estado) }}</span>
            </div>
            @if ($r->item)<p class="suave" style="margin:.2rem 0">{{ $r->item->pregunta->enunciado['texto'] }}</p>@endif
            <p style="white-space:pre-wrap"><strong>Alega:</strong> {{ $r->descripcion }}</p>

            @if ($r->estado !== 'abierto')
                <div class="aviso {{ $r->estado === 'aceptado' ? 'aviso-ok' : 'aviso-info' }}">
                    <strong>{{ $r->estado === 'aceptado' ? R::ACCIONES[$r->accion] : 'Respuesta' }}:</strong> {{ $r->resolucion }}
                    @if (isset($r->detalle['notas_recalculadas']))<br>{{ $r->detalle['notas_recalculadas'] }} nota(s) del registro cambiaron.@endif
                    @if (isset($r->detalle['respuestas_recalificadas']))<br>{{ $r->detalle['respuestas_recalificadas'] }} respuesta(s) recalificadas con la clave corregida.@endif
                    @if (isset($r->detalle['nota_nueva']))<br>Nota: {{ $r->detalle['nota_anterior'] === null ? 'vacía' : $escala->mostrar($r->detalle['nota_anterior']) }} → {{ $escala->mostrar($r->detalle['nota_nueva']) }}.@endif
                    <div class="suave">{{ $r->atendido_en->format('Y-m-d H:i') }}@if ($r->docente) · {{ $r->docente->name }}@endif</div>
                </div>
            @else
                @php($tipo = $r->item?->pregunta->tipo)
                @php($clave = $r->item?->pregunta->clave ?? [])
                <form method="POST" action="{{ route('calificador.reclamos.resolver', $r) }}" x-data="{ decision: 'rechazar', accion: '' }">
                    @csrf
                    <fieldset style="border:0;padding:0"><legend style="font-weight:600">Decisión</legend>
                        <label style="font-weight:400;display:inline;margin-right:1rem"><input type="radio" name="decision" value="rechazar" x-model="decision"> Rechazar</label>
                        <label style="font-weight:400;display:inline"><input type="radio" name="decision" value="aceptar" x-model="decision"> Aceptar</label>
                    </fieldset>
                    <div x-show="decision === 'aceptar'" x-cloak class="tarjeta" style="box-shadow:none;margin:.5rem 0">
                        <label for="accion-{{ $r->id }}">Corrección</label>
                        <select id="accion-{{ $r->id }}" name="accion" x-model="accion">
                            <option value="">— elija —</option>
                            @if ($r->item)<option value="anulacion">Anular el ítem para todo el grupo</option>@endif
                            @if ($r->item && in_array($tipo, $corregibles, true))<option value="correccion_clave">Corregir la clave y recalificar a todo el grupo</option>@endif
                            <option value="ajuste_nota">Ajustar solo la nota de este estudiante</option>
                        </select>

                        <div x-show="accion === 'anulacion'" style="margin-top:.5rem">
                            <label style="font-weight:400"><input type="radio" name="regla" value="dar_por_buena" checked> Darlo por bueno a todos</label>
                            <label style="font-weight:400"><input type="radio" name="regla" value="retirar_y_reescalar"> Retirarlo y reescalar</label>
                        </div>

                        @if ($r->item && in_array($tipo, $corregibles, true))
                            <div x-show="accion === 'correccion_clave'" style="margin-top:.5rem">
                                @switch($tipo)
                                    @case('unica')
                                        <label for="correcta-{{ $r->id }}">Respuesta correcta</label>
                                        <select id="correcta-{{ $r->id }}" name="correcta">@foreach ($r->item->pregunta->enunciado['opciones'] as $k => $t)<option value="{{ $k }}" @selected((string) $clave['correcta'] === (string) $k)>{{ $k }}) {{ $t }}</option>@endforeach</select>
                                        @break
                                    @case('vf')
                                        <label style="font-weight:400"><input type="radio" name="correcta" value="true" @checked($clave['correcta'])> Verdadero</label>
                                        <label style="font-weight:400"><input type="radio" name="correcta" value="false" @checked(! $clave['correcta'])> Falso</label>
                                        @break
                                    @case('multiple')
                                        <p style="margin:0 0 .2rem;font-weight:600">Opciones correctas</p>
                                        @foreach ($r->item->pregunta->enunciado['opciones'] as $k => $t)<label style="font-weight:400"><input type="checkbox" name="correctas[]" value="{{ $k }}" @checked(in_array((string) $k, array_map('strval', $clave['correctas']), true))> {{ $k }}) {{ $t }}</label>@endforeach
                                        @break
                                    @case('completar')
                                        <p class="suave" style="margin:0 0 .3rem">Respuestas aceptadas por hueco, separadas por «|». Agregue la que el estudiante demostró válida.</p>
                                        @foreach ($clave['huecos'] as $n => $h)
                                            <label for="hueco-{{ $r->id }}-{{ $n }}">Hueco {{ $n + 1 }}</label>
                                            <input id="hueco-{{ $r->id }}-{{ $n }}" name="huecos[]" type="text" value="{{ implode(' | ', (array) $h) }}">
                                        @endforeach
                                        @break
                                    @case('emparejar')
                                        <p class="suave" style="margin:0 0 .3rem">La pareja correcta de cada elemento.</p>
                                        @foreach ($r->item->pregunta->enunciado['izquierda'] as $k => $t)
                                            <label for="par-{{ $r->id }}-{{ $k }}">{{ $k }}. {{ $t }}</label>
                                            <select id="par-{{ $r->id }}-{{ $k }}" name="pares[{{ $k }}]">@foreach ($r->item->pregunta->enunciado['derecha'] as $dk => $dt)<option value="{{ $dk }}" @selected((string) ($clave['pares'][$k] ?? '') === (string) $dk)>{{ $dt }}</option>@endforeach</select>
                                        @endforeach
                                        @break
                                    @case('ordenar')
                                        <p class="suave" style="margin:0 0 .3rem">La posición correcta de cada paso (1 = primero).</p>
                                        @php($posicion = array_flip(array_map('strval', $clave['orden'] ?? [])))
                                        @foreach ($r->item->pregunta->enunciado['pasos'] as $k => $t)
                                            <label for="orden-{{ $r->id }}-{{ $k }}">{{ $t }}</label>
                                            <select id="orden-{{ $r->id }}-{{ $k }}" name="orden[{{ $k }}]">@for ($i = 1; $i <= count($r->item->pregunta->enunciado['pasos']); $i++)<option value="{{ $i }}" @selected(($posicion[(string) $k] ?? -1) + 1 === $i)>{{ $i }}</option>@endfor</select>
                                        @endforeach
                                        @break
                                    @case('reto')
                                        <label for="verif-{{ $r->id }}">Verificaciones del reto (JSON). Corrija la que no contemplaba una solución válida; las fracciones deben sumar 1.</label>
                                        <textarea id="verif-{{ $r->id }}" name="verificaciones" rows="10" spellcheck="false" style="font-size:.85rem">{{ json_encode($clave['verificaciones'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea>
                                        @unless (\App\Calificacion\CalificadorDeterminista::loCalificaElServidor('reto', $clave))
                                            <p class="suave">Después, en el calificador, pulse «Ejecutar retos de código»: las entregas de este reto quedan pendientes para volver a ejecutarlas.</p>
                                        @endunless
                                        @break
                                @endswitch
                            </div>
                        @endif

                        <div x-show="accion === 'ajuste_nota'" class="campo" style="margin-top:.5rem;max-width:12rem">
                            <label for="valor-{{ $r->id }}">Nota nueva</label><input id="valor-{{ $r->id }}" name="valor" type="text" inputmode="decimal">
                        </div>
                    </div>
                    <div class="campo"><label for="resolucion-{{ $r->id }}">Respuesta al estudiante y motivo</label>
                        <input id="resolucion-{{ $r->id }}" name="resolucion" type="text" required minlength="5" maxlength="1000"
                               placeholder="La pregunta admitía dos lecturas; se da por buena a todo el grupo"></div>
                    <button class="boton">Resolver</button>
                </form>
            @endif
        </section>
    @empty
        <p class="suave">No hay reclamos registrados en esta actividad.</p>
    @endforelse
</x-app>
