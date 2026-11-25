# Plataforma educativa (plataforma-docente)

Aplicación Laravel que unifica el registro de notas, la generación de actividades, el calificador y el contenido de las materias. El ciclo es: preparar contenido → generar la actividad → aplicarla → calificarla → registrar la nota, sin escribir un dato dos veces.

- Requerimientos: documento v3 (fuera del repositorio).
- Revisión del documento: [`docs/revision-requerimientos-v3.md`](docs/revision-requerimientos-v3.md)
- Diseño del modelo de datos y del motor de IA: [`docs/diseno-modelo-datos-y-motor-ia.md`](docs/diseno-modelo-datos-y-motor-ia.md)

## Estado: MVP del registro de notas

Lo que ya se puede usar en un semestre real:

1. **Entrar con seguridad.** Login con límite de intentos, segundo factor opcional y sin registro público.
2. **Crear la materia en un paso.** Periodo, cortes con sus pesos, componentes y grupos, con una plantilla sugerida que se puede cambiar.
3. **Cargar la lista de secretaría.** Con vista previa y reimportación que no duplica ni borra.
4. **Llevar las notas.** Pestaña por corte y consolidado, columnas dinámicas, celda vacía distinta de cero, deshacer, historial de cada nota y bitácora que no se puede alterar.
5. **Cargar notas desde un archivo.** Cruce por documento o por nombre completo, conversión de escala y reporte de lo que no cruzó.
6. **Cerrar el periodo.** Qué nota necesita cada estudiante, quiénes están al margen, quiénes aprueban solo por una vacía o por redondeo, y revisión de ceros ordenada por cercanía al límite.
7. **Aplicar y calificar exámenes digitales.** Se importa el banco en JSON y se descarga el examen: un HTML que funciona sin internet, no lleva respuestas, guarda solo y cifra la entrega. Luego se suben las entregas y el calificador descifra, identifica al estudiante y al examen, genera alertas, ejecuta los retos de código en el navegador, califica las abiertas por pregunta y pasa las notas al registro, también en grupo.
8. **Generar el banco con IA.** Se carga el material de la materia (PDF, Word, PowerPoint, texto) y se arma el prompt con tres capas: su perfil, el estilo de la materia y la tarea. El prompt se revisa y se edita antes de enviarlo. La IA devuelve un borrador que se valida igual que un banco importado; usted lo corrige, a mano o pidiéndole a la IA, y lo guarda solo cuando lo aprueba. Funciona con Ollama o LM Studio en local, o con Claude en la nube.
9. **Organizar la materia con su temario.** La IA extrae unidades, temas y resultados de aprendizaje del plan curricular como borrador; usted lo aprueba y lo ajusta, o lo arma a mano. La lista de chequeo de cada grupo está a un clic en el registro de notas, y lo que marca como visto es lo único que la IA puede evaluar para ese grupo. La IA también sugiere, tema por tema, cómo organizar la clase.
10. **Planear cada unidad.** A partir de una unidad del temario, con sus temas, resultados de aprendizaje y las sugerencias aceptadas, la IA propone la secuencia de sesiones. Cada sesión trae objetivo, inicio, desarrollo y cierre, recursos y evaluación. El borrador se ajusta en un editor por sesiones que avisa si un tema no está en el temario o si un tema de la unidad quedó sin cubrir. También se arma a mano, con una sesión por tema como punto de partida. La unidad se imprime o se guarda en PDF, y desde cada sesión se abre el generador de quices con sus temas ya marcados.
11. **Mejorar el examen con los datos.** Cada actividad calificada tiene estadísticas del grupo y análisis por ítem: acierto, discriminación y qué opción eligió cada quien. Se marcan los ítems para revisar, y un ítem ambiguo se anula con su motivo, lo que recalcula y actualiza el registro. Los temas de bajo desempeño se resaltan en la lista de chequeo.
12. **Dar segundas oportunidades.** Supletorio o recuperación de una actividad y habilitación de un corte o de la materia, con la regla que use la institución (reemplaza, promedia o tope). Se simula antes de registrar, la nota original nunca se borra y un registro equivocado se anula con motivo.
13. **Devolver a cada estudiante su informe.** Contiene la nota, el detalle de cada ítem con la respuesta correcta y los comentarios, nunca las notas de los demás ni las alertas internas. Se libera cuando todo el grupo presentó y se entrega en un ZIP (un HTML imprimible por estudiante) o por un enlace personal firmado que vence y se puede retirar. La plataforma registra quién lo recibió.
14. **Calificar abiertas con rúbrica.** La IA propone criterios con peso y niveles a partir de la pregunta y su respuesta de referencia, o de la descripción de una sustentación o un trabajo. El borrador se ajusta en un editor visual que avisa si los pesos no suman 100 %, o la rúbrica se arma a mano. Al calificar se elige un nivel por criterio y la plataforma calcula el puntaje. El estudiante ve en su informe qué nivel alcanzó en cada criterio, aunque después se edite la rúbrica.
15. **Atender reclamos sin repetir errores.** Se registra cada reclamo y se resuelve rechazándolo con una respuesta, anulando el ítem, corrigiendo la clave (se recalifica a todo el grupo, no solo al reclamante) o ajustando una nota. El registro de la materia guarda la corrección aplicada, y la IA recibe esos casos como errores que no debe repetir.
16. **Entregar las notas.** Excel con todas las notas, planilla CSV para Excel en español y respaldo completo en JSON.

