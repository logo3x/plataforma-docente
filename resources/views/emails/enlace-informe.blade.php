<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Su informe</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;line-height:1.5;max-width:560px;margin:0 auto;padding:16px">
    <p>Hola, {{ $estudiante->nombres }}:</p>
    <p>Ya puede ver su informe de <strong>«{{ $actividad->nombre }}»</strong> de {{ $materia->nombre }}: su nota, el detalle de cada pregunta y mis comentarios.</p>
    @if ($mensaje)<p style="white-space:pre-line">{{ $mensaje }}</p>@endif
    <p style="margin:24px 0">
        <a href="{{ $enlace }}" style="background:#0b2545;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;display:inline-block">Ver mi informe</a>
    </p>
    <p style="font-size:14px;color:#4b5563">El enlace es personal: no lo comparta. Vale hasta el {{ $vence?->format('d/m/Y') }}.
        Si el botón no funciona, copie esta dirección en el navegador:<br><span style="word-break:break-all">{{ $enlace }}</span></p>
    <p>Si tiene dudas sobre su calificación, responda este correo.</p>
    <p>{{ $docente->name }}</p>
</body>
</html>
