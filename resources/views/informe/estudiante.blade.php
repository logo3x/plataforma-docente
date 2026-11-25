<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $actividad->nombre }} — {{ $estudiante->nombreCompleto() }}</title>
<style>
{!! $css !!}
body { background: #fff; }
main { max-width: 900px; }
.encabezado-informe { border-bottom: 3px solid var(--marino); padding-bottom: .75rem; margin-bottom: 1rem; }
.nota-grande { font-size: 2.4rem; font-weight: 800; color: var(--marino); line-height: 1; }
.item-informe { break-inside: avoid; box-shadow: none; }
@media print {
    .no-imprimir { display: none !important; }
    main { padding: 0; max-width: none; }
    .tarjeta { border-color: #999; }
    thead th { background: #e5e7eb !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<main>
    <div class="encabezado-informe">
        <div class="suave">{{ $materia->nombre }} · {{ $dictado->periodo }} · Instituto Universitario de la Paz</div>
        <h1 style="margin:.25rem 0">{{ $actividad->nombre }}</h1>
        <div><strong>{{ $estudiante->nombreCompleto() }}</strong> · documento {{ $estudiante->documento }}
            @if ($companeros->isNotEmpty())<br><span class="suave">Entrega grupal con {{ $companeros->map->nombreCompleto()->join(', ') }}</span>@endif</div>
    </div>

    <section class="tarjeta fila" style="justify-content:space-between;align-items:center">
        <div>
            <div class="suave">Nota</div>
            <div class="nota-grande">{{ $vigente === null ? '—' : $escala->mostrar($vigente) }}</div>
            <div class="suave">sobre {{ $escala->mostrar($escala->maximo) }} · aprueba con {{ $escala->mostrar($escala->aprobatoria) }}</div>
        </div>
        <div style="text-align:right">
            @if ($vigente !== null)
                <span class="estado {{ $escala->aprueba($vigente) ? 'estado-aprobado' : 'estado-reprobado' }}">{{ $escala->aprueba($vigente) ? '✓ Aprobado' : '✗ No aprobado' }}</span>
            @endif
            @if ($segunda)
                <div style="margin-top:.4rem">Incluye {{ mb_strtolower($tipoSegunda) }} del {{ $segunda->fecha->format('d/m/Y') }}.</div>
                @if ($mostrarOriginal)<div class="suave">Nota original: {{ $original === null ? 'sin presentar' : $escala->mostrar($original) }}</div>@endif
            @endif
        </div>
    </section>

    @if ($comentarioNota)
        <div class="aviso aviso-info"><strong>Comentario del docente:</strong> {{ $comentarioNota }}</div>
    @endif

    @if ($entrega)
        <h2>Detalle de la calificación</h2>
        <p class="suave">Para cada ítem: el enunciado, su respuesta, la respuesta correcta y el puntaje obtenido sobre el máximo.</p>
        @include('calificador._items', ['respuestas' => $entrega->respuestas])
    @elseif ($vigente === null)
        <div class="tarjeta">No hay entrega registrada para esta actividad.</div>
    @endif

    <p class="suave" style="margin-top:2rem">Informe generado el {{ $generado->format('d/m/Y H:i') }}. Si tiene dudas sobre su calificación, consulte con su docente.</p>
    <p class="no-imprimir"><button class="boton" onclick="window.print()">Imprimir o guardar como PDF</button></p>
</main>
</body>
</html>