Instalación: [`docs/instalacion.md`](docs/instalacion.md).

| Pieza | Dónde | Requerimientos |
|---|---|---|
| Modelo de datos completo: materia, dictado, divisiones anidables, grupos, estudiantes, artefactos, banco, actividades, entregas, notas, IA | `database/migrations`, `app/Models` | 2.6, 2.7, 9.1, 11.5 |
| Cálculo de notas exacto (sin `float`): promedio que ignora vacías, definitiva, aporte, estados, escala y redondeo configurables, conversión de escala | `app/Notas/CalculadoraNotas.php`, `Escala.php` | 3.1.6, 3.2 |
| Simulador de cierre: nota necesaria, «no alcanza», «aprueba por redondeo», al margen | `CalculadoraNotas::simularCierre` | 3.7 |
| Registro de notas con bitácora inmutable (triggers en BD), deshacer por operación, vacías a ceros reversible, eliminación con confirmación | `app/Notas/RegistroNotas.php` | 3.1.9, 3.5, 9.1 |
| Calificación híbrida: el servidor califica única, V/F, múltiple con crédito parcial, emparejar, ordenar y huecos; el navegador del docente reporta qué verificaciones de código pasaron y el servidor calcula el puntaje | `app/Calificacion` | 5.3, 6.1, 6.4, revisión A1 |
| Anulación de ítem: dar por bueno o retirar y reescalar | `CalificadorEntrega::nota` | 6.5.5 |
| Entrega grupal: la nota baja a todos, tope por tamaño de grupo, alerta de estudiante en dos entregas | `app/Calificacion/AsignacionGrupal.php` | 6.8 |
| Autenticación con Fortify: login con límite de intentos, segundo factor (TOTP + códigos de recuperación), cambio de contraseña, sin registro público | `config/fortify.php`, `resources/views/auth`, `/seguridad` | revisión A3 |
| Alta de materia en un paso con plantilla sugerida (universidad 30/30/40, colegio 4 periodos, libre) y dictado siguiente que hereda la estructura | `app/Dictados`, `/materias/crear` | 2.4, 2.6, 2.7, 2.8 |
| Cargue masivo con la plantilla oficial (XLSX o CSV con `,` o `;`, UTF-8 o Windows-1252): vista previa obligatoria, errores y advertencias por fila, reimportación sin duplicar ni borrar, plantilla descargable | `app/Estudiantes`, `/grupos/{grupo}/importar` | 3.4 |
| Registro de notas: pestaña por corte y consolidado, columnas dinámicas (crear, renombrar, mover, eliminar con confirmación), coma decimal, vacías a cero reversible, deshacer con el nombre de la acción, historial de cada nota (doble clic), marca de «aprueba solo por redondeo» | `app/Livewire/RegistroNotas.php`, `app/Notas/TableroNotas.php` | 3.1, 3.3, 3.5, 3.7.2 |
| Carga de notas desde archivo que crea una columna: cruce por documento y, como respaldo, por nombre completo (nunca por apellido solo), columna de grupo, conversión 0–10/50/100, columnas y escala corregibles en la vista previa, reporte de no cruzados, otro grupo, repetidas y fuera de rango; deshacible | `app/Notas/ImportadorNotas.php`, `/grupos/{grupo}/cargar-notas` | 3.1.5 |
| Motor de IA intercambiable: Ollama, servidores compatibles con OpenAI (LM Studio, llama.cpp, vLLM) y Claude en la nube con el SDK oficial | `app/IA` | 1.5 |

