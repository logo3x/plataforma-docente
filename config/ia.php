<?php

return [
    /*
     * 1.5.3 — Preferencia por lo local cuando hay datos de estudiantes. En false (por defecto),
     * cualquier tarea marcada con datos de estudiantes se niega a usar un motor en la nube.
     */
    'permitir_nube_con_datos_estudiantes' => env('IA_PERMITIR_NUBE_CON_DATOS_ESTUDIANTES', false),

    /* Material del repositorio que cabe en un prompt. Con modelos locales la ventana es corta. */
    'max_contexto_caracteres' => (int) env('IA_MAX_CONTEXTO', 24000),

    /* Segundos que puede tardar una generación (los modelos locales pueden tardar minutos). */
    'tiempo_maximo' => (int) env('IA_TIEMPO_MAXIMO', 600),

    /*
     * Plantillas por defecto de las tres capas (sección 8). El docente las edita en
     * «Plantillas de IA»; cada cambio crea una versión nueva.
     */
    'plantillas' => [
        'contexto' => <<<'TXT'
Institución:        {{institucion}}
Programa:           {{programa}}
Docente:            {{docente}}
Ciudad:             {{ciudad}}
Idioma:             {{idioma}}
Escala de notas:    de {{nota_minima}} a {{nota_maxima}}, aprueba con {{nota_aprobatoria}}
Pesos del periodo:  {{pesos_cortes}}
Perfil del grupo:   {{perfil_estudiantes}}
Reglas de estilo:   {{reglas_escritura}}
Prohibido:          {{restricciones}}
TXT,
        'materia' => <<<'TXT'
Materia:               {{materia}}
Código:                {{codigo_materia}}
Unidades:              {{unidades}}
Alcance por grupo:     {{alcance_grupo}}
Lenguaje o técnica:    {{herramientas}}
Modalidad práctica:    {{modalidad}}
Tono:                  {{tono}}
Verificación:          {{tipo_verificacion}}
Vocabulario propio:    {{glosario}}
TXT,
        'banco' => <<<'TXT'
Tarea: genere una actividad evaluable para la plataforma, en el formato JSON «plataforma-banco» v1.

Tipo de artefacto:  {{tipo}}
Tema:               {{tema}}
Grupo:              {{grupo}}
Valor:              {{valor}} sobre {{nota_maxima}}
Cantidad de ítems:  {{cantidades}}
Debe incluir:       {{obligatorio}}
No debe incluir:    {{excluido}}

Reglas de contenido:
- Cíñase al material de referencia y al alcance del grupo. No evalúe temas que el grupo no ha visto.
- Cada enunciado práctico es una situación problema breve, no una definición técnica.
- Una sola respuesta defendible por pregunta cerrada; los distractores son plausibles pero claramente incorrectos.
- En «justificacion» escriba una línea que explique la respuesta correcta, como la diría el docente en clase.
- En los retos de código, «prueba» es una expresión de JavaScript que da exactamente true cuando el código es correcto,
  «nombre» describe en español lo que se comprueba, y al menos una verificación es reservada ("reservada": true).
  Las fracciones de las verificaciones de un reto suman 1.
- Si la materia trabaja HTML y CSS, el reto lleva "lenguaje":"html": el estudiante escribe una página, su
  «plantilla_codigo» es HTML, y cada «prueba» o «entrada» es una expresión de JavaScript sobre esa página
  (document.querySelector, getComputedStyle, .click()), por ejemplo
  "document.querySelectorAll('nav a').length === 3".
- Si la materia trabaja bases de datos, el reto lleva "lenguaje":"sql" y un «esquema» con los CREATE TABLE y los
  INSERT de unos datos realistas y breves (SQLite). Cada verificación trae una «consulta» de referencia cuyo
  resultado debe coincidir con el de la última consulta del estudiante ("orden": true solo si el enunciado pide
  ORDER BY), o una «prueba» que da 1 después de ejecutar su código (para INSERT, UPDATE o CREATE). Una
  verificación reservada puede traer «datos» propios, INSERT adicionales, para que no sirva escribir el
  resultado a mano.
- Si la materia trabaja PHP o Laravel, el reto lleva "lenguaje":"php" y NO se ejecuta: se revisa con criterios
  estáticos. «archivos» lista los archivos que escribe el estudiante ([{"nombre":"routes/web.php","plantilla":"<?php\n"}]).
  Cada verificación tiene un solo criterio: «patron» (expresión regular sin delimitadores, sobre el código sin
  comentarios; "ausente": true si NO debe aparecer), «contiene» (texto, sin importar espacios) o "sintaxis": true
  (el archivo se analiza sin errores). Use «archivo» para revisar uno solo. Su «nombre» dice en español qué se
  revisa («La ruta GET /productos llama a ProductoController@index»). En «alcance» diga qué NO se comprueba.
- Los pesos de todos los ítems deben sumar exactamente {{valor}}.
{{no_repetir}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-banco","version":1,"sigla":"{{sigla}}","titulo":"...","tipo":"{{tipo}}","valor":"{{valor}}",
 "items":[
  {"tipo":"unica","tema":"...","dificultad":"baja|media|alta","peso":"0.5","enunciado":"...","opciones":{"a":"...","b":"...","c":"...","d":"..."},"correcta":"b","justificacion":"..."},
  {"tipo":"vf","peso":"0.5","enunciado":"...","correcta":true,"justificacion":"..."},
  {"tipo":"multiple","peso":"0.5","enunciado":"...","opciones":{"a":"...","b":"...","c":"..."},"correctas":["a","c"],"justificacion":"..."},
  {"tipo":"emparejar","peso":"0.5","enunciado":"...","izquierda":{"1":"...","2":"..."},"derecha":{"a":"...","b":"..."},"pares":{"1":"b","2":"a"}},
  {"tipo":"ordenar","peso":"0.5","enunciado":"...","pasos":{"p1":"...","p2":"...","p3":"..."},"orden":["p2","p1","p3"]},
  {"tipo":"completar","peso":"0.5","enunciado":"...","plantilla":"texto con ___ por cada hueco","huecos":["respuesta1","respuesta2"]},
  {"tipo":"abierta","peso":"1.0","enunciado":"...","referencia":"respuesta esperada","rubrica":"criterios y puntajes"},
  {"tipo":"reto","peso":"1.5","enunciado":"...","plantilla_codigo":"function f(x) {\n}\n","casos_visibles":[{"entrada":"f(2)","esperada":"4","descripcion":"..."}],
   "verificaciones":[{"id":"v1","nombre":"...","fraccion":"0.5","prueba":"f(2) === 4"},{"id":"v2","nombre":"...","fraccion":"0.5","prueba":"f(0) === 0","reservada":true}]}
 ]}
Use solo los tipos pedidos en «Cantidad de ítems».
TXT,

        'rubrica' => <<<'TXT'
Tarea: diseñe una rúbrica analítica para calificar {{actividad}}

Tema:                {{tema}}
Grupo:               {{grupo}}
Número de criterios: {{criterios}}
Niveles (de mayor a menor): {{niveles}}

Reglas:
- Cada criterio evalúa una sola cosa que se puede observar en el trabajo del estudiante; dos criterios no miden lo mismo.
- «peso» es el porcentaje de la nota que vale el criterio; los pesos de todos los criterios suman exactamente 100.
- Cada criterio tiene exactamente {{cantidad_niveles}} niveles con los nombres indicados, en ese orden.
- «porcentaje» es la parte del criterio que otorga el nivel: el primero otorga 100, los demás menos, sin repetir, y el último puede otorgar 0.
- La descripción de cada nivel dice en una o dos frases qué se ve en una respuesta de ese nivel, en concreto
  («explica la diferencia entre requisito y restricción con un ejemplo propio»), nunca «bien», «regular» o «mal».
- Cíñase al material de referencia y al alcance del grupo: no exija lo que el grupo no ha visto.
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-rubrica","version":1,"titulo":"...","proposito":"qué se califica, en una frase",
 "criterios":[
  {"nombre":"...","descripcion":"qué evalúa este criterio","peso":"40",
   "niveles":[{"nombre":"Excelente","descripcion":"...","porcentaje":"100"},{"nombre":"Bueno","descripcion":"...","porcentaje":"75"}]}
 ]}
TXT,

        'unidad' => <<<'TXT'
Tarea: planee la unidad didáctica como una secuencia de sesiones de clase.

Unidad:               {{unidad}}
Temas de la unidad:
{{temas}}
Resultados de aprendizaje:
{{resultados}}
Grupo:                {{grupo}}
Número de sesiones:   {{sesiones}}, de {{duracion}} minutos cada una
Énfasis del docente:  {{enfasis}}

Reglas:
- Cubra todos los temas de la unidad en orden lógico; en «temas» de cada sesión escriba el nombre exacto del tema tal como aparece arriba.
- El objetivo de cada sesión empieza con un verbo observable («Implementar…», «Comparar…»), nunca «Conocer» o «Entender».
- Inicio, desarrollo y cierre dicen qué hace el docente y qué hacen los estudiantes, con tiempos aproximados que sumen la duración.
- Prefiera situaciones problema y práctica en clase a la exposición larga.
- En «recursos» mencione el material del repositorio por su título cuando sirva.
- La evaluación de la sesión es «diagnostica», «formativa», «sumativa» o «ninguna»; la evaluación de la unidad lista las actividades
  calificables («quiz», «taller», «parcial», «trabajo», «sustentacion» u «otra») y qué evalúa cada una.
- Cíñase al material de referencia y al alcance del grupo.
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-unidad","version":1,"titulo":"...","proposito":"para qué sirve la unidad, en dos frases",
 "resultados":["..."],"previos":["conocimiento previo necesario"],
 "sesiones":[
  {"titulo":"...","temas":["..."],"objetivo":"...","duracion":"{{duracion}}",
   "inicio":"...","desarrollo":"...","cierre":"...","recursos":["..."],
   "evaluacion":{"tipo":"formativa","descripcion":"..."}}
 ],
 "evaluacion":[{"actividad":"...","tipo":"quiz","descripcion":"qué evalúa y cuándo"}]}
