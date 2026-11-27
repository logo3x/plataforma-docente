{{-- 8.4.5: desde lo que salió bien, la plantilla con que se generó va a la biblioteca --}}
@if ($m->generacion && ! $estudiante)
    <form method="POST" action="{{ route('ia.generaciones.funciono', $m->generacion) }}" class="fila no-imprimir" style="justify-content:flex-end;gap:.3rem;margin:.3rem 0">
        @csrf
        <input type="text" name="nota_funciono" maxlength="300" placeholder="Qué funcionó (opcional)" aria-label="Qué funcionó de esta generación" style="max-width:20rem">
        <button class="boton boton-sec boton-chico" title="Guarda en la biblioteca la plantilla de prompt con que se generó">Esto funcionó: guardar la plantilla</button>
    </form>
@endif
