<x-app :titulo="'Actividades evaluables — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $materia->nombre }} › Actividades evaluables</div>
    <h1>Actividades evaluables — {{ $materia->nombre }}</h1>
    <p class="suave">Quices, parciales y talleres de la materia. Se guardan en la materia y se pueden aplicar en cualquier periodo.</p>

    <p><a class="boton" href="{{ route('generacion.create', $materia) }}">Generar una actividad con IA</a></p>
    <section class="tarjeta">
        <h2 style="margin-top:0">Importar una actividad</h2>
        <p>Archivo JSON en formato «unipaz-banco» (vea <code>docs/formato-banco.md</code> en el repositorio). Se valida antes de guardar:
            tipos de pregunta, respuestas dentro de las opciones y que los pesos sumen el valor de la actividad.</p>
        <form method="POST" action="{{ route('artefactos.importar', $materia) }}" enctype="multipart/form-data" class="fila">
            @csrf
            <input type="file" name="banco" accept=".json,application/json" required style="max-width:420px" aria-label="Archivo del banco">
            <button class="boton" type="submit">Importar</button>
        </form>
        @error('banco')<div class="aviso aviso-error" role="alert" style="white-space:pre-line;margin-top:.75rem">{{ $message }}</div>@enderror
    </section>

    @forelse ($artefactos as $a)
        <section class="tarjeta">
            <h2 style="margin-top:0">{{ $a->titulo }} <span class="suave">· {{ $a->sigla }} · {{ $a->items_count }} ítems · valor {{ $a->configuracion['valor'] ?? '' }}</span></h2>
            @if ($a->actividades->isNotEmpty())
                <p style="margin:.25rem 0">Aplicada en:</p>
                <ul>
                    @foreach ($a->actividades as $act)
                        <li>{{ $act->componente->division->dictado->periodo }} › {{ $act->componente->division->nombre }} › {{ $act->componente->nombre }}
                            ({{ $act->grupos->pluck('nombre')->join(', ') ?: 'todos los grupos' }})
                            — <a href="{{ route('calificador.show', $act) }}">Calificador</a> · <a href="{{ route('calificador.examen', $act) }}">Descargar examen</a></li>
                    @endforeach
                </ul>
            @endif
            <details>
                <summary>Aplicar en un periodo</summary>
                @foreach ($dictados as $d)
                    <form method="POST" action="{{ route('artefactos.aplicar', $a) }}" class="tarjeta" style="box-shadow:none;margin-top:.5rem">
                        @csrf
                        <strong>{{ $d->periodo }}</strong>
                        <div class="fila" style="margin-top:.5rem">
                            <div><label for="comp-{{ $a->id }}-{{ $d->id }}">Componente</label>
                                <select id="comp-{{ $a->id }}-{{ $d->id }}" name="componente_id">
                                    @foreach ($d->divisiones as $div)@foreach ($div->componentes as $c)
                                        <option value="{{ $c->id }}">{{ $div->nombre }} — {{ $c->nombre }}</option>
                                    @endforeach @endforeach
                                </select></div>
                            <fieldset style="border:0;padding:0"><legend style="font-weight:600">Grupos</legend>
                                @foreach ($d->grupos as $g)<label style="display:inline;font-weight:400;margin-right:.75rem"><input type="checkbox" name="grupos[]" value="{{ $g->id }}" checked> {{ $g->nombre }}</label>@endforeach
                            </fieldset>
                            <label style="font-weight:400"><input type="checkbox" name="es_grupal" value="1"> Entrega grupal</label>
                            <button class="boton boton-sec" type="submit" style="align-self:flex-end">Aplicar</button>
                        </div>
                    </form>
                @endforeach
            </details>
        </section>
    @empty
        <div class="tarjeta vacio"><strong>Aún no hay actividades evaluables en esta materia.</strong>Importe la primera con el formulario de arriba.</div>
    @endforelse
</x-app>
