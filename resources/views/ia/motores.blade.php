<x-app titulo="Motores de IA">
    <h1>Motores de IA</h1>
    <p class="suave">El motor es intercambiable: un servidor local (Ollama, LM Studio) o un servicio en la nube.
        Las tareas con trabajo de estudiantes solo usan motores locales, y si el motor elegido no responde la aplicación avisa: <strong>nunca pasa en silencio a la nube</strong>.</p>
    @if (session('error_motor'))<div class="aviso aviso-error" role="alert">{{ session('error_motor') }}</div>@endif

    @if ($motores->isNotEmpty())
        <table>
            <thead><tr><th>Nombre</th><th>Tipo</th><th>Dirección</th><th>Modelo</th><th>Ubicación</th><th></th></tr></thead>
            <tbody>
                @foreach ($motores as $m)
                    <tr>
                        <td>{{ $m->nombre }} @if ($m->predeterminado)<span class="estado estado-aprobado">Predeterminado</span>@endif</td>
                        <td>{{ ['ollama' => 'Ollama', 'openai_compatible' => 'Compatible OpenAI (LM Studio, llama.cpp)', 'anthropic' => 'Claude (nube)'][$m->driver] }}</td>
                        <td>{{ $m->base_url ?? '—' }}</td>
                        <td><code>{{ $m->modelo }}</code></td>
                        <td>{{ $m->es_local ? 'Local' : 'Nube' }}</td>
                        <td class="fila" style="gap:.4rem">
                            <form method="POST" action="{{ route('ia.motores.probar', $m) }}">@csrf<button class="boton boton-sec boton-chico">Probar</button></form>
                            @unless ($m->predeterminado)<form method="POST" action="{{ route('ia.motores.predeterminado', $m) }}">@csrf<button class="boton boton-sec boton-chico">Usar por defecto</button></form>@endunless
                            <form method="POST" action="{{ route('ia.motores.destroy', $m) }}">@csrf @method('DELETE')<button class="enlace">Eliminar</button></form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <form method="POST" action="{{ route('ia.motores.store') }}" class="tarjeta" style="max-width:640px;margin-top:1rem" x-data="{ driver: '{{ old('driver', 'ollama') }}' }">
        @csrf
        <h2 style="margin-top:0">Agregar motor</h2>
        <div class="campo"><label for="nombre">Nombre</label><input id="nombre" name="nombre" type="text" value="{{ old('nombre', 'Ollama de mi equipo') }}" required></div>
        <div class="campo"><label for="driver">Tipo</label>
            <select id="driver" name="driver" x-model="driver">
                <option value="ollama">Ollama (local)</option>
                <option value="openai_compatible">LM Studio u otro compatible con OpenAI (local)</option>
                <option value="anthropic">Claude, de Anthropic (nube)</option>
            </select></div>
        <div class="campo" x-show="driver !== 'anthropic'"><label for="base_url">Dirección del servidor</label>
            <input id="base_url" name="base_url" type="text" value="{{ old('base_url', 'http://127.0.0.1:11434') }}" placeholder="http://127.0.0.1:11434 (Ollama) o http://127.0.0.1:1234 (LM Studio)"></div>
        <div class="campo"><label for="modelo">Modelo</label><input id="modelo" name="modelo" type="text" value="{{ old('modelo') }}" required placeholder="qwen3:14b, llama3.1:8b, claude-opus-5-5…"></div>
        <div class="campo" x-show="driver === 'anthropic'"><label for="api_key">Clave de API</label><input id="api_key" name="api_key" type="password" autocomplete="off">
            <p class="suave">Se guarda cifrada. Con un motor en la nube, el material que seleccione sale de la institución.</p></div>
        @if ($errors->any())<div class="aviso aviso-error" role="alert">{{ $errors->first() }}</div>@endif
        <button class="boton" type="submit">Agregar</button>
    </form>
</x-app>