TXT,

        'ejemplos' => <<<'TXT'
Tarea: escriba ejemplos prácticos resueltos paso a paso, para que el estudiante aprenda viendo cómo se razona.

Tema:        {{tema}}
Cantidad:    {{cantidad}} ejemplos
Dificultad:  {{dificultad}}
Contexto:    {{contexto}}
Grupo:       {{grupo}}

Reglas:
- Cada ejemplo parte de una situación concreta y creíble, no de una definición.
- Los pasos muestran el razonamiento: qué se decide en cada paso y por qué, no solo el resultado.
  Si un paso lleva código, póngalo en «codigo» del paso y explíquelo en «explicacion».
- «respuesta» es el resultado final, verificable.
- En «errores» liste las equivocaciones típicas de los estudiantes en ese ejercicio y cómo evitarlas.
- «practica» es un ejercicio parecido, sin resolver, para que el estudiante lo intente solo.
- En «tema» escriba el nombre del tema tal como aparece arriba.
{{reglas_codigo}}
- Cíñase al material de referencia y al alcance del grupo.
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-ejemplos","version":1,"titulo":"...","proposito":"qué aprende el estudiante con estos ejemplos",
 "ejemplos":[
  {"titulo":"...","tema":"...","dificultad":"baja|media|alta","enunciado":"...",
   "pasos":[{"explicacion":"...","codigo":""},{"explicacion":"...","codigo":""}],
   "respuesta":"...","errores":["..."],"practica":"...","codigo":"","comprobacion":""}
 ]}