| Banco «plataforma-banco» v1 con validación (pesos, respuestas dentro de las opciones, fracciones de verificaciones) | `app/Evaluacion/ImportadorBanco.php`, `docs/formato-banco.md` | 5.3, 9.2.4, 9.2.5 |
| Examen autocontenido: sin respuestas dentro, autoguardado anunciado, salidas reales de ventana con duración, reloj, modo desconectado, entorno de prueba con consola y casos visibles en un Worker aislado, entrega cifrada (AES-256-GCM y RSA-OAEP-256) | `app/Evaluacion/GeneradorExamen.php`, `resources/examen/` | 4, 5.4, 5.5, 5.7 |
| Recepción de entregas: descifrado con la clave del periodo, examen reconocido por identificador, entrega en la actividad equivocada, identidad manipulada, otro grupo, duplicadas, alertas de sesión, código idéntico aunque se quiten comentarios | `app/Evaluacion/ProcesadorEntregas.php`, `docs/formato-entrega.md` | 6.1, 6.3, 6.11 |
| Calificador: planilla con indicadores, retos ejecutados en el navegador del docente, abiertas por pregunta con texto legible y resaltado de términos, detalle autoexplicativo, paso al registro (incluida la entrega grupal) | `app/Http/Controllers/CalificadorController.php`, `app/Livewire/CalificarAbiertas.php` | 6.2, 6.4, 6.6, 6.7, 6.8 |
| Generación del banco con IA: motores configurables (local u online, nunca cae en silencio a otro), repositorio de contenido con extracción de texto, prompts de tres capas versionados, prompt editable, borrador validado, corrección por IA, ajuste de pesos, aprobación explícita y trazabilidad | `app/Evaluacion/GeneradorBanco.php`, `app/IA/`, `/materias/{materia}/generar` | 1.5, 7, 7.3, 7.4, 8 |
| Plan curricular y temario: extracción con IA como borrador, editor manual, resultados de aprendizaje, sugerencias por tema que el docente toma o descarta, lista de chequeo por grupo y alcance real en la capa 2 del prompt | `app/Temario/ServicioTemario.php`, `app/Livewire/EditorTemario.php`, `app/Livewire/ListaChequeo.php` | 7.1 |
| Estadísticas y análisis de ítems: resumen del grupo, distribución por rangos, acierto, discriminación (27 % superior e inferior), distribución de opciones, candidatos a revisión, vista para proyectar, CSV, anulación de ítem con reproceso de todos los grupos y desempeño por tema en la lista de chequeo | `app/Calificacion/EstadisticasActividad.php`, `app/Calificacion/DesempenoTemas.php` | 6.5.4, 6.5.5, 6.10 |
| Supletorios, recuperaciones y habilitaciones: eventos que no se editan ni se borran (se anulan con motivo), reglas reemplaza, promedia o tope, simulación previa, marcas S, R y H en el registro con la nota original, lista del periodo, bitácora y respaldo | `app/Notas/SegundasOportunidades.php`, `app/Http/Controllers/SegundaOportunidadController.php` | 3.6, 3.7.4 |
| Informe individual y devolución: detalle autoexplicativo compartido con la vista del docente, nota vigente y la original opcional, liberación explícita con aviso de pendientes, ZIP de informes autocontenidos, enlaces firmados con vencimiento y revocación, registro de apertura o entrega | `app/Calificacion/InformeEstudiante.php`, `app/Http/Controllers/DevolucionController.php` | 3.6.5, 6.5, 6.9 |
| Unidades didácticas: generador con IA desde el temario (temas, resultados, sugerencias, alcance del grupo, memoria de lo ya planeado), editor por sesiones con errores y advertencias, vista imprimible y enlace al generador de quices por sesión | `app/Planificacion/UnidadesDidacticas.php`, `app/Planificacion/GeneradorUnidad.php` | 7.1, 7.3, 7.4 |
| Rúbricas: generador con IA (prompt editable, borrador, corrección), editor visual a mano, asociación a preguntas abiertas, calificación por niveles con puntaje calculado y detalle por criterio en el informe | `app/Evaluacion/Rubricas.php`, `app/Evaluacion/GeneradorRubrica.php` | 6.7.11, 7.4 |
| Reclamos: registro, rechazo con respuesta, anulación, corrección de clave con recalificación de todo el grupo, ajuste individual, registro por materia, marca en el análisis de ítems, respuesta en el informe del estudiante y memoria para la IA | `app/Calificacion/Reclamos.php`, `app/Calificacion/AjustesDeExamen.php` | 6.5.4, 6.5.6 |
| Exportación: Excel con una hoja por corte y planilla; CSV con `;` y BOM; un periodo incompleto no sale como nota final | `app/Notas/ExportadorNotas.php` | 3.1.11, 3.1.14 |
| Cierre: simulador de lo que necesita cada estudiante (meta redondeada hacia arriba), al margen, aprobados por vacía o por redondeo, revisión de ceros y vacías | `app/Notas/CierrePeriodo.php`, `/grupos/{grupo}/cierre` | 3.7 |
| Edición de estudiante y retiro del grupo sin perder notas | `app/Http/Controllers/EstudianteController.php` | 3.4.5, 3.4.6 |
| Respaldo JSON del dictado con estructura, estudiantes, notas y bitácora | `app/Dictados/RespaldoDictado.php` | 3.1.10 |

