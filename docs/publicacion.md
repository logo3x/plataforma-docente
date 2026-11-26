# Publicación institucional del material

Cada material de la materia (textos y apuntes, ejemplos prácticos, preguntas de debate, vocabulario e ideas de
proyectos) tiene el botón **Publicar** (requerimiento 7.8, decisión pendiente 2). Se publica la **versión para
estudiantes** —sin respuestas, soluciones, menús ni avisos internos— con su **ficha de metadatos**.

| Formato | Para qué | Qué trae |
|---|---|---|
| Paquete SCORM 1.2 | Moodle y otras plataformas institucionales | `imsmanifest.xml` con los metadatos en LOM e `index.html`. Al abrirlo, la plataforma lo registra como «incompleto»; al llegar al final o con «Marcar como leído», como «completado». |
| HTML autocontenido | Una página, un correo o un repositorio | Un solo archivo con los estilos dentro, metadatos Dublin Core en el encabezado y la ficha al final. Funciona sin internet. |
| Documento de Word | Seguir editándolo | Títulos, párrafos, listas y código con estilos de Word, la ficha al final y el título, autor y palabras clave en las propiedades del documento. |
| PDF | Imprimir o enviar | Con «Imprimir o guardar en PDF» en la versión para estudiantes. |

SCORM 1.2 se eligió por ser el que acepta cualquier versión de Moodle y casi cualquier plataforma; un paquete
SCORM también se puede abrir directamente en un navegador, sin plataforma.

## La ficha de metadatos

La ficha se basa en Dublin Core y en el núcleo de LOM (IEEE 1484.12.1): título, descripción, autor, institución,
programa, asignatura, palabras clave, nivel, resultados de aprendizaje, tiempo de estudio, idioma, licencia y
versión. La plataforma la propone con lo que ya sabe y recuerda la última que se usó para cada material.

**Si la institución tiene su propia plantilla de ficha**, se reemplaza la lista `campos` de
`config/publicacion.php`: cada campo con su etiqueta, si es obligatorio, su tipo (`texto`, `area` o `lista`) y,
si corresponde, el elemento de LOM o de Dublin Core al que va. La institución y la licencia por defecto se
cambian con `PUBLICACION_INSTITUCION` y `PUBLICACION_LICENCIA` en el `.env`.

Cada publicación queda registrada: formato, versión, fecha y la ficha que se usó.