TXT,

        'debate' => <<<'TXT'
Tarea: proponga preguntas para debatir en clase: abiertas, que admitan posturas razonadas y obliguen a usar los conceptos del tema.

Tema:        {{tema}}
Cantidad:    {{cantidad}} preguntas
Enfoque:     {{enfoque}}
Dinámica:    {{dinamica}}
Tiempo:      unos {{minutos}} minutos por pregunta
Contexto:    {{contexto}}
Grupo:       {{grupo}}

Reglas:
- Nada de preguntas de definición o de dato («¿Qué es…?», «¿Cuántos…?»): cada pregunta admite al menos dos posturas defendibles.
- «contexto» es un caso breve y concreto que sitúa la pregunta; se proyecta a los estudiantes.
- En «posturas» escriba al menos dos posiciones, cada una con argumentos que usen los conceptos del tema: es la guía del docente.
- «seguimiento» son repreguntas para profundizar o para destrabar la discusión.
- «cierre» es la idea o el concepto que debe quedar claro al terminar.
- «enfoque» es dilema, caso, controversia, prediccion o comparacion; «dinamica» es individual, parejas, grupos o plenaria.
- En «tema» escriba el nombre del tema tal como aparece arriba.
- Cíñase al material de referencia y al alcance del grupo.
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-debate","version":1,"titulo":"...","proposito":"qué se busca con el debate",
 "preguntas":[
  {"pregunta":"¿...?","tema":"...","enfoque":"dilema","contexto":"...","dinamica":"grupos","minutos":"{{minutos}}",
   "posturas":[{"postura":"...","argumentos":["..."]},{"postura":"...","argumentos":["..."]}],
   "seguimiento":["..."],"cierre":"..."}
 ]}
