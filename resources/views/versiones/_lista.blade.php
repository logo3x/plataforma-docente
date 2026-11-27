{{-- 7.6: versiones anteriores de lo generado, con la opción de volver a una. --}}
@php($lista = $modelo ? app(\App\Historial\Versiones::class)->de($modelo) : collect())
@if (session('error_version'))<div class="aviso aviso-error" role="alert">{{ session('error_version') }}</div>@endif
@if ($lista->isNotEmpty())
    <details class="tarjeta versiones" style="margin-top:1rem">
        <summary><strong>Versiones anteriores</strong> <span class="suave">({{ $lista->count() }}: cada cambio guarda antes cómo estaba)</span></summary>
        <ul style="list-style:none;padding:0;margin:.5rem 0 0">
            @foreach ($lista as $v)
                <li class="fila" style="justify-content:space-between;border-bottom:1px solid var(--borde);padding:.35rem 0">
                    <span>Versión {{ $v->numero }} · {{ $v->created_at?->format('Y-m-d H:i') }} · «{{ $v->titulo }}»@if ($v->usuario) <span class="suave">· {{ $v->usuario->name }}</span>@endif</span>
                    <form method="POST" action="{{ route('versiones.restaurar', $v) }}" onsubmit="return confirm('¿Volver a la versión {{ $v->numero }}? Lo que hay ahora se guarda como otra versión.')">@csrf
                        <button class="boton boton-sec boton-chico" aria-label="Volver a la versión {{ $v->numero }}">Volver a esta versión</button></form>
                </li>
            @endforeach
        </ul>
    </details>
@endif
