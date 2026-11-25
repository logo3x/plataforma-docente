# Revisión del documento de requerimientos v3 — Plataforma educativa

Revisión hecha antes de programar. Busca contradicciones internas, vacíos que bloquean el diseño y requerimientos difíciles de probar. Cada hallazgo cita la sección y propone una corrección concreta.

**Resumen:** el documento es sólido. Las reglas nacen de casos reales y casi todas se pueden verificar. Antes de la fase 0 hay que cerrar tres cosas, porque cambian el modelo de datos o la arquitectura:

1. Dónde se ejecuta el código del estudiante al calificar (hallazgo A1).
2. Dónde se guarda la clave privada (hallazgo A2).
3. A qué nivel pertenecen la estructura de cortes y la identidad del estudiante, materia o dictado (hallazgos B1 y B2).

---

## A. Vacíos de arquitectura (bloquean la fase 0)

### A1. El calificador en Laravel no puede ejecutar JavaScript del estudiante tal como está escrito

- **Dónde:** 1.3 regla 3, 6.1.6 y 9.3 (rendimiento del calificador).
- **Problema:** las reglas de calificación se definen «una sola vez en el servidor», y el calificador debe «ejecutar el código entregado contra las verificaciones, aislado, incluyendo los casos reservados». PHP no ejecuta JavaScript ni manipula el DOM. Hoy esto funciona porque el calificador es un HTML que corre en el navegador del docente. Al pasar a Laravel, esa capacidad se pierde si no se decide explícitamente.
- **Opciones:**
  - **a)** **Calificación híbrida (recomendada para la v1):** Laravel descifra y califica lo determinista (selección, emparejamiento, huecos, orden). Los retos de código se ejecutan en el **navegador del docente**, dentro de una página del calificador que reutiliza el mismo entorno de prueba de la sección 4 (iframe aislado). El resultado se devuelve al servidor. Se aprovecha lo que ya está probado.
  - **b)** **Ejecución en servidor** con Node aislado (contenedor sin red, con límite de tiempo y memoria) más jsdom para las aserciones sobre el DOM. Es más robusto para la fase 13, pero agrega infraestructura y un vector de seguridad: se ejecuta código no confiable en el servidor.
- **Qué hacer:** registrar la decisión en la sección 11 y ajustar la regla 3 de 1.3. Lo que se define una sola vez es la *especificación* de la calificación (JSON de verificaciones). Los motores que la ejecutan pueden ser dos: PHP para lo determinista y el navegador o Node para el código.

### A2. Custodia de la clave privada y del solucionario

- **Dónde:** 1.3 regla 1, 5.4.6, 6.1.3 y 6.7 (restricciones).
- **Problema:** hoy «la clave privada y el solucionario residen únicamente en el archivo del docente». En la v3, Laravel es la única fuente de verdad, así que la clave pasa a vivir en el servidor. El documento no dice cómo se protege. Además, **un par de claves único para todos los exámenes** (6.1.3) hace que una sola filtración exponga todas las entregas, pasadas y futuras.
- **Propuesta:**
  - Guardar la clave privada cifrada en reposo, con el cast `encrypted` sobre `APP_KEY`, o mejor con una frase de paso del docente.
  - Un par de claves **por dictado**: mantiene la comodidad de la autodetección por sigla sin el radio de impacto global.
  - Solucionarios cifrados en la base de datos.
  - Rotación de la clave documentada.
  - Bitácora de quién descifró qué y cuándo.

  Es coherente con un SGSI ISO 27001, en particular con los controles de criptografía y de gestión de claves.

### A3. Falta un apartado de seguridad y protección de datos del servidor

- **Dónde:** 9.3.
- **Problema:** con la v3 aparece un servidor que guarda notas e identificación de estudiantes. El documento no trata autenticación, roles, copias de seguridad del servidor, retención y borrado, ni el marco legal. En Colombia aplica la **Ley 1581 de 2012** (habeas data) y su decreto reglamentario. Tampoco dice si la institución ya tiene una política de tratamiento; el Drive del docente sugiere que existe un formato de autorización de la institución.
- **Propuesta:** una sección 9.4 con:
  - Autenticación del docente, con 2FA recomendado.
  - Registro de accesos.
  - Copias de seguridad cifradas.
  - Política de retención: cuánto tiempo se guardan notas y entregas, y qué se borra al cerrar el periodo.
  - Referencia a la política de tratamiento de datos institucional.
  - Inventario de los datos personales tratados (ver B6).

---

## B. Contradicciones internas

### B1. La estructura de cortes y pesos aparece en la materia y en el dictado

- 3.1.2: «Cortes con su peso en el semestre, configurables **por materia**».
- 2.4.1: el alta de materia incluye «grupos, cortes y pesos».
- 2.6, 2.7 regla 5 y 9.1: los cortes, sus fechas y la lista **pertenecen al dictado**.

