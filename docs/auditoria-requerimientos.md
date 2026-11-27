# Auditoría de trazabilidad: requerimientos v3 contra el código

Cada punto numerado de las secciones 2 a 9 y de los anexos se comparó con el código (controladores, servicios, vistas y pruebas), sin dar por buena la tabla del README. Aquí solo figura lo que falta o está a medias. Lo aplazado o descartado en la sección 11.1 no se cuenta.

Estado: ✅ corregido · 🔧 pendiente, sin decisiones abiertas · ❓ necesita una decisión del docente

## Corregido a raíz de la auditoría

| Req. | Qué era | Estado |
|---|---|---|
| 4.5.3 | El código JavaScript del estudiante podía salir a internet desde el examen: la función de red seguía en el prototipo del Worker y la política permitía todo `https:`. Comprobado en Chromium | ✅ Worker sin red en toda la cadena de prototipos; la política solo permite la sonda |
| 4.5.3 | El mismo hueco en «Ejecutar retos», que corre el código de los estudiantes en el navegador del docente con su sesión abierta. Comprobado | ✅ Mismo bloqueo, más una política `connect-src 'self'` en toda la plataforma |
| 6.8.7 | La nota de un reclamo aceptado o corregida a mano se perdía al volver a pasar las notas o al anular un ítem | ✅ Se conserva y se informa |
| 7.11 | La pantalla prometía descargar el material original y no había cómo; borrar un material podía borrar el archivo de otra carga igual | ✅ |
| 6.7 | Faltaban el modo por estudiante, el teclado, el umbral de respuesta corta y la exportación de abiertas | ✅ |
| 9.3 | Líneas de 115 caracteres en las abiertas; cuatro problemas de accesibilidad; el informe fallaba con una respuesta sin desglose | ✅ |

## Pendiente, sin decisiones abiertas

| Req. | Qué falta | Tamaño |
|---|---|---|
| 6.2.1, 6.2.2, 6.2.4 | Planilla: columna de documento, indicador de reprobados, filtros por estado y por alerta | pequeño |
| 3.1.4 | Mover una actividad a otro componente | pequeño |
| 3.1.13, 6.6.2 | Las alertas no se ven junto al estudiante en el registro | mediano |
| 3.5.4 | Exportar la bitácora de una nota o de un estudiante como soporte de un reclamo | pequeño |
| 3.7.1 | El simulador de cierre no calcula la nota mínima que necesita en la actividad pendiente (solo en el periodo) | mediano |
| 3.7.9 | En la revisión de ceros, cruzar con la entrega grupal (la pareja tiene nota y él no) | pequeño |
| 4.1, 4.2.3 | Errores de JavaScript sin línea ni columna en la consola del estudiante; los errores asíncronos se pierden | mediano |
| 4.2.4 | Referencias circulares anidadas se muestran como `[object Object]` | pequeño |
| 5.4.3 | Las opciones de única, múltiple y emparejar salen en el mismo orden para todos | pequeño |
| 5.4.9 | Tiempo por ítem; la portada no declara todo lo que se registra (pegados, ejecuciones, navegador) | mediano |
| 5.7 | Modo desconectado: no deja iniciar con internet y pausa el reloj al volver la conexión | pequeño |
| 6.5.4 | Al prosperar un reclamo, poder corregir la verificación de un reto o la clave de emparejar u ordenar | mediano |
| 6.5.6 | Las anulaciones hechas desde Estadísticas no aparecen en el registro de reclamos | pequeño |
| 6.8.6 | El informe no dice quién subió la entrega grupal ni explica el tope aplicado | pequeño |
| 6.10.6 | Estadísticas dentro de la exportación del corte | pequeño |
| 6.11.1 | La similitud no compara el texto de los archivos entregados, solo el hash | mediano |
| 6.1.4 | Aviso de un archivo subido a la actividad equivocada (hoy solo para exámenes) | mediano |
| 2.8.3 | Lo copiado no guarda el vínculo con su origen | pequeño |
| 7.2 | Asignar el material del repositorio a una unidad o tema y elegirlo así al generar | mediano |
| 8.4.4 | Ver y restaurar versiones anteriores de una plantilla de prompt; el estilo de la materia se sobrescribe sin versiones | pequeño |
| 8.4.1 | Validar al guardar que la plantilla no use campos que no existen | pequeño |
| 7.6 | Historial de versiones del material y de los bancos editados | mediano |
| 7.8 | Exportar rúbricas y unidades didácticas a HTML y Word | pequeño |
| 9.1 | La versión del contenido no sube al cargar de nuevo el mismo material | pequeño |

## Necesita una decisión del docente

| Req. | Pregunta |
|---|---|
| 2.7.1, 2.7.2, 3.1.2 | Cortes y componentes no se pueden editar después del alta. ¿Se permite cambiar nombres y pesos con notas ya cargadas, o solo mientras el periodo está vacío? |
| 2.7.4 | Divisiones anidadas (cortes dentro de cortes): el modelo las soporta, la interfaz no. ¿Hacen falta? |
| 2.6.2 | Fechas y calendario del dictado: ¿solo inicio y fin de cada corte, o también las fechas de clase? |
| 3.2 | Nota del parcial como 50 % escrita y 50 % práctica: hoy es la proporción de puntos. ¿Se calcula por partes, con el peso configurable? |
| 3.2.1, 3.2.3 | Escala, nota mínima y regla de redondeo no se cambian después del alta. ¿Se deben poder cambiar? |
| 6.2.3, 6.4 | Modalidad II: nota de la práctica digitada con su comentario y requerimientos con su fracción. ¿Cómo llega hoy esa nota (anexo calificado aparte)? |
| 6.8.1 a 6.8.3 | Grupos de trabajo definidos por el docente y elegidos de la lista, en vez de documentos escritos por el estudiante. ¿Se arman antes del parcial en sala? |
| 4.4.3, 4.4.5 | Editor de código con números de línea, resaltado y marca del error en la línea. Requiere incluir un editor (unos 300 KB dentro del examen sin internet). ¿Vale la pena? |
| 4.4.1 | Avisar del punto y coma faltante: JavaScript no lo exige y avisarlo puede confundir. ¿Se pide como estilo? |
| 5.2 | Enunciado de la práctica descargable en texto plano, con fracciones y números de autoverificación |
| 5.4.13, 5.4.14 | Historial de uso por pregunta: avisar y excluir preguntas que un grupo ya vio; equilibrio por tema y dificultad |
| 5.7 | Panel: interruptores de versiones, segundo banco, cantidad de casos reservados; formato de entrega «artefacto autocontenido» |
| 7.4.3 | Catálogo extensible: hoy cada generador nuevo requiere código. ¿Un generador genérico a partir de una plantilla de prompt? |
| 7.5, 7.10 | Manuales interactivos con el entorno de prueba, guías y boletines |
| 7.9 | Copiar material, contenido y plantillas a otra materia |
| 8.4.5 | Biblioteca de plantillas que funcionaron, reutilizable entre materias |
| 8.5.3 | Perfil de escritura del docente con muestras de sus comentarios para la IA |
| 9.2 | Pruebas 8 y 9 automáticas (entregable modelo frente a uno con fallas) y exigir el ensayo antes de aplicar |
