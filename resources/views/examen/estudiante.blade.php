<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' blob:; worker-src blob:; style-src 'unsafe-inline'; connect-src https:; img-src data:">
<title>@if (! empty($datos['ensayo']))ENSAYO · @endif{{ $datos['sigla'] }} — {{ $datos['titulo'] }}</title>
<style>{!! $css !!}</style>
</head>
<body>
<header>
    <h1>@if (! empty($datos['ensayo']))<span class="marca-ensayo">ENSAYO</span> @endif{{ $datos['titulo'] }}</h1>
    <span>{{ $datos['sigla'] }}</span>
    <div class="der"><span id="quien"></span><span class="reloj" id="reloj"></span><span class="guardado" id="guardado" role="status"></span></div>
</header>
<main>
    <section id="portada" class="tarjeta"></section>
    <section id="examen" class="oculto">
        @if (! empty($datos['ensayo']))<section id="ensayo" class="tarjeta oculto" aria-live="polite"></section>@endif
        <div><span id="avance"></span><div class="barra"><div id="barra"></div></div></div>
        <nav class="nav" id="nav" aria-label="Ítems"></nav>
        <div id="items"></div>
        <section id="cierre" class="tarjeta oculto" aria-live="polite"></section>
        <p><button class="boton" id="finalizar">Finalizar y generar la entrega</button></p>
    </section>
</main>
<div id="bloqueo" class="bloqueo oculto" role="alertdialog">Este examen debe presentarse sin internet.<br>Desconecte el equipo de la red para continuar.</div>
<script type="application/json" id="datos-examen">{!! $json !!}</script>
<script>{!! $js !!}</script>
</body>
</html>
