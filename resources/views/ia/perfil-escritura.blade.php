<x-app titulo="Perfil de escritura">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › Perfil de escritura</div>
    <h1>Perfil de escritura</h1>
    <p class="suave">Para que los comentarios que la IA propone a sus estudiantes suenen como usted. Se toman de comentarios que usted mismo escribió:
        en respuestas abiertas, en notas, en trabajos calificados y sus comentarios frecuentes. No entran los que solo aceptaron lo que propuso la IA
        ni los que mencionan cifras o puntajes. La IA imita la manera de escribir, no el contenido, y lo que propone sigue siendo un borrador que usted revisa.</p>

    <form method="POST" action="{{ route('ia.perfil-escritura.guardar') }}">
        @csrf @method('PUT')
        <div class="tarjeta">
            <label style="font-weight:400"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" @checked($activo)>
                <strong>Usar mi perfil de escritura</strong> en los comentarios para los estudiantes</label>
            <p class="suave" style="margin:.3rem 0 0">Desactivado, los comentarios se proponen en un registro neutro de aula.</p>
        </div>

        <h2>Muestras</h2>
        @forelse ($candidatas as $c)
            @if ($loop->first)<p class="suave">Van al prompt las primeras {{ $enUso }} marcadas. Desmarque las que no lo representan.</p>@endif
            <label class="tarjeta" style="display:flex;gap:.6rem;align-items:flex-start;font-weight:400;box-shadow:none;margin:.3rem 0">
                <input type="checkbox" name="usar[]" value="{{ $c['huella'] }}" @checked(! $c['excluida']) style="margin-top:.3rem">
                <span><span class="texto-estudiante" style="display:block;background:none;padding:0">{{ $c['texto'] }}</span><span class="suave">{{ $c['origen'] }}</span></span>
            </label>
        @empty
            <div class="tarjeta vacio">Todavía no hay comentarios suyos para tomar como muestra. Aparecerán cuando comente respuestas abiertas, notas o trabajos.</div>
        @endforelse
        <p><button class="boton" type="submit">Guardar</button></p>
    </form>

    @if ($bloque)
        <details class="tarjeta"><summary>Lo que recibe la IA</summary><pre style="white-space:pre-wrap;font-size:.85rem">{{ $bloque }}</pre></details>
    @endif
</x-app>
