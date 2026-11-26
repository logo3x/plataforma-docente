@php($P = \App\Calificacion\PoliticaAlertas::class)
<x-app titulo="Política de alertas">
    <h1>Política frente a las alertas</h1>
    <div class="aviso aviso-info" role="note">Las alertas <strong>informan</strong>: ninguna baja la nota ni sanciona por sí sola (6.3).
        Aquí decide cuáles le piden revisión y si el estudiante puede llegar a saber de ellas. Por defecto no sabe nada;
        con «si usted lo decide», el informe solo muestra las que usted revisó y marcó para contar, en un texto neutral y con su nota.</div>
    <form method="POST" action="{{ route('alertas.politica.guardar') }}">@csrf @method('PUT')
        <div class="tabla-notas"><table>
            <thead><tr><th>Alerta</th><th>Pide su revisión</th><th>Qué ve el estudiante</th></tr></thead>
            <tbody>
                @foreach ($P::TIPOS as $tipo => [$nombre, $porque])
                    <tr>
                        <td><strong>{{ $nombre }}</strong><div class="suave">{{ $porque }}</div></td>
                        <td><label style="font-weight:normal"><input type="hidden" name="politica[{{ $tipo }}][revisar]" value="0">
                            <input type="checkbox" name="politica[{{ $tipo }}][revisar]" value="1" @checked($politica[$tipo]['revisar'])> Sí</label></td>
                        <td><select name="politica[{{ $tipo }}][informar]" aria-label="Qué ve el estudiante de «{{ $nombre }}»">
                            @foreach ($P::INFORMAR as $k => $t)<option value="{{ $k }}" @selected($politica[$tipo]['informar'] === $k)>{{ $t }}</option>@endforeach</select></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <p><button class="boton" type="submit">Guardar la política</button></p>
    </form>
    <p class="suave">La similitud entre entregas tiene su propia revisión, par por par, en el calificador.</p>
</x-app>
