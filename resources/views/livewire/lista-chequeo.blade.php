<div>
    @if ($total === 0)
        <p class="suave">La materia no tiene temario. <a href="{{ route('temario.show', $grupo->dictado->materia) }}">Armarlo</a></p>
    @else
        <p style="margin:0 0 .5rem"><strong>{{ $hechos }} de {{ $total }}</strong> temas vistos con {{ $grupo->nombre }}.
            <span class="suave">Lo marcado es lo que la IA puede evaluar para este grupo.</span></p>
        <div class="barra-avance" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $hechos }}"><div style="width: {{ $total ? round(100 * $hechos / $total) : 0 }}%"></div></div>
        @foreach ($unidades as $u)
            @continue($u->hijos->isEmpty())
            <fieldset style="border:0;padding:0;margin:.5rem 0">
                <legend style="font-weight:600">{{ $u->titulo }}</legend>
                @foreach ($u->hijos as $t)
                    <label style="font-weight:400" wire:key="c-{{ $t->id }}">
                        <input type="checkbox" @checked(isset($vistos[$t->id])) wire:click="alternar({{ $t->id }})"> {{ $t->titulo }}
                        @isset($vistos[$t->id])<span class="suave">— {{ \Illuminate\Support\Carbon::parse($vistos[$t->id])->format('d/m') }}</span>@endisset
                        @isset($desempeno[$t->id])
                            @php($pct = round($desempeno[$t->id]['acierto'] * 100))
                            @if ($desempeno[$t->id]['acierto'] < $umbral && $desempeno[$t->id]['respuestas'] >= \App\Calificacion\EstadisticasActividad::MINIMO_PARA_MARCAR)
                                <span class="estado estado-reprobado" title="{{ $desempeno[$t->id]['respuestas'] }} respuestas en actividades calificadas">⚠ bajo desempeño ({{ $pct }} %): reforzar</span>
                            @else
                                <span class="suave" title="{{ $desempeno[$t->id]['respuestas'] }} respuestas en actividades calificadas">· acierto {{ $pct }} %</span>
                            @endif
                        @endisset
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    @endif
</div>