**Corrección:** la materia guarda una *plantilla* de estructura de evaluación (tipos de componente y pesos sugeridos). El dictado guarda la estructura real, copiada de la plantilla y editable. Hay que reescribir 3.1.2 y 2.4.1 en ese sentido. El mismo ajuste aplica a 3.1.1 («materia → grupos → estudiantes»), que debe decir «materia → dictado → grupos → estudiantes».

### B2. Unicidad del documento: ¿por materia o por dictado?

- 3.4, tabla, fila `numero_documento`: «único **dentro de la materia**».
- 9.1, reglas de integridad: «único **dentro de cada dictado**, no de la materia».

**Corrección:** dejar «por dictado», que es lo correcto porque el estudiante repite la materia en otro periodo, y corregir la tabla de 3.4.

### B3. La escala de notas sigue fija en varios lugares

La sección 3.2 hace la escala configurable, pero estos puntos la siguen dando por fija:

- 3.7.2: «no alcanza ni con **5.0**».
- 6.8, punto 7.1: «máximo de **4.0** en la escala 0–5».
- 6.10.1: «**Escala 0.0 a 5.0**».
- 3.2: «nota del parcial = promedio **50/50** de escrita y práctica».

**Corrección:** expresar los tres primeros contra `nota_maxima` del dictado (el tope de 6.8, como porcentaje del máximo o como valor en la escala del dictado). La regla 50/50 debe ser un parámetro de la actividad, con 50/50 por defecto. Una materia teórica no tiene parte práctica y no le aplica (1.4).

### B4. Similitud entre entregas: requerimiento o aplazada

- 6.11: pasó a **requerimiento** tras el corte 3.
- 6.7, «Fuera de alcance»: la comparación de similitud entre respuestas abiertas «pasa a decisiones pendientes, punto 9».
- 11.9: dice que dejó de estar aplazada.

**Corrección:** quitar la mención de 6.7, o aclarar que la iteración sobre la *herramienta HTML actual* no la incluye pero el módulo 3 en Laravel sí.

### B5. SCORM: fuera del núcleo pero todavía exigido

- 11.1: la exportación SCORM sale del núcleo.
- 7.8 y 7.2.4: piden exportar a «paquete SCORM».
- 10, fase 7: la incluye en el orden de construcción.

**Corrección:** marcar 7.8 y 7.2.4 como «fase posterior (ver 11.1)» y renombrar la fase 7.

### B6. Datos mínimos frente a la plantilla de secretaría

- 9.3: «Nombre, documento y notas. Nada más».
- 3.4: además importa email, teléfono, número de matrículas, convocatorias y observación.

**Corrección:** actualizar 9.3 con el inventario real de datos y la finalidad de cada uno. También conviene evaluar si `telefono` y `observacion` son necesarios. Si no lo son, la importación puede descartarlos. Principio de minimización.

### B7. Banco de preguntas: ¿núcleo o fase 13?

- 5.4.13 y 5.4.14: banco etiquetado por tema y dificultad, con historial de uso, en el núcleo.
- 13.5: «Banco de preguntas administrable desde la interfaz» como fase posterior.

**Corrección:** precisar que el banco y su historial son núcleo, y que lo de la fase 13 es otra cosa (por ejemplo, edición colaborativa o generación A/B en vivo).

### B8. El orden de construcción tiene una dependencia invertida

- 10: la fase 1 es el registro de notas y la fase 2 es «materias, grupos, cortes… cargue masivo».
- El registro de notas no se puede usar sin materia, dictado, cortes y lista de estudiantes. La misma tabla lo reconoce: la fase 2 es «el esqueleto del que dependen las demás».

**Corrección:** invertir las fases 1 y 2, o fusionarlas. La migración del corte en curso (último párrafo de 10) también necesita la lista cargada antes.

### B9. Numeración duplicada

- 6.7: el punto **14** aparece dos veces («Puntajes y comentarios se conservan…» y «Además del CSV…»), y la exportación sigue con el 15.
- 6.8: un punto «7.1» incrustado dentro de una lista numerada.

Como el documento cita requerimientos por número (6.7.13, 5.4.9…), conviene renumerar sin huecos. Otra opción son identificadores estables tipo `RF-NOT-014`, que no cambian al insertar puntos.

### B10. Fecha de la versión

El encabezado dice «Versión 3 (septiembre de 2026)», pero la bitácora tiene entradas de noviembre de 2026 incorporadas a la v3 (6.8, 3.7, 6.11, 1.5…). Hay que actualizar la fecha o subir a la v3.1.

---

## C. Requerimientos ambiguos o difíciles de probar