TXT,

        'vocabulario' => <<<'TXT'
Tarea: arme una lista de vocabulario con los términos clave del tema, para que el estudiante los entienda y los use con precisión.

Tema:        {{tema}}
Cantidad:    {{cantidad}} términos
Nivel:       {{nivel}}
Contexto:    {{contexto}}
Grupo:       {{grupo}}

Reglas:
- Solo términos que el estudiante necesita para el tema; nada de relleno ni de términos de otros temas.
- La definición tiene una o dos frases, en palabras sencillas y sin usar el mismo término que define.
- «ejemplo» muestra el término en una situación concreta del contexto indicado.
- «relacionados» son otros términos de la lista con los que se conecta o se confunde.
{{reglas_ingles}}
- En «tema» escriba el nombre del tema tal como aparece arriba.
- Use el vocabulario del material de referencia y cíñase al alcance del grupo.
{{glosario_actual}}
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-vocabulario","version":1,"titulo":"...","proposito":"para qué le sirve al estudiante",
 "terminos":[
  {"termino":"...","tema":"...","definicion":"...","ejemplo":"...","ingles":"","relacionados":["..."]}
 ]}
TXT,

        'proyectos' => <<<'TXT'
Tarea: proponga ideas de proyecto que integren los temas indicados en un producto concreto, útil para alguien real.

Temas que integra: {{tema}}
Cantidad:          {{cantidad}} proyectos
Duración:          {{semanas}} semanas
Integrantes:       {{integrantes}}
Contexto:          {{contexto}}
Grupo:             {{grupo}}

Reglas:
- Cada proyecto parte de un problema o una necesidad concreta, con un usuario o cliente identificable; no de un tema.
- Use solo lo que el grupo ya vio: cada proyecto integra varios de los temas indicados y no exige temas que el grupo no ha visto.
- «entregables» dice qué se entrega al final (producto, informe, presentación…).
- «hitos» son entregas parciales con su semana; el último cae en la semana {{semanas}}.
- «criterios» son los aspectos que se evalúan; servirán para armar la rúbrica.
- «extension» es un reto adicional opcional para quien avance más rápido.
- «notas_docente» advierte los riesgos típicos y cómo acompañar; no se muestra al estudiante.
- Varíe la dificultad entre los proyectos y escriba «temas» con los nombres tal como aparecen arriba.
{{reglas_codigo}}
- Cíñase al material de referencia y al alcance del grupo.
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-proyectos","version":1,"titulo":"...","proposito":"qué se busca con estos proyectos",
 "proyectos":[
  {"titulo":"...","resumen":"una frase","problema":"...","temas":["..."],"dificultad":"baja|media|alta","integrantes":"{{integrantes}}","semanas":"{{semanas}}",
   "entregables":["..."],"hitos":[{"semana":"2","entrega":"..."},{"semana":"{{semanas}}","entrega":"..."}],
   "criterios":["..."],"extension":"...","notas_docente":"..."}
 ]}