### Siguientes fases

- **Adaptador del calificador HTML anterior**, para migrar las entregas del corte en curso. Hace falta un archivo de entrega real y el calificador actual.
- Retos con DOM (HTML y CSS) y vista previa: hoy los retos son de JavaScript puro, en un Worker.
- Versiones A/B y segundo banco, modo de ensayo con las pruebas de la sección 9.2 en pantalla, anulación de ítems y plantillas de comentarios desde la interfaz.
- Más generadores del catálogo 7.4 (ejemplos prácticos, preguntas de debate, ideas de proyectos), envío de los enlaces por correo institucional, corrección de verificaciones de retos de código y restaurar un respaldo.

### Reglas que el código garantiza

- **Con datos de estudiantes, solo motor local.** Sin autorización explícita (`IA_PERMITIR_NUBE_CON_DATOS_ESTUDIANTES=true`), esas tareas se niegan a usar un motor en la nube.
- **Nunca se cae en silencio a otro motor.** Si el motor configurado no responde, la aplicación avisa y se detiene.
- **El solucionario no se filtra.** `solucionario`, `clave`, `api_key` y la clave privada se guardan cifrados (cast `encrypted`) y están ocultos en la serialización.
- **El navegador no puede inventar puntajes.** Las verificaciones y sus valores viven en el servidor. Si el navegador envía una verificación que sobra o falta, el resultado se rechaza.

## Desarrollo

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed      # datos de demostración: demo@ejemplo.edu.co / demo-plataforma-2026
php artisan serve                     # http://127.0.0.1:8000
php artisan test
```

### Motor de IA local (recomendado)

1. Instale [Ollama](https://ollama.com) en su equipo o en un servidor del programa y descargue un modelo, por ejemplo `ollama pull qwen3:14b`. Con menos memoria sirve un modelo de 7 u 8 B, aunque comete más errores de formato.
2. En **Motores de IA** agregue el motor con la dirección `http://127.0.0.1:11434` y el nombre del modelo, y pulse **Probar**.
3. Con un modelo local una generación puede tardar varios minutos. En WAMP, suba `max_execution_time` en `php.ini` (o use `IA_TIEMPO_MAXIMO` en `.env`).

## Próximos pasos

1. Adaptador de entregas del calificador HTML anterior.
2. Más generadores del catálogo 7.4: ejemplos prácticos resueltos paso a paso y preguntas de debate, que reutilizan el mismo flujo de borrador.
