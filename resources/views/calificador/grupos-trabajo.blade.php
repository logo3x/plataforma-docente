<x-app :titulo="'Grupos de trabajo — '.$actividad->nombre">
    @php($GT = \App\Calificacion\GruposTrabajo::class)
    @php($dictado = $actividad->componente->division->dictado)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> ›
        <a href="{{ $actividad->artefacto ? route('calificador.show', $actividad) : route('calificador.archivos', $actividad) }}">{{ $actividad->nombre }}</a> › Grupos de trabajo</div>
    <h1>Grupos de trabajo</h1>
    <p class="suave">{{ $actividad->nombre }} · {{ $dictado->materia->nombre }} {{ $dictado->periodo }}
        · {{ $actividad->grupos->pluck('nombre')->join(', ') ?: 'todos los grupos' }}
        @if ($actividad->tamano_grupo) · grupos previstos de {{ $actividad->tamano_grupo }}@endif</p>

    @unless ($actividad->es_grupal)
        <div class="aviso aviso-info" role="note">Esta actividad no está marcada como grupal: lo que defina aquí se aplica cuando la marque.</div>
    @endunless
    @if ($conEntregas)
        <div class="aviso aviso-info" role="note">Ya hay entregas. Los cambios valen para las que lleguen desde ahora; las recibidas conservan sus integrantes.</div>
    @endif
    @if ($errors->any())<div class="aviso aviso-error" role="alert"><ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <div x-data="{ via: @js($forzado ? $modo : 'auto'), defecto: @js($porDefecto), get docente() { return this.via === 'docente' || (this.via === 'auto' && this.defecto === 'docente'); } }">
    <form method="POST" action="{{ route('calificador.grupos.guardar', $actividad) }}">
        @csrf @method('PUT')
        <fieldset class="tarjeta">
            <legend>¿Quién arma los grupos?</legend>
            <label style="font-weight:400;display:block"><input type="radio" name="grupos_por" value="auto" x-model="via">
                Según el modo de la actividad: {{ $porDefecto === 'docente' ? 'sin conexión, en sala → los define el docente' : 'con conexión, para la casa → los escoge el estudiante' }}</label>
            @foreach ($GT::MODOS as $k => $v)
                <label style="font-weight:400;display:block"><input type="radio" name="grupos_por" value="{{ $k }}" x-model="via"> {{ $v }}</label>
            @endforeach
            <p class="suave" style="margin:.4rem 0 0" x-show="! docente">Al iniciar, quien entrega escoge a sus compañeros de la lista del curso por nombre.
                No teclea documentos y el examen no reparte los documentos del curso.</p>
        </fieldset>

        <section class="tarjeta" x-show="docente">
            <h2 style="margin-top:0">Grupos</h2>
            @if ($propuesta)<div class="aviso aviso-info" role="status">Esta es una propuesta: aún no está guardada.</div>
            @elseif (! $asignacion)<div class="aviso aviso-info" role="note">Aún no ha armado los grupos. Mientras tanto, el examen deja que cada estudiante escoja a sus compañeros.</div>@endif
            <p class="suave">Escriba el número de grupo de cada estudiante. Quien quede sin número no está en ningún grupo: si entrega, la entrega queda solo a su nombre y se marca.</p>
            @php($porGrupo = collect($asignacion)->groupBy(fn ($n) => $n, true)->sortKeys())
            @if ($porGrupo->isNotEmpty())
                <ul class="lista-grupos">
                    @foreach ($porGrupo as $n => $ids)
                        <li><strong>Grupo {{ $n }}</strong> ({{ count($ids) }}): {{ $candidatos->whereIn('id', array_keys($ids->all()))->map->nombreCompleto()->join(' · ') }}
                            @if ($actividad->tamano_grupo && count($ids) > $actividad->tamano_grupo)<span class="error"> · más de {{ $actividad->tamano_grupo }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif
            <table>
                <thead><tr><th>Estudiante</th><th>Grupo del curso</th><th class="num">Grupo de trabajo</th></tr></thead>
                <tbody>
                    @foreach ($candidatos as $e)
                        <tr>
                            <td>{{ $e->nombreCompleto() }}</td>
                            <td>{{ $e->grupos->pluck('nombre')->join(', ') }}</td>
                            <td class="num"><input type="number" min="0" max="200" name="numero[{{ $e->id }}]" value="{{ $asignacion[$e->id] ?? '' }}"
                                       aria-label="Grupo de trabajo de {{ $e->nombreCompleto() }}" style="width:6rem" placeholder="—"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <button class="boton" type="submit">Guardar</button>
    </form>

    <form method="POST" action="{{ route('calificador.grupos.repartir', $actividad) }}" class="tarjeta" style="margin-top:1rem" x-show="docente">
        @csrf <input type="hidden" name="grupos_por" value="docente">
        <h2 style="margin-top:0">Armar al azar</h2>
        <p class="suave">Reparte al azar a quienes aún no tienen grupo, sin mover a los que ya tienen. Muestra la propuesta y no guarda hasta que usted pulse «Guardar».</p>
        <div class="fila">
            <div><label for="tamano">Integrantes por grupo</label><input id="tamano" name="tamano" type="number" min="2" max="10" value="{{ $actividad->tamano_grupo ?? 3 }}" style="width:6rem"></div>
            <button class="boton boton-sec" type="submit" style="align-self:flex-end">Proponer grupos</button>
        </div>
    </form>
    </div>
</x-app>
