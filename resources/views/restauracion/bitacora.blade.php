<x-app :titulo="'Bitácora original — '.$dictado->periodo">
    <div class="migas"><a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }}</a> › Bitácora original</div>
    <h1>Bitácora original del respaldo</h1>
    <p class="suave">Los cambios de notas tal como venían en el respaldo de {{ $restauracion->periodo_original }}@if ($restauracion->generado_en) generado el {{ $restauracion->generado_en->format('Y-m-d H:i') }}@endif.
        Se conservan para consulta y no se pueden modificar; los cambios posteriores están en la bitácora del registro.</p>
    <table>
        <thead><tr><th>Fecha</th><th>Documento</th><th>Actividad</th><th class="num">Antes</th><th class="num">Después</th><th>Origen</th><th>Motivo</th></tr></thead>
        <tbody>
            @php($nombres = \App\Models\Actividad::query()->whereIn('id', collect($restauracion->bitacora_original)->pluck('actividad_restaurada')->filter())->pluck('nombre', 'id'))
            @foreach ($restauracion->bitacora_original as $b)
                <tr>
                    <td>{{ $b['fecha'] ? \Illuminate\Support\Carbon::parse($b['fecha'])->format('Y-m-d H:i') : '—' }}</td>
                    <td>{{ $b['documento'] ?? '—' }}</td>
                    <td>{{ $nombres[$b['actividad_restaurada'] ?? 0] ?? '—' }}</td>
                    <td class="num">{{ $b['anterior'] ?? '—' }}</td>
                    <td class="num">{{ $b['nuevo'] ?? '—' }}</td>
                    <td>{{ $b['origen'] ?? '' }}</td>
                    <td>{{ $b['motivo'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-app>
