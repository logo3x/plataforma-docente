# Verificación de los requerimientos no funcionales (9.3)

Medido con la base de demostración y en las pruebas automáticas. Para repetirlo, vea «Cómo se midió».

| Requerimiento | Criterio | Resultado | Cómo se verifica |
|---|---|---|---|
| Rendimiento del calificador | 40 entregas en menos de 2 minutos | **1,7 s** para descifrar, identificar, alertar y calificar en el servidor 40 entregas subidas de una vez. Los retos de código de esas 40 entregas, ejecutados en el navegador del docente: **62 s**, con 10 de las 40 en el peor caso (un ciclo infinito que agota el límite de tiempo en cada verificación) | Prueba `cuarenta_entregas_se_procesan_en_menos_de_dos_minutos` (falla si pasa de 120 s); medición en el navegador con 40 entregas |
| Abiertas: cambio de modo | Por estudiante ↔ por pregunta en menos de 1 s con 40 entregas | **0,09 a 0,12 s** | Medición en el navegador (Chromium, 1366 × 768) |
| Abiertas: texto del estudiante | 15 px o más; máximo 90 caracteres por línea | **15 px; máximo 88** caracteres medidos sobre el texto renderizado | Se cuentan los caracteres de cada línea visible de la respuesta más larga |
| Abiertas: legible en 1366 × 768 | Sin desplazamiento horizontal | Sin desplazamiento en ninguna página | Ancho del documento igual al de la ventana en las 89 páginas recorridas |
| Accesibilidad | Teclado, contraste de 4,5 a 1, etiquetas asociadas, ningún estado solo por color | **0 violaciones** de WCAG 2.1 A y AA (axe-core 4) en 89 páginas del docente, el examen (portada y en curso, con el editor de código en JavaScript, SQL y PHP), la entrada a la sesión en vivo y el informe del estudiante | Recorrido automático de todas las páginas enlazadas desde «Mis materias», una por cada tipo de ruta |
| Datos del estudiante | Nombre, documento y notas | Además de esos, se guardan los datos que trae la plantilla de secretaría: matrículas, convocatorias, observación, correo y teléfono. El 3.4.10 los admite como contacto operativo, siempre que no aparezcan en vistas que se proyectan en el aula. Solo se muestran en el formulario de edición de cada estudiante; el registro, el panel en vivo y la proyección no los muestran. El correo sirve además para la devolución y para cruzar con Classroom | Búsqueda de `email` y `telefono` en todas las vistas |
| Respaldo | Exportación completa en cualquier momento | Respaldo JSON por periodo y restauración como periodo nuevo | Pruebas de `RestauracionTest` |
| Sin servidor en el aula | El examen corre desde el archivo | El examen es un HTML autocontenido que no pide nada a la red | Prueba de que el examen no tiene recursos externos ni respuestas |

## Lo que se corrigió al medir

- Las líneas del texto del estudiante llegaban a 115 caracteres: el ancho estaba en `90ch`, y `ch` es el ancho del «0», más ancho que la letra promedio. Quedó en `68ch`.
- Contraste insuficiente en el pie de las diapositivas (también en el PowerPoint), y en tres botones el nombre accesible no empezaba con el texto visible, lo que confunde a quien usa control por voz.
- El selector de versión nueva del banco estaba dentro de la lista de pestañas.
- El detalle y el informe fallaban con una respuesta sin desglose; ahora muestran el puntaje sin la explicación.

## Cómo se midió

- Servidor: `php artisan serve` en el equipo de desarrollo, base SQLite.
- Navegador: Chromium sin interfaz (Playwright), ventana de 1366 × 768.
- Accesibilidad: axe-core 4 con las reglas `wcag2a`, `wcag2aa`, `wcag21a` y `wcag21aa`.
- 40 entregas reales: sobres cifrados con la clave del periodo, procesados por el mismo `ProcesadorEntregas` que recibe los archivos, con respuestas abiertas de 40 a 160 palabras y cuatro variantes de código (correcto, con `for`, incompleto y con un ciclo infinito).
