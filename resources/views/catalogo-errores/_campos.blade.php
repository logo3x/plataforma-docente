<label for="patron-{{ $sufijo }}">Mensaje del motor</label>
<input id="patron-{{ $sufijo }}" name="patron" type="text" maxlength="300" required value="{{ old('patron', $e?->patron ?? ($patron ?? '')) }}" placeholder="{x} is not defined">
<label style="font-weight:normal"><input type="hidden" name="es_regex" value="0"><input type="checkbox" name="es_regex" value="1" @checked(old('es_regex', $e?->es_regex))> Es una expresión regular (el primer grupo reemplaza a {x})</label>
<label for="explicacion-{{ $sufijo }}">Explicación para el estudiante</label>
<textarea id="explicacion-{{ $sufijo }}" name="explicacion" rows="2" maxlength="500" required>{{ old('explicacion', $e?->explicacion) }}</textarea>
<label for="revision-{{ $sufijo }}">Qué revisar</label>
<textarea id="revision-{{ $sufijo }}" name="revision" rows="2" maxlength="500">{{ old('revision', $e?->revision) }}</textarea>
<label for="materia-{{ $sufijo }}">Ámbito</label>
<select id="materia-{{ $sufijo }}" name="materia_id"><option value="">Todas mis materias</option>
    @foreach ($materias as $m)<option value="{{ $m->id }}" @selected((string) old('materia_id', $e?->materia_id) === (string) $m->id)>Solo {{ $m->nombre }}</option>@endforeach</select>
