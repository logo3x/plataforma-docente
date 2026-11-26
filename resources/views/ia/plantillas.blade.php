<x-app titulo="Plantillas de IA">
    <h1>Plantillas de IA</h1>
    <p class="suave">Todo prompt se arma con tres capas: <strong>contexto general</strong> (usted y su institución),
        <strong>estilo de la materia</strong> y <strong>la tarea</strong>. Los campos <code>&#123;&#123;así&#125;&#125;</code> se llenan solos. Cada cambio crea una versión nueva.</p>

    <form method="POST" action="{{ route('ia.plantillas.perfil') }}" class="tarjeta">
        @csrf
        <h2 style="margin-top:0">Su perfil (capa 1)</h2>
        <div class="rejilla">
            @foreach (\App\Http\Controllers\PlantillasIaController::CAMPOS_PERFIL as $campo => $etiqueta)
                <div class="campo"><label for="pf-{{ $campo }}">{{ $etiqueta }}</label><input id="pf-{{ $campo }}" name="{{ $campo }}" type="text" value="{{ $perfil[$campo] ?? '' }}"></div>
            @endforeach
        </div>
        <button class="boton" type="submit">Guardar perfil</button>
    </form>

    @foreach (\App\Http\Controllers\PlantillasIaController::CLAVES as $clave => $titulo)
        <section class="tarjeta">
            <h2 style="margin-top:0">{{ $titulo }} <span class="suave">· {{ $plantillas[$clave]['version'] ? 'versión '.$plantillas[$clave]['version'] : 'de fábrica' }}</span></h2>
            <form method="POST" action="{{ route('ia.plantillas.guardar') }}">
                @csrf <input type="hidden" name="clave" value="{{ $clave }}">
                <textarea name="cuerpo" rows="{{ in_array($clave, ['banco', 'rubrica', 'unidad', 'ejemplos', 'debate', 'vocabulario', 'proyectos']) ? 24 : 11 }}" aria-label="{{ $titulo }}" style="font-family:ui-monospace,Consolas,monospace;font-size:.85rem">{{ $plantillas[$clave]['cuerpo'] }}</textarea>
                <div class="fila" style="margin-top:.5rem"><button class="boton boton-sec" type="submit">Guardar versión nueva</button></div>
            </form>
            <form method="POST" action="{{ route('ia.plantillas.restaurar') }}" style="margin-top:.5rem">@csrf <input type="hidden" name="clave" value="{{ $clave }}"><button class="enlace">Restaurar la de fábrica</button></form>
        </section>
    @endforeach
</x-app>
