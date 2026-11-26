<x-layouts.base :titulo="$a->sigla.' — respaldo imprimible'">
    {{-- Respaldo en papel (5.4.12): sin respuestas, para cuando fallen los equipos --}}
    <main class="examen-papel">
        <p class="no-imprimir"><button class="boton" type="button" onclick="window.print()">Imprimir o guardar en PDF</button></p>
        <header>
            <h1 style="margin:0">{{ $a->titulo }}</h1>
            <p style="margin:.2rem 0">{{ $a->materia->nombre }} · {{ $a->sigla }}@if (count($a->versiones()) > 1) · versión {{ $version }}@endif · valor {{ $a->configuracion['valor'] ?? '' }}@if ($c['reloj_minutos']) · {{ $c['reloj_minutos'] }} minutos @endif</p>
            <p>Nombre: ______________________________________ Documento: ________________ Grupo: ________</p>
            @if ($c['instrucciones'])<p><em>{{ $c['instrucciones'] }}</em></p>@endif
        </header>
        <ol>
            @foreach ($items as $it)
                @php($e = $it->pregunta->enunciado)
                <li class="pregunta-papel">
                    <p><strong>{{ $e['texto'] ?? '' }}</strong> <span class="suave">({{ (string) \Brick\Math\BigDecimal::of($it->peso)->strippedOfTrailingZeros() }})</span></p>
                    @switch($it->pregunta->tipo)
                        @case('unica') @case('multiple')
                            <ul class="opciones-papel">@foreach ($e['opciones'] ?? [] as $k => $o)<li>{{ $it->pregunta->tipo === 'unica' ? '○' : '□' }} {{ $k }}) {{ $o }}</li>@endforeach</ul>
                            @if ($it->pregunta->tipo === 'multiple')<p class="suave">Marque todas las correctas.</p>@endif
                            @break
                        @case('vf')<p>○ Verdadero &nbsp;&nbsp; ○ Falso</p>@break
                        @case('emparejar')
                            <table><tbody>@foreach ($e['izquierda'] ?? [] as $k => $iz)<tr><td>{{ $k }}. {{ $iz }}</td><td>_____</td></tr>@endforeach</tbody></table>
                            <p>@foreach ($e['derecha'] ?? [] as $k => $de){{ $k }}) {{ $de }}@if (! $loop->last) · @endif @endforeach</p>
                            @break
                        @case('ordenar')<ul class="opciones-papel">@foreach ($e['pasos'] ?? [] as $k => $p)<li>___ {{ $p }}</li>@endforeach</ul><p class="suave">Numere los pasos en orden.</p>@break
                        @case('completar')<pre class="codigo">{{ $e['plantilla'] ?? '' }}</pre>@break
                        @case('reto')
                            @if (! empty($e['plantilla_codigo']))<pre class="codigo">{{ $e['plantilla_codigo'] }}</pre>@endif
                            @foreach ($e['casos_visibles'] ?? [] as $cv)<p class="suave">Ejemplo: <code>{{ $cv['entrada'] ?? '' }}</code>@if (isset($cv['esperada'])) → <code>{{ $cv['esperada'] }}</code>@endif</p>@endforeach
                            <div class="espacio-respuesta" style="min-height:16rem"></div>
                            @break
                        @default<div class="espacio-respuesta" style="min-height:{{ max(4, (int) ($e['lineas'] ?? 6)) * 1.6 }}rem"></div>
                    @endswitch
                </li>
            @endforeach
        </ol>
    </main>
</x-layouts.base>
