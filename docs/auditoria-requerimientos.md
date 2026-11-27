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

## Pendientes sin decisión: todos corregidos

| Req. | Qué faltaba | Estado |
|---|---|---|
| 6.2.1, 6.2.2, 6.2.4 | Planilla: documento, versión, reprobadas, filtros por estado y por alerta | ✅ |
| 3.1.4 | Mover una actividad a otro componente, con sus notas | ✅ |
| 3.1.13, 6.6.2 | Alertas junto al estudiante en el registro, con filtro | ✅ |
| 3.5.4 | Bitácora de un estudiante o de una nota en CSV | ✅ |
| 3.7.1 | Nota mínima en la actividad pendiente para aprobar el corte y el periodo | ✅ |
| 3.7.9 | Cero de quien entregó en pareja con alguien que sí tiene nota | ✅ |
| 4.1, 4.2.3 | Línea y fragmento del error de JavaScript; errores asíncronos | ✅ |
| 4.2.4 | Referencias circulares anidadas en la consola | ✅ |
| 5.4.3 | Opciones barajadas por estudiante (salvo «todas las anteriores») | ✅ |
| 5.4.9 | Tiempo por pregunta y registro de la sesión declarado en la portada | ✅ |
| 5.7 | Modo desconectado: no inicia con internet y pausa el reloj | ✅ |
| 6.5.4 | Corregir la clave de emparejar y ordenar, y las verificaciones de un reto | ✅ |
| 6.5.6 | Anulaciones en el registro de reclamos y en los errores que evita la IA | ✅ |
| 6.8.6 | Quién subió la entrega grupal y el tope explicados en el informe | ✅ |
| 6.10.6 | Estadísticas en el Excel del grupo | ✅ |
| 6.11.1 | Similitud del texto de los archivos entregados | ✅ |
| 6.1.4 | Aviso de un archivo ya entregado en otra actividad | ✅ |
| 2.8.3 | Las copias guardan el vínculo con su original | ✅ |
| 7.2 | Material asignado a unidad o tema y elegido por tema al generar | ✅ |
| 8.4.4 | Ver y volver a versiones anteriores de una plantilla | ✅ |
| 8.4.1 | Validar al guardar los campos de una plantilla | ✅ |
| 7.6 | Historial de versiones de material, rúbricas, unidades y bancos | ✅ |
| 7.8 | Rúbricas y unidades en HTML y Word; tablas reales en Word | ✅ |
| 9.1 | Versión del material al cargarlo de nuevo | ✅ |

## Decidido por el docente y hecho

| Req. | Decisión | Estado |
|---|---|---|
| 2.7.1, 2.7.2, 3.1.2 | Cortes y componentes se editan después del alta, aunque haya notas | ✅ Nombres, pesos, fechas, divisiones y componentes nuevos; no se quita lo que tiene actividades; cada cambio queda registrado con cómo estaba |
| 3.2 | Nota del parcial por partes, según el profesor | ✅ Opción por examen: por puntos (como antes) o por partes, con el peso de la escrita que fije el docente; la cuenta se explica en el detalle y en el informe |
| 6.8.1 a 6.8.3 | Grupos de trabajo: las dos vías | ✅ El docente los arma (por defecto sin conexión, con reparto al azar) o el estudiante escoge de la lista por nombre; el archivo no lleva los documentos del curso |
| 4.4.3, 4.4.5 | Incluir el editor de código en el examen | ✅ CodeMirror dentro del examen: 440 KB con JavaScript y 630 KB con los demás lenguajes (más de los 300 KB estimados); solo en exámenes con retos |

## Necesita una decisión del docente

| Req. | Pregunta |
|---|---|
| 2.7.4 | Divisiones anidadas (cortes dentro de cortes): el modelo las soporta, la interfaz no. ¿Hacen falta? |
| 2.6.2 | Fechas y calendario del dictado: ¿solo inicio y fin de cada corte, o también las fechas de clase? |
| 3.2.1, 3.2.3 | Escala, nota mínima y regla de redondeo no se cambian después del alta. ¿Se deben poder cambiar? |
| 6.2.3, 6.4 | Modalidad II: nota de la práctica digitada con su comentario y requerimientos con su fracción. ¿Cómo llega hoy esa nota (anexo calificado aparte)? |
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
