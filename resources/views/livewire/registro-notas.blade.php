@php($escala = $tablero->escala())
<div>
    <div class="fila" style="justify-content: space-between; margin-bottom: .75rem">
        <div class="fila">
            <a class="boton boton-sec boton-chico" href="{{ route('importacion.create', $grupo) }}">Actualizar lista</a>
            <a class="boton boton-sec boton-chico" href="{{ route('carga-notas.create', [$grupo, 'division' => $division?->id]) }}">Cargar notas desde archivo</a>
            <a class="boton boton-sec boton-chico" href="{{ route('cierre', $grupo) }}">Cierre</a>
            <details class="menu">
                <summary class="boton boton-sec boton-chico">Exportar ▾</summary>
                <div class="contenido" style="left:0;right:auto">
                    <a href="{{ route('exportar.xlsx', $grupo) }}">Excel con todas las notas (.xlsx)</a>
                    <a href="{{ route('exportar.csv', $grupo) }}" style="display:block">Planilla consolidada (.csv)</a>
                </div>
            </details>
            <span class="suave">Escala {{ $escala->mostrar($escala->minimo) }}–{{ $escala->mostrar($escala->maximo) }} · aprueba con {{ $escala->mostrar($escala->aprobatoria) }} · celda vacía no promedia</span>
        </div>
        <div class="fila">
            <span wire:loading class="suave" role="status">Guardando…</span>
            <span wire:loading.remove class="suave" role="status">✓ Guardado</span>
            @if ($ultimaAccion)
                <button class="boton boton-sec boton-chico" wire:click="deshacer" title="Deshacer: {{ $ultimaAccion['descripcion'] }}">
                    ↶ Deshacer: {{ \Illuminate\Support\Str::limit($ultimaAccion['descripcion'], 60) }}
                </button>
            @endif
        </div>
    </div>

    @if ($mensaje)
        <div class="aviso aviso-info" role="status">{{ $mensaje }}</div>
    @endif

    <div class="pestanas" role="tablist" aria-label="Divisiones del periodo">
        @foreach ($tablero->pestanas() as $p)
            <button role="tab" aria-selected="{{ $pestana === (string) $p->id ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $p->id }}')">
                {{ $p->nombre }} <span class="suave" @if ($pestana === (string) $p->id) style="color:inherit" @endif>({{ $p->porcentaje() }} %)</span>
            </button>
        @endforeach
        <button role="tab" aria-selected="{{ $pestana === 'consolidado' ? 'true' : 'false' }}" wire:click="$set('pestana', 'consolidado')">Consolidado</button>
    </div>

    @if ($tablero->estudiantes()->isEmpty())
        <div class="tarjeta vacio">
            <strong>Este grupo aún no tiene estudiantes.</strong>
            Cargue la lista con la plantilla oficial de secretaría; después aparecerán aquí sus filas.
            <p><a class="boton" href="{{ route('importacion.create', $grupo) }}">Cargar lista</a></p>
        </div>
    @elseif ($division)
        @php($componentes = $division->componentes)
        <div class="tabla-notas" role="tabpanel">
            <table>
                <caption class="sr-only">Notas de {{ $division->nombre }}</caption>
                <thead>
                    <tr class="componentes">
                        <th rowspan="2" class="estudiante" scope="col">Estudiante</th>
                        @foreach ($componentes as $c)
                            <th colspan="{{ $c->actividades->count() + 1 }}" scope="colgroup">
                                <div class="fila" style="justify-content: space-between">
                                    <span>{{ $c->nombre }} ({{ $c->porcentaje() }} %)</span>
                                    <details class="menu">
                                        <summary aria-label="Agregar actividad a {{ $c->nombre }}">＋</summary>
                                        <div class="contenido">
                                            <label for="nueva-{{ $c->id }}">Nueva actividad en {{ $c->nombre }}</label>
                                            <input id="nueva-{{ $c->id }}" type="text" wire:model="nuevaActividad.{{ $c->id }}" wire:keydown.enter="crearActividad({{ $c->id }})" placeholder="Quiz 1">
                                            <button class="boton boton-chico" wire:click="crearActividad({{ $c->id }})">Crear columna</button>
                                        </div>
                                    </details>
                                </div>
                            </th>
                        @endforeach
                        <th rowspan="2" class="num" scope="col">Definitiva</th>
                        <th rowspan="2" scope="col">Estado</th>
                    </tr>
                    <tr>
                        @foreach ($componentes as $c)
                            @foreach ($c->actividades as $a)
                                <th scope="col" wire:key="th-{{ $a->id }}">
                                    <div class="fila" style="gap:.25rem; flex-wrap: nowrap">
                                        <span>{{ $a->nombre }}</span>
                                        <details class="menu" x-data="{ n: '' }">
                                            <summary aria-label="Opciones de {{ $a->nombre }}">⋯</summary>
                                            <div class="contenido">
                                                <div>
                                                    <label for="nombre-{{ $a->id }}">Nombre</label>
                                                    <input id="nombre-{{ $a->id }}" type="text" value="{{ $a->nombre }}" x-on:change="$wire.renombrar({{ $a->id }}, $event.target.value)">
                                                </div>
                                                <div class="fila">
                                                    <button class="boton boton-sec boton-chico" wire:click="mover({{ $a->id }}, -1)">← Mover</button>
                                                    <button class="boton boton-sec boton-chico" wire:click="mover({{ $a->id }}, 1)">Mover →</button>
                                                </div>
                                                @if ($a->artefacto_id)<a class="boton boton-sec boton-chico" href="{{ route('calificador.show', $a) }}">Abrir calificador</a>@endif
                                                <button class="boton boton-sec boton-chico" wire:click="vaciasACeros({{ $a->id }})">Convertir vacías en cero</button>
                                                @php($conNota = $a->notas()->count())
                                                <div>
                                                    <label for="borrar-{{ $a->id }}">Eliminar: escriba {{ $conNota }} (notas que se borran)</label>
                                                    <div class="fila" style="flex-wrap:nowrap">
                                                        <input id="borrar-{{ $a->id }}" type="number" x-model="n" style="width:6rem">
                                                        <button class="boton boton-peligro boton-chico" x-on:click="$wire.eliminarActividad({{ $a->id }}, Number(n))">Eliminar</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                </th>
                            @endforeach
                            <th scope="col" class="num" title="Promedio de {{ $c->nombre }}">Prom.</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($filas as $fila)
                        @php($e = $fila['estudiante'])
                        @php($r = $fila['resultado'])
                        <tr wire:key="fila-{{ $e->id }}">
                            <td class="estudiante" title="Documento {{ $e->documento }}">
                                <a href="{{ route('estudiantes.edit', [$grupo, $e]) }}" class="nombre-estudiante">{{ $e->nombreCompleto() }}</a>
                                @if ($e->revisar_nombre)<a href="{{ route('estudiantes.edit', [$grupo, $e]) }}" class="suave" title="El nombre no traía coma en la plantilla: sepárelo">⚠ revisar nombre</a>@endif
                                <a href="{{ route('segundas.create', [$grupo, $e]) }}" class="enlace-segunda" title="Supletorio, recuperación o habilitación">2ª oportunidad</a>
                            </td>
                            @foreach ($componentes as $c)
                                @foreach ($c->actividades as $a)
                                    @php($clave = $a->id.'-'.$e->id)
                                    <td class="celda {{ isset($errores[$clave]) ? 'con-error' : '' }}" wire:key="c-{{ $clave }}">
                                        <input type="text" inputmode="decimal"
                                               value="{{ $escala->editable($tablero->nota($a->id, $e->id)) }}"
                                               aria-label="Nota de {{ $e->nombreCompleto() }} en {{ $a->nombre }}"
                                               x-on:change="$wire.guardar({{ $a->id }}, {{ $e->id }}, $event.target.value)"
                                               x-on:dblclick="$wire.verHistorial({{ $a->id }}, {{ $e->id }})"
                                               title="Doble clic: historial de la nota">
                                        @isset($errores[$clave])<span class="celda-error" role="alert">{{ $errores[$clave] }}</span>@endisset
                                        @if ($so = $tablero->segunda($a->getMorphClass(), $a->id, $e->id))
                                            <a class="marca-segunda" href="{{ route('segundas.create', [$grupo, $e]) }}"
                                               title="{{ \App\Models\SegundaOportunidad::TIPOS[$so->tipo] }} del {{ $so->fecha->format('Y-m-d') }}. La nota original ({{ $tablero->nota($a->id, $e->id) === null ? 'vacía' : $escala->mostrar($tablero->nota($a->id, $e->id)) }}) se conserva.">{{ $so->letra() }} {{ $escala->mostrar($tablero->notaEfectiva($a->id, $e->id)) }}</a>
                                        @endif
                                    </td>
                                @endforeach
                                @php($prom = $r->promedios[$c->id] ?? null)
                                <td class="num">{{ $prom === null ? '—' : $escala->mostrar($prom) }}</td>
                            @endforeach
                            <td class="num definitiva">{{ $r->definitiva === null ? '—' : $escala->mostrar($r->definitiva) }}
                                @if ($r->segunda)<span class="marca-segunda" title="Habilitación del {{ $r->segunda->fecha->format('Y-m-d') }}. Definitiva original: {{ $escala->mostrar($r->original) }}">H · antes {{ $escala->mostrar($r->original) }}</span>@endif</td>
                            <td>
                                <span class="estado estado-{{ $r->estado->value }}">{{ $r->estado->etiqueta() }}</span>
                                @if ($r->definitiva !== null && $escala->apruebaSoloPorRedondeo($r->definitiva))
                                    <span class="redondeo" title="Se muestra {{ $escala->mostrar($r->definitiva) }} por redondeo, pero la nota interna es {{ $escala->editable($r->definitiva) }}">⚠ {{ $escala->editable($r->definitiva) }} sin redondear</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($componentes->every(fn ($c) => $c->actividades->isEmpty()))
            <div class="tarjeta vacio" style="margin-top:1rem">
                <strong>{{ $division->nombre }} aún no tiene actividades.</strong>
                Use el botón ＋ junto a cada componente para crear una columna (por ejemplo «Quiz 1» o «Parcial»).
            </div>
        @endif
    @else
        <div class="tabla-notas" role="tabpanel">
            <table>
                <caption class="sr-only">Consolidado del periodo</caption>
                <thead>
                    <tr>
                        <th class="estudiante" scope="col">Estudiante</th>
                        @foreach ($tablero->pestanas() as $p)
                            <th class="num" scope="col">{{ $p->nombre }}</th>
                            <th class="num" scope="col">Aporte ({{ $p->porcentaje() }} %)</th>
                        @endforeach
                        <th class="num" scope="col">Acumulado</th>
                        <th scope="col">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($filas as $fila)
                        <tr wire:key="cons-{{ $fila['estudiante']->id }}">
                            <td class="estudiante" title="Documento {{ $fila['estudiante']->documento }}">{{ $fila['estudiante']->nombreCompleto() }}</td>
                            @foreach ($tablero->pestanas() as $p)
                                @php($rd = $fila['divisiones'][$p->id])
                                <td class="num">{{ $rd->definitiva === null ? '—' : $escala->mostrar($rd->definitiva) }}
                                    @if ($rd->estado->value === 'incompleto')<span class="suave" title="Incompleto">…</span>@endif
                                    @if ($rd->segunda)<span class="marca-segunda" title="Habilitación; definitiva original {{ $escala->mostrar($rd->original) }}">H</span>@endif</td>
                                <td class="num">{{ $fila['aportes'][$p->id] === null ? '—' : $escala->mostrar($fila['aportes'][$p->id]) }}</td>
                            @endforeach
                            <td class="num definitiva">{{ $escala->mostrar($fila['acumulado']) }}
                                @if ($fila['segunda'])<span class="marca-segunda" title="Habilitación de la materia; nota original {{ $escala->mostrar($fila['original']) }}">H · antes {{ $escala->mostrar($fila['original']) }}</span>@endif</td>
                            <td>
                                <span class="estado estado-{{ $fila['estado']->value }}">{{ $fila['estado'] === \App\Notas\EstadoCorte::Incompleto ? '… En curso' : $fila['estado']->etiqueta() }}</span>
                                @if ($fila['estado'] !== \App\Notas\EstadoCorte::Incompleto && $escala->apruebaSoloPorRedondeo($fila['acumulado']))
                                    <span class="redondeo" title="Nota interna {{ $escala->editable($fila['acumulado']) }}">⚠ {{ $escala->editable($fila['acumulado']) }} sin redondear</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="suave">El acumulado suma los aportes de las divisiones con notas. «En curso» indica que falta alguna división o componente.</p>
    @endif

    @if ($retirados->isNotEmpty())
        <details style="margin-top:1rem">
            <summary>Retirados del grupo ({{ $retirados->count() }}): no aparecen en la planilla, sus notas se conservan</summary>
            <ul>@foreach ($retirados as $r)<li><a href="{{ route('estudiantes.edit', [$grupo, $r]) }}">{{ $r->nombreCompleto() }}</a> — {{ $r->documento }}</li>@endforeach</ul>
        </details>
    @endif

    @if ($historial)
        <div class="modal-fondo" wire:click.self="cerrarHistorial" x-on:keydown.escape.window="$wire.cerrarHistorial()">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-historial">
                <h2 id="titulo-historial" style="margin-top:0">Historial: {{ $historial['titulo'] }}</h2>
                @if ($historial['cambios'])
                    <table>
                        <thead><tr><th>Fecha</th><th class="num">Antes</th><th class="num">Después</th><th>Origen</th><th>Motivo</th></tr></thead>
                        <tbody>
                            @foreach ($historial['cambios'] as $c)
                                <tr><td>{{ $c['fecha'] }}</td><td class="num">{{ $c['anterior'] }}</td><td class="num">{{ $c['nuevo'] }}</td><td>{{ $c['origen'] }}</td><td>{{ $c['motivo'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p>Esta nota no tiene cambios registrados.</p>
                @endif
                <p><button class="boton" wire:click="cerrarHistorial" autofocus>Cerrar</button></p>
            </div>
        </div>
    @endif
</div>
