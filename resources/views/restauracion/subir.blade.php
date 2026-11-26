<x-app titulo="Restaurar un respaldo">
    <div class="migas"><a href="{{ route('inicio') }}">Mis materias</a> › Restaurar un respaldo</div>
    <h1>Restaurar un respaldo de periodo</h1>
    <p class="suave">Suba el archivo <code>.json</code> que descargó con «Respaldo» en la página de un periodo. La restauración crea un periodo
        <strong>nuevo</strong> con sus grupos, estudiantes, cortes, actividades, notas y segundas oportunidades; nunca sobrescribe nada de lo que ya tiene.
        Antes de crear algo verá un resumen para confirmar.</p>
    <form method="POST" action="{{ route('restauracion.revisar') }}" enctype="multipart/form-data" class="tarjeta">
        @csrf
        <div class="campo"><label for="respaldo">Archivo de respaldo</label>
            <input id="respaldo" name="respaldo" type="file" accept=".json,application/json" required>
            @error('respaldo')<div class="error" role="alert">{{ $message }}</div>@enderror</div>
        <button class="boton" type="submit">Revisar el respaldo</button>
    </form>
</x-app>
