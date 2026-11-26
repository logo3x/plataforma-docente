<?php

use App\Http\Controllers\ApunteController;
use App\Http\Controllers\Calificador\ResultadosNavegadorController;
use App\Http\Controllers\CalificadorController;
use App\Http\Controllers\CargaNotasController;
use App\Http\Controllers\CierreController;
use App\Http\Controllers\ContenidoController;
use App\Http\Controllers\DebateController;
use App\Http\Controllers\DevolucionController;
use App\Http\Controllers\DictadoController;
use App\Http\Controllers\EjemploController;
use App\Http\Controllers\EstadisticasController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ExportacionController;
use App\Http\Controllers\GeneracionBancoController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\MateriaController;
use App\Http\Controllers\MotorIaController;
use App\Http\Controllers\PlantillasIaController;
use App\Http\Controllers\ProyectoController;
use App\Http\Controllers\ReclamoController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\RubricaController;
use App\Http\Controllers\SegundaOportunidadController;
use App\Http\Controllers\SeguridadController;
use App\Http\Controllers\TemarioController;
use App\Http\Controllers\UnidadDidacticaController;
use App\Http\Controllers\VocabularioController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inicio');

// Informe del estudiante por enlace personal firmado: sin cuenta, solo lo suyo (6.9)
Route::get('/informe/{devolucion}', [DevolucionController::class, 'publico'])
    ->middleware(['signed', 'throttle:30,1'])->name('informe.publico');