TXT,

        'apuntes' => <<<'TXT'
Tarea: escriba un texto informativo (apunte de clase) que explique el tema para que el estudiante lo estudie por su cuenta.

Tema:       {{tema}}
Extensión:  {{extension}}
Nivel:      {{nivel}}
Enfoque:    {{enfoque}}
Grupo:      {{grupo}}

Reglas:
- Explique, no enumere: cada sección parte de una pregunta o situación y llega al concepto, con al menos un ejemplo concreto.
- Párrafos cortos. Separe los párrafos con una línea en blanco; para listas, empiece cada línea con «- ».
- «idea_clave» resume la sección en una frase; «codigo» solo si hace falta mostrar código (sin explicarlo ahí: la explicación va en el texto).
- «resumen» son las ideas clave de todo el texto; «preguntas» son preguntas de comprensión con su respuesta breve.
- Base lo que dice en el material de referencia y cite en «fuentes» los títulos del material que usó; no invente referencias.
- Cíñase al alcance del grupo.
{{glosario}}
{{memoria}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"formato":"plataforma-apunte","version":1,"titulo":"...","proposito":"qué va a entender el estudiante",
 "secciones":[{"titulo":"...","texto":"párrafo\n\notro párrafo","idea_clave":"...","codigo":""}],
 "resumen":["..."],"preguntas":[{"pregunta":"...","respuesta":"..."}],"fuentes":["..."]}
TXT,

        'comentarios' => <<<'TXT'
Tarea: proponga un comentario breve para cada respuesta abierta de esta pregunta, como lo escribiría el docente después de leerla.

Pregunta:
{{pregunta}}

Respuesta de referencia del docente:
{{referencia}}

Rúbrica:
{{rubrica}}

Respuestas, cada una con el puntaje que ya le puso el docente:
{{respuestas}}

Reglas (voz del docente):
- Una a tres frases por respuesta, en el trato y el tono indicados en el contexto, como se habla en clase.
- Diga primero qué está bien, luego qué falta o qué corregir, en concreto y sobre lo que el estudiante escribió.
- Coherente con el puntaje, pero SIN mencionar cifras, puntajes, porcentajes, niveles de rúbrica ni conteos.
- Nada de fórmulas de máquina («Excelente trabajo», «Sigue así») ni listas: frases de profesor.
- No invente lo que la respuesta no dice. No use el nombre del estudiante.

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"comentarios":[{"id":123,"comentario":"..."}]}
Use exactamente los números entre corchetes como «id».
TXT,

        'puntajes' => <<<'TXT'
Tarea: sugiera un puntaje para cada respuesta abierta de esta pregunta, como lo haría el docente con su rúbrica.
Usted no califica: propone. El docente lee cada respuesta y decide; su sugerencia solo le ahorra tiempo.

Pregunta:
{{pregunta}}

Respuesta de referencia del docente:
{{referencia}}

Puntaje máximo: {{maximo}}

Rúbrica:
{{rubrica}}

Respuestas, sin el nombre de quien las escribió:
{{respuestas}}

Reglas:
- Juzgue solo lo que la respuesta dice. No premie la extensión, la ortografía ni el tono si la rúbrica no lo pide.
- Si hay criterios numerados, elija para cada uno el número del nivel que mejor describe la respuesta, en
  «niveles», en el mismo orden de los criterios. El puntaje lo calcula la plataforma con esos niveles.
