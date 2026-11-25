<x-app titulo="Revisar el prompt">
    <div class="migas"><a href="{{ $volver ?? route('generacion.create', $materia) }}">{{ $migas ?? 'Generar con IA' }} — {{ $materia->nombre }}</a> › Prompt</div>
    <h1>Revise el prompt antes de enviarlo</h1>
    <p class="suave">Armado con sus tres capas y el material seleccionado. Puede editarlo; lo que envíe queda registrado.
        Motor: <strong>{{ $motor?->nombre ?? 'el predeterminado' }}</strong>@if ($motor && ! $motor->es_local) — <strong>en la nube</strong>: el material sale de la institución @endif.</p>
    @if ($prompt['recortado'])<div class="aviso aviso-info">El material seleccionado supera lo que cabe en un prompt ({{ number_format(config('ia.max_contexto_caracteres'), 0, ',', '.') }} caracteres) y se recortó. Seleccione menos archivos si importa el final.</div>@endif

    <form method="POST" action="{{ $accion ?? route('generacion.generar', $materia) }}" x-data="{ enviando: false }" x-on:submit="enviando = true">
        @csrf
        <input type="hidden" name="motor_id" value="{{ $motorId }}">
        <div class="campo"><label for="sistema">Contexto (capas 1 y 2)</label>
            <textarea id="sistema" name="sistema" rows="14" style="font-size:.85rem">{{ $prompt['sistema'] }}</textarea></div>
        <div class="campo"><label for="usuario">Tarea y material (capa 3)</label>
            <textarea id="usuario" name="usuario" rows="22" style="font-size:.85rem">{{ $prompt['usuario'] }}</textarea></div>
        <button class="boton" type="submit" x-bind:disabled="enviando" x-text="enviando ? 'Generando… con un modelo local puede tardar varios minutos' : 'Generar borrador'">Generar borrador</button>
    </form>
</x-app>