Route::middleware('auth')->group(function () {
    Route::get('/inicio', InicioController::class)->name('inicio');
    Route::get('/seguridad', SeguridadController::class)->name('seguridad');

    Route::get('/materias/crear', [MateriaController::class, 'create'])->name('materias.create');
    Route::post('/materias', [MateriaController::class, 'store'])->name('materias.store');

    Route::get('/dictados/{dictado}', [DictadoController::class, 'show'])->name('dictados.show');
    Route::post('/dictados/{dictado}/siguiente', [DictadoController::class, 'store'])->name('dictados.store');

    Route::get('/plantilla-lista-clase', [ImportacionController::class, 'plantilla'])->name('importacion.plantilla');
    Route::get('/grupos/{grupo}/importar', [ImportacionController::class, 'create'])->name('importacion.create');
    Route::post('/grupos/{grupo}/importar', [ImportacionController::class, 'previsualizar'])->name('importacion.previa');
    Route::post('/grupos/{grupo}/importar/confirmar', [ImportacionController::class, 'confirmar'])->name('importacion.confirmar');

    Route::get('/grupos/{grupo}/notas', RegistroController::class)->name('registro');
    Route::get('/grupos/{grupo}/cierre', CierreController::class)->name('cierre');
    Route::get('/grupos/{grupo}/exportar.xlsx', [ExportacionController::class, 'xlsx'])->name('exportar.xlsx');
    Route::get('/grupos/{grupo}/planilla.csv', [ExportacionController::class, 'csv'])->name('exportar.csv');
    Route::get('/dictados/{dictado}/respaldo.json', [ExportacionController::class, 'respaldo'])->name('respaldo');
    Route::get('/grupos/{grupo}/estudiantes/{estudiante}', [EstudianteController::class, 'edit'])->name('estudiantes.edit');
    Route::put('/grupos/{grupo}/estudiantes/{estudiante}', [EstudianteController::class, 'update'])->name('estudiantes.update');
    Route::get('/grupos/{grupo}/estudiantes/{estudiante}/segunda-oportunidad', [SegundaOportunidadController::class, 'create'])->name('segundas.create');
    Route::post('/grupos/{grupo}/estudiantes/{estudiante}/segunda-oportunidad/simular', [SegundaOportunidadController::class, 'simular'])->name('segundas.simular');
    Route::post('/grupos/{grupo}/estudiantes/{estudiante}/segunda-oportunidad', [SegundaOportunidadController::class, 'store'])->name('segundas.store');
    Route::post('/grupos/{grupo}/segundas-oportunidades/{segunda}/anular', [SegundaOportunidadController::class, 'anular'])->name('segundas.anular');
    Route::get('/dictados/{dictado}/segundas-oportunidades', [SegundaOportunidadController::class, 'index'])->name('segundas.index');
    Route::get('/grupos/{grupo}/cargar-notas', [CargaNotasController::class, 'create'])->name('carga-notas.create');
    Route::post('/grupos/{grupo}/cargar-notas', [CargaNotasController::class, 'subir'])->name('carga-notas.subir');
    Route::get('/grupos/{grupo}/cargar-notas/previa', [CargaNotasController::class, 'previa'])->name('carga-notas.previa');
    Route::post('/grupos/{grupo}/cargar-notas/confirmar', [CargaNotasController::class, 'confirmar'])->name('carga-notas.confirmar');

    Route::get('/materias/{materia}/actividades', [CalificadorController::class, 'artefactos'])->name('artefactos.index');
    Route::post('/materias/{materia}/actividades', [CalificadorController::class, 'importar'])->name('artefactos.importar');
    Route::post('/actividades-evaluables/{artefacto}/aplicar', [CalificadorController::class, 'aplicar'])->name('artefactos.aplicar');

    Route::prefix('ia')->name('ia.')->group(function () {
        Route::get('motores', [MotorIaController::class, 'index'])->name('motores');
        Route::post('motores', [MotorIaController::class, 'store'])->name('motores.store');
        Route::post('motores/{motor}/probar', [MotorIaController::class, 'probar'])->name('motores.probar');
        Route::post('motores/{motor}/predeterminado', [MotorIaController::class, 'predeterminado'])->name('motores.predeterminado');
        Route::delete('motores/{motor}', [MotorIaController::class, 'destroy'])->name('motores.destroy');
        Route::get('plantillas', [PlantillasIaController::class, 'edit'])->name('plantillas');
        Route::post('plantillas/perfil', [PlantillasIaController::class, 'perfil'])->name('plantillas.perfil');
        Route::post('plantillas', [PlantillasIaController::class, 'plantilla'])->name('plantillas.guardar');
        Route::post('plantillas/restaurar', [PlantillasIaController::class, 'restaurar'])->name('plantillas.restaurar');
    });
    Route::get('/materias/{materia}/estilo', [PlantillasIaController::class, 'estilo'])->name('materias.estilo');
    Route::put('/materias/{materia}/estilo', [PlantillasIaController::class, 'guardarEstilo'])->name('materias.estilo.guardar');
    Route::get('/materias/{materia}/contenido', [ContenidoController::class, 'index'])->name('contenido.index');
    Route::post('/materias/{materia}/contenido', [ContenidoController::class, 'store'])->name('contenido.store');
    Route::get('/contenido/{contenido}', [ContenidoController::class, 'show'])->name('contenido.show');
    Route::delete('/contenido/{contenido}', [ContenidoController::class, 'destroy'])->name('contenido.destroy');
    Route::get('/materias/{materia}/rubricas', [RubricaController::class, 'index'])->name('rubricas.index');
    Route::get('/materias/{materia}/rubricas/nueva', [RubricaController::class, 'create'])->name('rubricas.create');
    Route::post('/materias/{materia}/rubricas', [RubricaController::class, 'store'])->name('rubricas.store');
    Route::post('/materias/{materia}/rubricas/prompt', [RubricaController::class, 'preparar'])->name('rubricas.preparar');
    Route::post('/materias/{materia}/rubricas/generar', [RubricaController::class, 'generar'])->name('rubricas.generar');
    Route::get('/rubricas/{rubrica}/editar', [RubricaController::class, 'edit'])->name('rubricas.edit');
    Route::put('/rubricas/{rubrica}', [RubricaController::class, 'update'])->name('rubricas.update');
    Route::delete('/rubricas/{rubrica}', [RubricaController::class, 'destroy'])->name('rubricas.destroy');
    Route::get('/generaciones/{generacion}/rubrica', [RubricaController::class, 'borrador'])->name('rubricas.borrador');
    Route::put('/generaciones/{generacion}/rubrica', [RubricaController::class, 'editarBorrador'])->name('rubricas.borrador.editar');
    Route::post('/generaciones/{generacion}/rubrica/corregir', [RubricaController::class, 'corregir'])->name('rubricas.corregir');
    Route::post('/generaciones/{generacion}/rubrica/aprobar', [RubricaController::class, 'aprobar'])->name('rubricas.aprobar');
    Route::post('/generaciones/{generacion}/rubrica/descartar', [RubricaController::class, 'descartar'])->name('rubricas.descartar');
    Route::get('/materias/{materia}/unidades', [UnidadDidacticaController::class, 'index'])->name('unidades.index');
    Route::get('/materias/{materia}/unidades/nueva', [UnidadDidacticaController::class, 'create'])->name('unidades.create');
    Route::post('/materias/{materia}/unidades', [UnidadDidacticaController::class, 'store'])->name('unidades.store');
    Route::post('/materias/{materia}/unidades/prompt', [UnidadDidacticaController::class, 'preparar'])->name('unidades.preparar');
    Route::post('/materias/{materia}/unidades/generar', [UnidadDidacticaController::class, 'generar'])->name('unidades.generar');
    Route::get('/unidades/{unidad}', [UnidadDidacticaController::class, 'show'])->name('unidades.show');
    Route::get('/unidades/{unidad}/editar', [UnidadDidacticaController::class, 'edit'])->name('unidades.edit');
    Route::put('/unidades/{unidad}', [UnidadDidacticaController::class, 'update'])->name('unidades.update');
    Route::delete('/unidades/{unidad}', [UnidadDidacticaController::class, 'destroy'])->name('unidades.destroy');
    Route::get('/generaciones/{generacion}/unidad', [UnidadDidacticaController::class, 'borrador'])->name('unidades.borrador');
    Route::put('/generaciones/{generacion}/unidad', [UnidadDidacticaController::class, 'editarBorrador'])->name('unidades.borrador.editar');
    Route::post('/generaciones/{generacion}/unidad/corregir', [UnidadDidacticaController::class, 'corregir'])->name('unidades.corregir');
    Route::post('/generaciones/{generacion}/unidad/aprobar', [UnidadDidacticaController::class, 'aprobar'])->name('unidades.aprobar');
    Route::post('/generaciones/{generacion}/unidad/descartar', [UnidadDidacticaController::class, 'descartar'])->name('unidades.descartar');
    // Generadores de material del catálogo (7.4): mismas pantallas y flujo de borrador para cada tipo
    foreach (['ejemplos' => EjemploController::class, 'debates' => DebateController::class, 'vocabularios' => VocabularioController::class, 'proyectos' => ProyectoController::class, 'apuntes' => ApunteController::class] as $r => $c) {
        Route::get("/materias/{materia}/{$r}", [$c, 'index'])->name("{$r}.index");
        Route::get("/materias/{materia}/{$r}/nuevo", [$c, 'create'])->name("{$r}.create");
        Route::post("/materias/{materia}/{$r}", [$c, 'store'])->name("{$r}.store");
        Route::post("/materias/{materia}/{$r}/prompt", [$c, 'preparar'])->name("{$r}.preparar");
        Route::post("/materias/{materia}/{$r}/generar", [$c, 'generar'])->name("{$r}.generar");
        Route::get("/{$r}/{material}", [$c, 'show'])->name("{$r}.show");
        Route::get("/{$r}/{material}/editar", [$c, 'edit'])->name("{$r}.edit");
        Route::put("/{$r}/{material}", [$c, 'update'])->name("{$r}.update");
        Route::delete("/{$r}/{material}", [$c, 'destroy'])->name("{$r}.destroy");
        Route::get("/generaciones/{generacion}/{$r}", [$c, 'borrador'])->name("{$r}.borrador");
        Route::put("/generaciones/{generacion}/{$r}", [$c, 'editarBorrador'])->name("{$r}.borrador.editar");
        Route::post("/generaciones/{generacion}/{$r}/corregir", [$c, 'corregir'])->name("{$r}.corregir");
        Route::post("/generaciones/{generacion}/{$r}/aprobar", [$c, 'aprobar'])->name("{$r}.aprobar");
        Route::post("/generaciones/{generacion}/{$r}/descartar", [$c, 'descartar'])->name("{$r}.descartar");
    }
    Route::post('/apuntes/{material}/repositorio', [ApunteController::class, 'repositorio'])->name('apuntes.repositorio');
    Route::post('/vocabularios/{material}/glosario', [VocabularioController::class, 'glosario'])->name('vocabularios.glosario');
    Route::get('/materias/{materia}/historial', [HistorialController::class, 'index'])->name('historial.index');
    Route::post('/materias/{materia}/historial/copiar', [HistorialController::class, 'copiar'])->name('historial.copiar');
    Route::get('/materias/{materia}/reclamos', [ReclamoController::class, 'materia'])->name('reclamos.materia');
    Route::get('/materias/{materia}/temario', [TemarioController::class, 'show'])->name('temario.show');
    Route::post('/materias/{materia}/temario/extraer', [TemarioController::class, 'extraer'])->name('temario.extraer');
    Route::post('/materias/{materia}/temario/sugerir', [TemarioController::class, 'sugerir'])->name('temario.sugerir');
    Route::get('/temario/borradores/{generacion}', [TemarioController::class, 'borrador'])->name('temario.borrador');
    Route::put('/temario/borradores/{generacion}', [TemarioController::class, 'editar'])->name('temario.editar');
    Route::post('/temario/borradores/{generacion}/aplicar', [TemarioController::class, 'aplicar'])->name('temario.aplicar');
    Route::post('/temario/borradores/{generacion}/descartar', [TemarioController::class, 'descartar'])->name('temario.descartar');
    Route::get('/materias/{materia}/generar', [GeneracionBancoController::class, 'create'])->name('generacion.create');
    Route::post('/materias/{materia}/generar/prompt', [GeneracionBancoController::class, 'preparar'])->name('generacion.preparar');
    Route::post('/materias/{materia}/generar', [GeneracionBancoController::class, 'generar'])->name('generacion.generar');
    Route::get('/generaciones/{generacion}', [GeneracionBancoController::class, 'show'])->name('generacion.show');
    Route::put('/generaciones/{generacion}', [GeneracionBancoController::class, 'editar'])->name('generacion.editar');
    Route::post('/generaciones/{generacion}/reescalar', [GeneracionBancoController::class, 'reescalar'])->name('generacion.reescalar');
    Route::post('/generaciones/{generacion}/corregir', [GeneracionBancoController::class, 'corregir'])->name('generacion.corregir');
    Route::post('/generaciones/{generacion}/aprobar', [GeneracionBancoController::class, 'aprobar'])->name('generacion.aprobar');
    Route::post('/generaciones/{generacion}/descartar', [GeneracionBancoController::class, 'descartar'])->name('generacion.descartar');

    Route::prefix('calificador')->name('calificador.')->group(function () {
        Route::get('actividades/{actividad}', [CalificadorController::class, 'show'])->name('show');
        Route::get('actividades/{actividad}/examen.html', [CalificadorController::class, 'examen'])->name('examen');
        Route::post('actividades/{actividad}/entregas', [CalificadorController::class, 'subir'])->name('subir');
        Route::get('actividades/{actividad}/retos-pendientes', [CalificadorController::class, 'retosPendientes'])->name('retos');
        Route::post('actividades/{actividad}/registro', [CalificadorController::class, 'pasarAlRegistro'])->name('registro');
        Route::get('actividades/{actividad}/abiertas', [CalificadorController::class, 'abiertas'])->name('abiertas');
        Route::get('entregas/{entrega}', [CalificadorController::class, 'detalle'])->name('detalle');
        Route::get('actividades/{actividad}/estadisticas', [EstadisticasController::class, 'show'])->name('estadisticas');
        Route::get('actividades/{actividad}/devolucion', [DevolucionController::class, 'index'])->name('devolucion');
        Route::get('actividades/{actividad}/devolucion/{estudiante}', [DevolucionController::class, 'ver'])->name('devolucion.ver');
        Route::post('actividades/{actividad}/devolucion/liberar', [DevolucionController::class, 'liberar'])->name('devolucion.liberar');
        Route::post('actividades/{actividad}/devolucion/revocar', [DevolucionController::class, 'revocar'])->name('devolucion.revocar');
        Route::post('actividades/{actividad}/devolucion/configurar', [DevolucionController::class, 'configurar'])->name('devolucion.configurar');
        Route::get('actividades/{actividad}/informes.zip', [DevolucionController::class, 'zip'])->name('devolucion.zip');
        Route::get('actividades/{actividad}/enlaces.csv', [DevolucionController::class, 'enlaces'])->name('devolucion.enlaces');
        Route::post('devoluciones/{devolucion}/marcar', [DevolucionController::class, 'marcar'])->name('devolucion.marcar');
        Route::get('actividades/{actividad}/reclamos', [ReclamoController::class, 'index'])->name('reclamos');
        Route::post('actividades/{actividad}/reclamos', [ReclamoController::class, 'store'])->name('reclamos.store');
        Route::post('reclamos/{reclamo}/resolver', [ReclamoController::class, 'resolver'])->name('reclamos.resolver');
        Route::get('actividades/{actividad}/estadisticas.csv', [EstadisticasController::class, 'csv'])->name('estadisticas.csv');
        Route::post('items/{item}/anular', [EstadisticasController::class, 'anular'])->name('items.anular');
        Route::post('items/{item}/restaurar', [EstadisticasController::class, 'restaurar'])->name('items.restaurar');
        Route::post('items/{item}/tema', [EstadisticasController::class, 'tema'])->name('items.tema');
        Route::post('entregas/{entrega}/resultados-navegador', ResultadosNavegadorController::class)
            ->name('entregas.resultados-navegador');
    });
});
