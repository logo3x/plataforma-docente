<!DOCTYPE html>
<html lang="{{ $ficha['idioma'] ?? 'es' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $ficha['titulo'] }}</title>
{{-- Ficha de metadatos también en Dublin Core, para repositorios y buscadores --}}
<link rel="schema.DC" href="http://purl.org/dc/elements/1.1/">
@foreach ($campos as $k => $c)@if (! empty($c['dc']) && filled($ficha[$k] ?? null))
<meta name="DC.{{ $c['dc'] }}" content="{{ $ficha[$k] }}">
@endif @endforeach
<meta name="DC.date" content="{{ now()->toDateString() }}">
<meta name="DC.format" content="text/html">
<style>{!! $css !!}
body { background: #fff; }
.publicado { max-width: 52rem; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }
.ficha-recurso { margin-top: 2.5rem; border-top: 2px solid var(--borde); padding-top: 1rem; font-size: .92rem; }
.ficha-recurso dl { display: grid; grid-template-columns: minmax(10rem, max-content) 1fr; gap: .25rem 1rem; margin: .5rem 0 0; }
.ficha-recurso dt { font-weight: 600; color: var(--suave); }
.ficha-recurso dd { margin: 0; white-space: pre-line; }
.leido { margin-top: 1.5rem; }
</style>
</head>
<body>
<main class="publicado">
{!! $cuerpo !!}
@if ($scorm)
    <p class="leido no-imprimir"><button class="boton" type="button" id="marcar-leido">Marcar como leído</button> <span id="estado-leido" class="suave" role="status"></span></p>
@endif
<footer class="ficha-recurso">
    <details>
        <summary><strong>Ficha del recurso</strong></summary>
        <dl>@foreach ($campos as $k => $c)@if (filled($ficha[$k] ?? null))<dt>{{ $c['etiqueta'] }}</dt><dd>{{ $ficha[$k] }}</dd>@endif @endforeach</dl>
    </details>
    <p class="suave">{{ $ficha['institucion'] }} · {{ $ficha['licencia'] }}</p>
</footer>
</main>
@if ($scorm)
<script>
/* SCORM 1.2: la plataforma institucional registra que el estudiante abrió el recurso y que lo terminó de leer.
   Si se abre fuera de una plataforma (sin API), el recurso funciona igual. */
(function () {
  /* Un marco de otro origen no deja leer su API: se sigue sin ella, el recurso no se rompe */
  function buscar(w) { try { for (var i = 0; w && i < 10; i++) { if (w.API) return w.API; if (w.parent === w) break; w = w.parent; } } catch (e) {} return null; }
  var api = buscar(window), terminado = false;
  if (!api) { try { api = window.opener && buscar(window.opener); } catch (e) { api = null; } }
  var estado = document.getElementById('estado-leido');
  if (api) { api.LMSInitialize(''); if (api.LMSGetValue('cmi.core.lesson_status') !== 'completed') { api.LMSSetValue('cmi.core.lesson_status', 'incomplete'); } api.LMSCommit(''); }
  function completar() {
    if (terminado) return; terminado = true;
    if (api) { api.LMSSetValue('cmi.core.lesson_status', 'completed'); api.LMSCommit(''); }
    estado.textContent = api ? '✓ Registrado como leído.' : '✓ Leído (fuera de la plataforma no se registra).';
  }
  document.getElementById('marcar-leido').addEventListener('click', completar);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (e) { if (e[0].isIntersecting) completar(); }).observe(document.querySelector('.ficha-recurso'));
  }
  window.addEventListener('pagehide', function () { if (api) { api.LMSFinish(''); api = null; } });
})();
</script>
@endif
</body>
</html>
