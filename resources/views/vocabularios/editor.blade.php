<x-app :titulo="($generacion ? 'Borrador de vocabulario' : ($existente ? 'Editar vocabulario' : 'Nueva lista de vocabulario')).' — '.$materia->nombre">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › <a href="{{ route('vocabularios.index', $materia) }}">Vocabulario — {{ $materia->nombre }}</a> ›
        {{ $generacion ? 'Borrador de la IA' : ($existente ? 'Editar' : 'Nueva') }}</div>
    <h1>{{ $generacion ? 'Borrador de vocabulario generado con IA' : ($existente ? 'Editar vocabulario' : 'Nueva lista de vocabulario') }}
        @if ($generacion)<span class="suave">· {{ $generacion->motor->nombre }} · {{ $generacion->created_at->format('Y-m-d H:i') }}</span>@endif</h1>
    @if (session('error_ia'))<div class="aviso aviso-error" role="alert">{{ session('error_ia') }}</div>@endif
    @if ($generacion && $generacion->estado !== 'borrador')<div class="aviso aviso-info">Este borrador ya fue {{ $generacion->estado }}.</div>@endif

    @if ($errores)
        <div class="aviso aviso-error" role="alert"><strong>{{ count($errores) }} observación(es) impiden guardar:</strong>
            <ul style="margin:.25rem 0 0">@foreach ($errores as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @elseif ($generacion)
        <div class="aviso aviso-ok" role="status">✓ El formato es válido. Revise cada definición: debe coincidir con como usted lo explica en clase.</div>
    @endif
    @if ($advertencias)
        <div class="aviso aviso-info"><strong>Para revisar (no impide guardar):</strong>
            <ul style="margin:.25rem 0 0">@foreach ($advertencias as $a)<li>{{ $a }}</li>@endforeach</ul></div>
    @endif

    <script>
        window.editorVocabulario = function (inicial) {
            return {
                d: Object.assign({titulo: '', proposito: '', terminos: []}, inicial),
                agregar() { this.d.terminos.push({termino: '', tema: this.d.terminos.at(-1)?.tema || '', definicion: '', ejemplo: '', ingles: '', relacionados: []}); },
                ordenar() { this.d.terminos.sort((a, b) => a.termino.localeCompare(b.termino, 'es', {sensitivity: 'base'})); },
                json() { return JSON.stringify(this.d); },
            };
        };
    </script>

    <div x-data="editorVocabulario(@js($contenido))">
        <form method="POST" action="{{ $accion }}">
            @csrf @if ($existente || $generacion) @method('PUT') @endif
            <input type="hidden" name="contenido" x-bind:value="json()">
            <div class="tarjeta">
                <div class="rejilla">
                    <div class="campo"><label for="titulo">Título</label><input id="titulo" type="text" x-model="d.titulo" required maxlength="255" placeholder="Vocabulario de bases de datos"></div>
                    <div class="campo"><label for="proposito">Para qué le sirve al estudiante</label><input id="proposito" type="text" x-model="d.proposito"></div>
                </div>
                <div class="fila">
                    <span class="suave" role="status" x-text="d.terminos.filter(t => t.termino.trim()).length + ' término(s)'"></span>
                    <button type="button" class="boton boton-sec boton-chico" x-on:click="ordenar()">Ordenar alfabéticamente</button>
                </div>
            </div>
            <datalist id="temas-temario">@foreach ($temas as $t)<option value="{{ $t }}">@endforeach</datalist>
            <div class="tarjeta" style="overflow-x:auto">
                <table>
                    <thead><tr><th style="width:13rem">Término</th><th>Definición</th><th>Ejemplo de uso</th><th style="width:9rem">Inglés</th><th style="width:10rem">Tema</th><th style="width:4rem"></th></tr></thead>
                    <tbody>
                        <template x-for="(t, i) in d.terminos" :key="i">
                            <tr>
                                <td><input type="text" x-model="t.termino" x-bind:aria-label="'Término ' + (i + 1)"></td>
                                <td><textarea rows="2" x-model="t.definicion" x-bind:aria-label="'Definición del término ' + (i + 1)" style="font-family:inherit"></textarea></td>
                                <td><textarea rows="2" x-model="t.ejemplo" x-bind:aria-label="'Ejemplo del término ' + (i + 1)" style="font-family:inherit"></textarea></td>
                                <td><input type="text" x-model="t.ingles" x-bind:aria-label="'Término en inglés ' + (i + 1)" lang="en"></td>
                                <td><input type="text" list="temas-temario" x-model="t.tema" x-bind:aria-label="'Tema del término ' + (i + 1)"></td>
                                <td><button type="button" class="enlace" x-on:click="d.terminos.splice(i, 1)" x-bind:aria-label="'Quitar el término ' + (i + 1)">Quitar</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <button type="button" class="boton boton-sec boton-chico" x-on:click="agregar()" style="margin-top:.5rem">Agregar término</button>
            </div>

            @if (! $generacion || $generacion->estado === 'borrador')
                <div class="fila">
                    @if ($generacion)
                        <button class="boton" type="submit" name="aprobar" value="1">Guardar lista</button>
                        <button class="boton boton-sec" type="submit">Guardar borrador y validar</button>
                    @else
                        <button class="boton" type="submit">Guardar lista</button>
                    @endif
                    <a href="{{ $existente ? route('vocabularios.show', $existente) : route('vocabularios.index', $materia) }}">Cancelar</a>
                </div>
            @endif
        </form>

        @if ($generacion && $generacion->estado === 'borrador')
            <div class="fila" style="margin-top:1rem">
                @if ($errores)<form method="POST" action="{{ route('vocabularios.corregir', $generacion) }}">@csrf<button class="boton boton-sec">Pedir a la IA que la corrija</button></form>@endif
                <form method="POST" action="{{ route('vocabularios.descartar', $generacion) }}">@csrf<button class="enlace">Descartar borrador</button></form>
            </div>
        @endif
        @if ($existente)
            <form method="POST" action="{{ route('vocabularios.destroy', $existente) }}" style="margin-top:2rem" onsubmit="return confirm('¿Eliminar esta lista?')">
                @csrf @method('DELETE')<button class="enlace">Eliminar lista</button>
            </form>
        @endif
    </div>
    @if ($existente instanceof \Illuminate\Database\Eloquent\Model)@include('versiones._lista', ['modelo' => $existente])@endif
</x-app>
