@php use App\Models\SegundaOportunidad as S; @endphp
<x-app :titulo="'Segundas oportunidades — '.$dictado->materia->nombre">
    <div class="migas"><a href="{{ route('dictados.show', $dictado) }}">{{ $dictado->materia->nombre }} {{ $dictado->periodo }}</a> › Segundas oportunidades</div>
    <h1>Supletorios, recuperaciones y habilitaciones — {{ $dictado->periodo }}</h1>
    @if ($eventos->isEmpty())
        <div class="tarjeta vacio"><strong>No hay segundas oportunidades en este periodo.</strong>Se registran desde el registro de notas, en el nombre de cada estudiante.</div>
    @else
        <table>
            <thead><tr><th>Fecha</th><th>Estudiante</th><th>Tipo</th><th>Sobre</th><th class="num">Nota</th><th>Regla</th><th>Motivo</th><th>Estado</th></tr></thead>
            <tbody>
                @foreach ($eventos as $s)
                    <tr>
                        <td>{{ $s->fecha->format('Y-m-d') }}</td>
                        <td><a href="{{ route('segundas.create', [$s->estudiante->grupos->first(), $s->estudiante]) }}">{{ $s->estudiante->nombreCompleto() }}</a></td>
                        <td>{{ S::TIPOS[$s->tipo] }}</td>
                        <td>{{ $s->ambito instanceof \App\Models\Actividad ? $s->ambito->nombre : ($s->ambito instanceof \App\Models\Division ? $s->ambito->nombre : 'Materia') }}</td>
                        <td class="num">{{ $escala->mostrar($s->valor) }}</td>
                        <td>{{ ['reemplaza' => 'Reemplaza', 'promedia' => 'Promedia', 'tope' => 'Tope '.($s->tope ? $escala->mostrar($s->tope) : '')][$s->regla] }}</td>
                        <td>{{ $s->motivo }}</td>
                        <td>{{ $s->anulada_en ? 'Anulado: '.$s->motivo_anulacion : 'Vigente' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-app>
