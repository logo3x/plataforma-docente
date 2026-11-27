<x-app titulo="Biblioteca de lo que funcionó">
    @php($CLAVES = \App\Http\Controllers\PlantillasIaController::CLAVES)
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › Biblioteca de lo que funcionó</div>
    <h1>Biblioteca de lo que funcionó</h1>
    <p class="suave">Las plantillas de prompt y los generadores que usted marcó porque dieron buen resultado. Valen para todas sus materias:
        vuelva a una de estas versiones cuando una nueva no salga tan bien. Se marcan desde las <a href="{{ route('ia.plantillas') }}">plantillas</a>,
        desde los <a href="{{ route('generadores.index') }}">generadores</a> o con «Esto funcionó» en un material generado con IA.
        El estilo de una materia se copia a otra desde su página «Estilo para la IA».</p>

    <h2>Plantillas de prompt</h2>
    @forelse ($plantillas->groupBy('clave') as $clave => $versiones)
        <section class="tarjeta">
            <h3 style="margin-top:0">{{ $CLAVES[$clave] ?? $clave }}</h3>
            @foreach ($versiones as $v)
                <details class="tarjeta" style="box-shadow:none;margin:.4rem 0">
                    <summary>Versión {{ $v->version }} · {{ $v->created_at->format('Y-m-d') }}
                        @if ($vigentes[$clave] === $v->id)<span class="estado estado-aprobado">en uso</span>@endif
                        @if ($v->nota_funciono)<span class="suave"> · {{ $v->nota_funciono }}</span>@endif</summary>
                    <pre style="white-space:pre-wrap;font-size:.8rem;max-height:18rem;overflow:auto">{{ $v->cuerpo }}</pre>
                    <div class="fila">
                        @if ($vigentes[$clave] !== $v->id)
                            <form method="POST" action="{{ route('ia.plantillas.volver') }}">@csrf <input type="hidden" name="version_id" value="{{ $v->id }}"><button class="boton boton-sec boton-chico">Usar esta versión</button></form>
                        @endif
                        @include('ia._funciono', ['plantilla' => $v])
                    </div>
                </details>
            @endforeach
        </section>
    @empty
        <div class="tarjeta vacio">Aún no ha marcado ninguna plantilla. Cuando una generación salga bien, pulse «Esto funcionó» en el material.</div>
    @endforelse

    <h2>Generadores</h2>
    @forelse ($generadores as $g)
        <p><a href="{{ route('generadores.edit', $g) }}">{{ $g->nombre }}</a>@if ($g->nota_funciono) <span class="suave">· {{ $g->nota_funciono }}</span>@endif</p>
    @empty
        <p class="suave">Ningún generador propio marcado todavía.</p>
    @endforelse
</x-app>