- Si no hay criterios numerados, dé «puntaje» entre 0 y {{maximo}}, con un decimal como máximo.
- En «justificacion», una o dos frases PARA EL DOCENTE: qué cumple y qué le falta, citando lo que la respuesta dice.
- Respuesta vacía, fuera de tema o que copia la pregunta: el nivel más bajo o puntaje 0, y dígalo.
- Ante la duda entre dos niveles, elija el más bajo y explique la duda en la justificación.

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"sugerencias":[{"id":123,"niveles":[0,2],"puntaje":1.5,"justificacion":"..."}]}
Use exactamente los números entre corchetes como «id». Con rúbrica, «niveles» es obligatorio; sin rúbrica, «puntaje».
TXT,

        'variante' => <<<'TXT'
Tarea: escriba la versión {{version}} de un examen ya aprobado, a partir de su versión {{origen}}: {{proposito}}.

Reglas:
- Exactamente {{cantidad}} ítems, uno por cada ítem original y en el mismo orden («n» indica a cuál corresponde).
- Cada ítem nuevo evalúa lo MISMO que su original: mismo tipo, mismo tema, misma dificultad y mismo «peso».
- Cambie los datos, los números, los nombres y el contexto de la situación, de modo que la respuesta del original
  no sirva para el nuevo. No basta con reordenar opciones ni con cambiar una palabra.
- Las respuestas correctas deben ser correctas para los datos NUEVOS: recalcúlelas.
- En los retos de código, la tarea es la misma con otros datos: ajuste «casos_visibles» y las «prueba» de las
  verificaciones a los datos nuevos, conserve cuáles son reservadas, el «lenguaje» del reto y que las fracciones sumen 1.
- Mantenga el formato de cada tipo exactamente como en el original.
- Indicaciones del docente: {{indicaciones}}

Ítems originales (versión {{origen}}):
{{items}}

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después, con esta forma:
{"items":[ { ...ítem nuevo con los mismos campos que su original... } ]}
TXT,

        'temario' => <<<'TXT'
Tarea: lea el plan curricular de la materia y extraiga su temario como estructura.

- Identifique las unidades en el orden del documento, los temas de cada unidad y los resultados de aprendizaje.
- Use los títulos del documento; no invente temas que no estén en el plan.
- «descripcion» es una frase corta con lo que cubre el tema (opcional).

Formato de salida: responda SOLO con un objeto JSON, sin texto antes ni después:
{"unidades":[{"titulo":"Unidad 1: ...","resultados":["El estudiante ..."],"temas":[{"titulo":"...","descripcion":"..."}]}]}
TXT,
        'sugerencias' => <<<'TXT'
Tarea: sugiera cómo organizar la clase para cada tema del temario. Son sugerencias: el docente decide.

Temario (id: título):
{{temario}}

Material disponible en el repositorio:
{{materiales}}

Para cada tema, en dos o tres frases: en qué orden conviene verlo, qué material del repositorio usar
y qué actividad práctica encaja (quiz, taller, reto de código, debate). Sea concreto; nada de relleno.

Formato de salida: responda SOLO con un objeto JSON:
{"sugerencias":[{"id":123,"sugerencia":"..."}]}
TXT,
    ],

    /* Valores por defecto de la capa 1 (el docente los cambia en su perfil). */
    'perfil_por_defecto' => [
        'institucion' => 'la institución',
        'programa' => 'Ingeniería Informática',
        'ciudad' => 'Barrancabermeja, Santander',
        'idioma' => 'español de Colombia, trato de usted, sin tuteo',
        'perfil_estudiantes' => 'estudiantes de pregrado; equipos de la sala de cómputo sin internet',
        'reglas_escritura' => 'poco texto explicativo y mucho ejercicio práctico; enunciados como situaciones problema; contenido incremental',
        'restricciones' => 'dramatismo, relleno, ejercicios que no se puedan verificar, temas que el grupo no haya visto',
    ],
];
