@props(['titulo' => null])
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo ? $titulo.' · '.config('app.name') : config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @livewireStyles
</head>
<body>
    {{ $slot }}
    @livewireScripts
    <script>
        /* 7.2: al marcar un tema para generar, se marca el material del repositorio asignado a ese tema o a su unidad */
        document.addEventListener('change', (ev) => {
            const t = ev.target;
            if (!t.matches || !t.matches('input[name="temas[]"]') || !t.checked || !t.form) return;
            const ids = [t.value, t.dataset.unidad].filter(Boolean);
            t.form.querySelectorAll('input[name="contenidos[]"][data-tema]').forEach(c => { if (ids.includes(c.dataset.tema)) c.checked = true; });
        });
    </script>
</body>
</html>
