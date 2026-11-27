<x-app titulo="Generadores de documentos">
    @php($G = \App\Material\Generadores::class)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › Generadores de documentos</div>
    <div class="fila" style="justify-content:space-between">
        <h1>Generadores de documentos</h1>
        <a class="boton" href="{{ route('generadores.create') }}">Nuevo generador</a>
    </div>
    <p class="suave">Un generador es una receta: qué le pide a la IA, con qué campos y si el documento lleva código. Se usa en
        «Guías, manuales y otros documentos» de cualquiera de sus materias. No hace falta saber programar: las instrucciones se escriben como se le explicaría a un monitor.</p>

    @foreach ($generadores as $g)
        <section class="tarjeta">
            <div class="fila" style="justify-content:space-between;align-items:flex-start">
                <div>
                    <h2 style="margin:0">{{ $g['nombre'] }}
                        @if ($g['fabrica'])<span class="estado estado-sin_actividades">De fábrica</span>@endif
                        @if ($g['funciono'])<span class="estado estado-aprobado">✓ Funcionó</span>@endif</h2>
                    <p style="margin:.3rem 0">{{ $g['descripcion'] }}</p>
                    <p class="suave" style="margin:0">{{ $G::CODIGO[$g['codigo']] }}@if ($g['campos']) · campos: {{ collect($g['campos'])->pluck('etiqueta')->join(', ') }}@endif</p>
                    @if (! $g['fabrica'] && $g['modelo']->nota_funciono)<p class="suave" style="margin:.2rem 0 0"><em>Nota:</em> {{ $g['modelo']->nota_funciono }}</p>@endif
                </div>
                <div class="fila">
                    @if ($materias->isNotEmpty())
                        <form method="GET" action="{{ route('documentos.index', $materias->first()) }}" class="fila" style="gap:.3rem" x-data
                              x-on:submit.prevent="location = @js(url('materias')) + '/' + $el.materia.value + '/documentos?generador=' + encodeURIComponent(@js($g['clave'])) + '#generar'">
                            <select name="materia" aria-label="Materia donde se usa «{{ $g['nombre'] }}»" class="boton-chico" style="width:auto">@foreach ($materias as $m)<option value="{{ $m->id }}">{{ $m->nombre }}</option>@endforeach</select>
                            <button class="boton boton-sec boton-chico">Usar</button>
                        </form>
                    @endif
                    <a class="boton boton-sec boton-chico" href="{{ route('generadores.create', ['desde' => $g['clave']]) }}">Copiar para ajustar</a>
                    @unless ($g['fabrica'])<a class="boton boton-sec boton-chico" href="{{ route('generadores.edit', $g['modelo']) }}">Editar</a>@endunless
                </div>
            </div>
            @unless ($g['fabrica'])
                <form method="POST" action="{{ route('generadores.funciono', $g['modelo']) }}" class="fila" style="margin-top:.5rem;gap:.4rem">
                    @csrf
                    @if ($g['funciono'])
                        <input type="hidden" name="funciono" value="0"><button class="enlace">Quitar de la biblioteca</button>
                    @else
                        <input type="hidden" name="funciono" value="1">
                        <input type="text" name="nota_funciono" maxlength="300" placeholder="Qué funcionó (opcional)" aria-label="Qué funcionó de «{{ $g['nombre'] }}»" style="max-width:24rem">
                        <button class="boton boton-sec boton-chico">Marcar que funcionó</button>
                    @endif
                </form>
            @endunless
        </section>
    @endforeach
    <p class="suave">Las plantillas de prompt que funcionaron están en la <a href="{{ route('ia.biblioteca') }}">biblioteca de plantillas</a>.</p>
</x-app>
