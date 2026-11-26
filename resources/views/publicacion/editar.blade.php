@php($formatos = \App\Material\Publicador::FORMATOS)
<x-app :titulo="'Publicar — '.$m->titulo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › {{ $m->materia->nombre }} › {{ $m->titulo }} › Publicar</div>
    <h1>Publicar «{{ $m->titulo }}»</h1>
    <p class="suave">Se publica la versión para estudiantes, sin respuestas ni soluciones, con su ficha de metadatos.
        Elija el formato según dónde lo vaya a poner: la plataforma institucional (SCORM), una página o un correo (HTML), o para seguir editándolo (Word).</p>
    @if ($errors->any())<div class="aviso aviso-error" role="alert">{{ implode(' ', $errors->all()) }}</div>@endif

    <form method="POST" action="{{ route('publicacion.store', $m) }}">@csrf
        <section class="tarjeta">
            <h2 style="margin-top:0">Ficha del recurso</h2>
            <p class="suave">Basada en Dublin Core y LOM. Si la institución tiene una plantilla de ficha, se ajusta en <code>config/publicacion.php</code>.</p>
            <div class="rejilla">
                @foreach ($campos as $k => $c)
                    <div @if ($c['tipo'] === 'area') style="grid-column:1/-1" @endif>
                        <label for="f-{{ $k }}">{{ $c['etiqueta'] }}@if ($c['obligatorio'] ?? false) <span aria-hidden="true">*</span>@endif</label>
                        @if ($c['tipo'] === 'area')
                            <textarea id="f-{{ $k }}" name="ficha[{{ $k }}]" rows="3" @required($c['obligatorio'] ?? false)>{{ old("ficha.$k", $ficha[$k] ?? '') }}</textarea>
                        @elseif ($c['tipo'] === 'lista')
                            <select id="f-{{ $k }}" name="ficha[{{ $k }}]">@foreach ($c['opciones'] as $o)<option @selected(old("ficha.$k", $ficha[$k] ?? '') === $o)>{{ $o }}</option>@endforeach</select>
                        @else
                            <input id="f-{{ $k }}" name="ficha[{{ $k }}]" type="text" value="{{ old("ficha.$k", $ficha[$k] ?? '') }}" @required($c['obligatorio'] ?? false)>
                        @endif
                        @error($k)<div class="error" role="alert">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </section>
        <div class="fila">
            @foreach ($formatos as $k => $t)
                <button @class(['boton', 'boton-sec' => $k !== 'scorm']) type="submit" name="formato" value="{{ $k }}">Descargar {{ mb_strtolower($t) }}</button>
            @endforeach
        </div>
        <p class="suave">El PDF sale de «Imprimir o guardar en PDF» en la versión para estudiantes.</p>
    </form>

    @if ($publicaciones->isNotEmpty())
        <h2>Publicaciones anteriores</h2>
        <ul>@foreach ($publicaciones as $p)<li>Versión {{ $p->version }} · {{ $formatos[$p->formato] }} · {{ $p->created_at->format('d/m/Y H:i') }}@if (filled($p->ficha['version'] ?? null)) · ficha v{{ $p->ficha['version'] }}@endif</li>@endforeach</ul>
    @endif
</x-app>