| Sección | Texto | Problema | Propuesta |
|---|---|---|---|
| 6.5.5 | Recalcular «repartiendo o descontando su valor según la regla elegida» | Las reglas no están definidas | Enumerar dos: **(a)** el ítem se da por bueno a todos; **(b)** el ítem se retira y los demás se reescalan al valor total. Dar un ejemplo numérico de cada una |
| 3.6.2 | Regla de reemplazo «reemplaza, promedia o tope» | No dice a qué nivel aplica la habilitación: actividad, corte o materia | Fijar los tres ámbitos posibles y la regla por ámbito, con un ejemplo |
| 3.7.2 | «Aprueba solo por redondeo» | Depende de la regla de redondeo y de si se redondea por corte o solo la definitiva | Declarar en qué punto del cálculo se redondea (sugerido: solo la nota mostrada, como dice 3.2.3) y derivar el caso de ahí |
| 7.1.2 | La aplicación «lee el plan, extrae el temario **y lo entiende**» | No se puede verificar | Criterio: «a partir del plan de prueba X, extrae ≥ 90 % de los temas listados; el docente corrige el resto en la vista editable» |
| 7.1.3 | «Sugiere contenido» | No dice qué produce ni en qué formato | Definir la salida: lista ordenada de temas con material sugerido y actividad sugerida por tema |
| 6.11.2 | Similitud «robusta a renombrar variables y quitar comentarios» | Sin umbral ni método | Fijar la técnica (normalizar tokens + *winnowing*, como MOSS) y un criterio con un par de prueba: «una copia con variables renombradas y sin comentarios supera el umbral; dos soluciones independientes al mismo reto no» |
| 6.3 | Pegados de texto «en los editores de código» | En materias teóricas, el pegado en respuestas abiertas es igual de relevante | Extender a todo campo de texto libre, configurable por actividad |
| 4.5.3 | «Sin acceso a red desde el código ejecutado» | En el navegador no basta con un iframe | Exigir `sandbox="allow-scripts"` sin `allow-same-origin`, más una CSP `connect-src 'none'` en el `srcdoc`. Criterio: un `fetch` del estudiante falla |
| 9.3 | «Hasta 6 materias por semestre» | Contradice el alcance de colegio (1.4), donde un docente puede tener más cursos | Dejarlo como objetivo de rendimiento, no como límite funcional |
| 11.5 | Estudiantes en dos grupos, pendiente | Bloquea el modelo de datos | Resuelto en el diseño propuesto: el estudiante pertenece al dictado y se vincula a uno o varios grupos por tabla pivote |

---

## D. Observaciones menores

- **8.1, idioma: «sin tildes en el contenido de los materiales».** Si nació de problemas de codificación en los HTML generados, en la aplicación Laravel ya no hace falta: todo va en UTF-8 con `<meta charset="utf-8">`. Vale la pena revisar si la regla sigue siendo necesaria, porque afecta la ortografía del material para los estudiantes.
- **Tensión menor con la lección 12:** «toda actividad digital necesita su respaldo en papel» choca con el principio 1.2, «el papel es contingencia». Sugerencia: «toda actividad digital debe *poder* generar su respaldo en papel».
- **Primera vez que se usa «sigla»** (6.1.3): conviene definir su formato, por ejemplo `ED-2026A-P2-A`, porque sirve como identificador en el cifrado, en el calificador y en la detección de la actividad equivocada.
- **5.4.3, barajado derivado del documento:** es determinista y reproducible, lo cual está bien. Conviene derivarlo de `hash(documento + sigla + sal_del_artefacto)` y no solo del documento, para que dos exámenes distintos no compartan el mismo orden.
- **1.5 regla 4 (no caer en silencio a la nube):** está bien planteada. Conviene añadir la regla inversa explícita: cuando una tarea procesa datos de estudiantes y el motor configurado es en la nube, la aplicación pide confirmación o se niega, según la configuración.

---

## Checklist para la v3.1

- [ ] A1 Decidir dónde se ejecuta el código al calificar
- [ ] A2 Custodia de claves: par por dictado y privada cifrada en reposo
- [ ] A3 Sección 9.4 de seguridad y Ley 1581
- [ ] B1 Plantilla de evaluación en la materia y estructura real en el dictado
- [ ] B2 Documento único por dictado (corregir 3.4)
- [ ] B3 Escala parametrizada en 3.7, 6.8 y 6.10; regla escrita/práctica configurable
- [ ] B4 Similitud: quitar la contradicción de 6.7
- [ ] B5 SCORM fuera de 7.8, 7.2.4 y fase 7
- [ ] B6 Inventario de datos personales en 9.3
- [ ] B7 Banco de preguntas: núcleo frente a fase 13
- [ ] B8 Invertir o fusionar las fases 1 y 2
- [ ] B9 Renumerar 6.7 y 6.8, o adoptar IDs estables
- [ ] B10 Fecha de la versión
- [ ] C Criterios medibles para 6.5.5, 3.6.2, 7.1.2, 6.11.2
