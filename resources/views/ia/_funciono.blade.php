{{-- 8.4.5: marca de «funcionó» de una versión de plantilla --}}
<form method="POST" action="{{ route('ia.plantillas.funciono', $plantilla) }}" class="fila" style="gap:.3rem">
    @csrf
    @if ($plantilla->funciono)
        <span class="estado estado-aprobado">✓ Funcionó</span><input type="hidden" name="funciono" value="0"><button class="enlace">quitar de la biblioteca</button>
    @else
        <input type="hidden" name="funciono" value="1"><button class="enlace">Marcar la versión {{ $plantilla->version }} como «funcionó»</button>
    @endif
</form>
