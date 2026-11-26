{{-- Vista previa estática de la página que escribió el estudiante: sin scripts (sandbox vacío) y sin red. --}}
@php($csp = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:")
<iframe class="vista-previa-html" sandbox="" referrerpolicy="no-referrer" loading="lazy" title="{{ $titulo ?? 'Vista previa de la página entregada' }}"
        srcdoc="{{ '<meta http-equiv="Content-Security-Policy" content="'.$csp.'">'.$codigo }}"></iframe>
<p class="suave" style="margin:.25rem 0 0">Vista previa sin ejecutar sus scripts: cómo se ve la página con su HTML y su CSS.</p>
