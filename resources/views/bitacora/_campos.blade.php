@php($destinos = \App\Calificacion\BitacoraAula::DESTINOS)
<div class="fila">
    <div><label for="fecha-{{ $sufijo }}">Fecha</label>
        <input id="fecha-{{ $sufijo }}" name="fecha" type="date" required max="{{ now()->toDateString() }}" value="{{ old('fecha', $e?->fecha?->toDateString() ?? now()->toDateString()) }}"></div>
    <div style="flex:1"><label for="materia-{{ $sufijo }}">Materia</label>
        @if ($actividadFija ?? null)
            <input type="hidden" name="actividad_id" value="{{ $actividadFija->id }}">
            <p style="margin:.4rem 0">{{ $actividadFija->componente->division->dictado->materia->nombre }} · {{ $actividadFija->nombre }}</p>
        @else
            @if ($e?->actividad_id)<input type="hidden" name="actividad_id" value="{{ $e->actividad_id }}">@endif
            <select id="materia-{{ $sufijo }}" name="materia_id" @disabled($e?->actividad_id)><option value="">General (sin materia)</option>
                @foreach ($materias as $m)<option value="{{ $m->id }}" @selected((string) old('materia_id', $e?->materia_id) === (string) $m->id)>{{ $m->nombre }}</option>@endforeach</select>
        @endif
    </div>
</div>
<label for="observacion-{{ $sufijo }}">Observación: qué pasó en el aula</label>
<textarea id="observacion-{{ $sufijo }}" name="observacion" rows="3" maxlength="2000" required>{{ old('observacion', $e?->observacion) }}</textarea>
<div x-data="{ destino: @js(old('destino', $e?->destino ?? 'pendiente')) }">
    <fieldset style="border:0;padding:0;margin:.5rem 0 0"><legend>Destino</legend>
        <div class="fila">@foreach ($destinos as $k => $t)<label style="font-weight:normal"><input type="radio" name="destino" value="{{ $k }}" x-model="destino"> {{ $t }}</label>@endforeach</div>
    </fieldset>
    <label for="referencia-{{ $sufijo }}">Referencia <span class="suave">(opcional: «Requerimiento 5.4.9», «Sección 6.7»)</span></label>
    <input id="referencia-{{ $sufijo }}" name="referencia" type="text" maxlength="200" value="{{ old('referencia', $e?->referencia) }}">
    <div x-show="destino === 'leccion'">
        <label for="leccion-{{ $sufijo }}">Lección: qué hacer distinto la próxima vez</label>
        <textarea id="leccion-{{ $sufijo }}" name="leccion" rows="2" maxlength="1000" placeholder="En los retos que imprimen por consola, declarar la salida esperada en cada caso visible.">{{ old('leccion', $e?->leccion) }}</textarea>
        <label style="font-weight:normal"><input type="hidden" name="para_ia" value="0"><input type="checkbox" name="para_ia" value="1" @checked(old('para_ia', $e?->para_ia))>
            Que la IA la tenga en cuenta en lo que genere para esta materia</label>
    </div>
</div>
