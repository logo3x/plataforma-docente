# Formato «plataforma-banco» v1

Archivo JSON con una actividad evaluable (quiz, parcial o taller). Se importa en **Actividades evaluables** de cada materia. No hace falta escribirlo a mano: el **banco de preguntas** de cada examen se edita en la aplicación pregunta por pregunta (los retos de código, en este mismo JSON), se puede crear un examen desde cero o con preguntas traídas de otros exámenes de la materia, y se descarga en este formato. Es el mismo formato que producirá el generador con IA. Hay un ejemplo completo en [`ejemplos/banco-ejemplo.json`](ejemplos/banco-ejemplo.json).

```json
{
  "formato": "plataforma-banco",
  "version": 1,
  "sigla": "ED-2026B-P2",
  "titulo": "Parcial 2",
  "tipo": "parcial",
  "valor": "5.0",
  "configuracion": { ... },
  "items": [ ... ]
}
```

- `sigla`: identificador único del examen. Viaja dentro de cada entrega y le permite al calificador reconocer el examen sin elegirlo a mano (6.1.3).
- `valor`: la suma de los `peso` de los ítems de cada versión debe ser exactamente este valor. Si no coincide, el banco no se importa (9.2.4).

## `configuracion` (todo opcional)

Todo esto se edita después en **Actividades evaluables → Configuración**, sin volver a importar el banco.

| Campo | Qué controla | Por defecto |
|---|---|---|
| `instrucciones` | Texto de la portada | — |
| `reloj_minutos` | Límite de tiempo con cierre automático; `null` = sin límite | `null` |
| `umbral_salidas` / `umbral_salidas_segundos` | Cantidad o duración acumulada de salidas que generan la alerta | 3 / 60 |
| `duracion_minima_minutos` | Por debajo de esta duración se marca «duración anómala» | — |
| `modo_desconectado` | Bloquea el examen mientras el equipo tenga internet | activo en parciales |
| `contar_salidas` | Registra las salidas de la ventana con su duración | `true` |
| `nivel_ayuda` | Ayuda en los retos de código: `completo`, `intermedio` o `minimo` (4.4) | `completo` en talleres, `intermedio` en quices y parciales |
| `sonda`, `intervalo_sonda` | Recurso que se intenta alcanzar y cada cuántos segundos | `https://www.google.com/generate_204`, 20 |
| `tiempo_ejecucion_ms` | Límite por ejecución de código | 2000 |
| `anexo` | Modalidad II: recuerda subir el anexo al finalizar | `false` |

## Ítems

Todos llevan `tipo`, `enunciado` y `peso`. Opcionales: `tema`, `dificultad` (`baja`, `media`, `alta`) y `justificacion`, que es la línea que aparece en el detalle autoexplicativo.

| Tipo | Campos | Calificación |
|---|---|---|
| `unica` | `opciones` {clave: texto}, `correcta` | Todo o nada |
| `vf` | `correcta` true/false | Todo o nada |
| `multiple` | `opciones`, `correctas` [claves] | Crédito por decisión; no marcar nada = sin responder |
| `emparejar` | `izquierda` {}, `derecha` {}, `pares` {izq: der} | Par por par |
| `ordenar` | `pasos` {clave: texto}, `orden` [claves] | Posición por posición; el examen baraja los pasos |
| `completar` | `plantilla` con `___` por hueco, `huecos` [respuesta o [alternativas]] | Hueco por hueco, sin distinguir mayúsculas, espacios ni `@` inicial |
| `abierta` | `referencia`, `rubrica`, `lineas` | La califica el docente, por pregunta |
| `reto` | `lenguaje` (`javascript`, `html`, `sql` o `php`), `esquema` (solo SQL), `archivos` y `alcance` (solo PHP), `plantilla_codigo`, `casos_visibles` [{entrada, esperada, descripcion}], `verificaciones` [{id, nombre, fraccion, prueba, reservada}] | En el navegador del docente; las fracciones suman 1 |

### Retos de código

- `entrada` de un caso visible es una expresión de JavaScript que el estudiante puede ejecutar con «Probar», por ejemplo `total([1000, 2500])`.
- `prueba` es una expresión que debe dar exactamente `true`, por ejemplo `total([]) === 0`. Se evalúa después del código del estudiante, en un Worker aislado, sin red y con límite de tiempo.
- `nombre` es lo que se lee en el detalle: escríbalo en lenguaje natural («Un carrito vacío suma cero»), no como expresión técnica (6.4).
- Una verificación con `"reservada": true` no se muestra al estudiante. El examen solo anuncia cuántas hay (4.3.3).
- En el informe del estudiante, ya calificada la actividad, las reservadas salen **completas** (nombre, resultado y valor) o **solo como cantidad** («2 verificaciones reservadas · superó 1 de 2 · 1.5 de 2.5»), según la opción `reservadas_informe` del panel de configuración (decisión pendiente 8). Por defecto, solo la cantidad en los parciales, cuyo banco se reutiliza en otra jornada u otro semestre, y completas en talleres y quices. El docente las ve siempre completas en el detalle de la entrega.

### Retos de HTML y CSS (`"lenguaje": "html"`)

El estudiante escribe una página completa (HTML, CSS y, si el reto lo pide, JavaScript) y con «Probar» la ve en una vista previa con la que puede interactuar. La ayuda señala las etiquetas sin cerrar o mal cerradas.

- `plantilla_codigo` es el HTML inicial.
- `entrada` y `prueba` son expresiones de JavaScript que se evalúan **sobre la página ya cargada**: `document.querySelectorAll('nav a').length === 3`, `getComputedStyle(document.querySelector('h1')).color === 'rgb(255, 0, 0)'` (los colores se comparan en formato `rgb(…)`), o una interacción: `(document.getElementById('contar').click(), document.getElementById('total').textContent === '3')`. También puede devolver una promesa.
- Cada verificación corre en su propia página de 1024 × 768, para que lo que haga una no afecte a la siguiente.
- La página corre aislada: sin red, sin acceso al examen ni a la plataforma, y con un límite de tiempo que corta los ciclos infinitos sin congelar el examen.
- En el detalle y en el informe se ve la página entregada sin ejecutar sus scripts.

```json
{ "tipo": "reto", "lenguaje": "html", "peso": "2", "enunciado": "Maquete un menú <nav> con tres enlaces en rojo.",
  "plantilla_codigo": "<!DOCTYPE html>\n<html>\n<body>\n  <nav>\n  </nav>\n</body>\n</html>\n",
  "casos_visibles": [{ "entrada": "document.querySelectorAll('nav a').length", "esperada": "3", "descripcion": "Enlaces en el menú" }],
  "verificaciones": [
    { "id": "v1", "nombre": "El menú tiene tres enlaces", "fraccion": "0.5", "prueba": "document.querySelectorAll('nav a').length === 3" },
    { "id": "v2", "nombre": "Los enlaces son rojos", "fraccion": "0.5", "reservada": true,
      "prueba": "[...document.querySelectorAll('nav a')].every(a => getComputedStyle(a).color === 'rgb(255, 0, 0)')" } ] }
```

Como en los retos de JavaScript, las verificaciones se ejecutan en el navegador del docente sobre el código del estudiante. Un estudiante que reescriba a propósito las funciones del navegador que usan las pruebas (por ejemplo `document.querySelectorAll`) podría engañarlas: el código queda siempre a la vista en el detalle de la entrega.

### Retos de SQL (`"lenguaje": "sql"`)

El estudiante escribe sus consultas y con «Probar» las ejecuta en un SQLite que corre dentro del navegador: ve la tabla que devuelve cada instrucción, las filas afectadas por un INSERT, UPDATE o DELETE, y los errores redactados en español («La tabla «equipo» no existe…»), con el mensaje original debajo. «Ver tablas» muestra las tablas de la actividad con sus datos.

- `esquema` son las instrucciones que crean y llenan las tablas (`CREATE TABLE`, `INSERT`). El estudiante las ve. Cada ejecución empieza con una base nueva creada con ellas: lo que haga una prueba no afecta a la siguiente.
- `entrada` de un caso visible es una consulta que se ejecuta después del código del estudiante; útil para que vea el efecto de un INSERT o un UPDATE.
- Cada verificación usa **una** de estas dos formas:
  - `consulta`: una consulta de referencia. Pasa si la última consulta del estudiante devuelve los mismos valores (mismo número de columnas y filas; los nombres de las columnas no importan). El orden de las filas solo cuenta con `"orden": true`; úselo cuando el enunciado pida `ORDER BY`.
  - `prueba`: una consulta que debe devolver 1 después del código del estudiante, por ejemplo `SELECT COUNT(*) = 1 FROM clientes WHERE nombre = 'Dora'`. Sirve para INSERT, UPDATE, DELETE y CREATE.
- `datos` (opcional) son instrucciones que se agregan al esquema solo para esa verificación. En una reservada, con otros datos, una respuesta escrita a mano («SELECT 'Ana', 150») no pasa.
- El motor (sql.js, SQLite 3.54 en WebAssembly, licencia MIT, en `public/vendor/sql.js`) viaja dentro del examen solo cuando el examen tiene retos de SQL: el archivo pesa cerca de 1 MB más y funciona sin internet. Una consulta que no termina se corta con el límite de tiempo, sin congelar el examen.

```json
{ "tipo": "reto", "lenguaje": "sql", "peso": "2", "enunciado": "Total de horas por equipo de la planta Barrancabermeja.",
  "esquema": "CREATE TABLE equipos (id INTEGER PRIMARY KEY, nombre TEXT, planta TEXT);\nINSERT INTO equipos VALUES (1, 'Bomba P-101', 'Barrancabermeja'), (2, 'Compresor C-20', 'Cartagena');\nCREATE TABLE ordenes (id INTEGER PRIMARY KEY, equipo_id INTEGER, horas REAL);\nINSERT INTO ordenes VALUES (1, 1, 4.5), (2, 1, 2), (3, 2, 8);",
  "plantilla_codigo": "SELECT\n",
  "verificaciones": [
    { "id": "v1", "nombre": "Total de horas por equipo", "fraccion": "0.7",
      "consulta": "SELECT e.nombre, SUM(o.horas) FROM equipos e JOIN ordenes o ON o.equipo_id = e.id WHERE e.planta = 'Barrancabermeja' GROUP BY e.nombre" },
    { "id": "v2", "nombre": "Funciona con órdenes nuevas", "fraccion": "0.3", "reservada": true,
      "datos": "INSERT INTO ordenes VALUES (4, 1, 10);",
      "consulta": "SELECT e.nombre, SUM(o.horas) FROM equipos e JOIN ordenes o ON o.equipo_id = e.id WHERE e.planta = 'Barrancabermeja' GROUP BY e.nombre" } ] }
```

### Retos de PHP y Laravel (`"lenguaje": "php"`)

PHP y Laravel **no se ejecutan**, ni en el navegador ni en el servidor. El código se revisa con criterios estáticos explicados en lenguaje natural, y la revisión nunca se presenta como ejecución (4.6):

- En el examen, el reto dice «Este reto no se ejecuta» y lista en español los criterios que se revisarán (los reservados solo se cuentan), además del `alcance`: lo que **no** se comprueba. No hay botón «Probar»; sí hay ayuda de sintaxis al escribir: llaves, paréntesis y comillas en PHP, y en Blade las directivas sin su cierre (`@foreach` sin `@endforeach`) y los `{{ }}` sin cerrar.
- `archivos`: los archivos que escribe el estudiante, cada uno con su editor: `[{ "nombre": "routes/web.php", "plantilla": "<?php\n" }, …]`, hasta 6. Sin `archivos` hay uno solo, `codigo.php`, con `plantilla_codigo`.
- Cada verificación tiene **un** criterio:
  - `patron`: expresión regular (sin delimitadores) que debe aparecer; con `"ausente": true`, que **no** debe aparecer (por ejemplo, `DB::select` en un controlador que debe usar Eloquent).
  - `contiene`: texto que debe aparecer, sin importar espacios ni saltos de línea.
  - `"sintaxis": true`: el archivo se analiza sin errores. En PHP con el analizador de PHP; en una vista `.blade.php`, compilándola a PHP. **Analizar no es ejecutar**: no corre ninguna línea del estudiante.
  - `archivo` (opcional) limita el criterio a uno de los archivos.
- Los comentarios no cuentan: escribir `// Route::get('/productos'…` no cumple un patrón. Con `"comentarios": true` sí se revisan.
- La revisión la hace el servidor al procesar la entrega: la nota sale de inmediato, sin «Ejecutar retos». El detalle y el informe dicen «Revisión estática, sin ejecutar el código» con el alcance, y cada criterio como «Cumple» o «No cumple»; un error de sintaxis se explica en español con su línea.
- Si el proyecto completo debe funcionar, pídalo además como **entrega de archivo libre** (un .zip) en una actividad aparte, y márquelo como anexo en la configuración del examen.

```json
{ "tipo": "reto", "lenguaje": "php", "peso": "3", "enunciado": "Liste los productos en /productos.",
  "archivos": [
    { "nombre": "routes/web.php", "plantilla": "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n" },
    { "nombre": "app/Http/Controllers/ProductoController.php", "plantilla": "<?php\n\nnamespace App\\Http\\Controllers;\n" },
    { "nombre": "resources/views/productos/index.blade.php", "plantilla": "" } ],
  "alcance": "No se comprobó que la página cargue ni que la base de datos tenga productos.",
  "verificaciones": [
    { "id": "v1", "nombre": "La ruta GET /productos llama a ProductoController@index", "fraccion": "0.3", "archivo": "routes/web.php",
      "patron": "Route::get\\(\\s*['\"]/productos['\"]\\s*,\\s*\\[\\s*ProductoController::class\\s*,\\s*['\"]index['\"]" },
    { "id": "v2", "nombre": "El controlador entrega los productos a la vista productos.index", "fraccion": "0.3",
      "archivo": "app/Http/Controllers/ProductoController.php", "patron": "view\\(\\s*['\"]productos\\.index['\"]" },
    { "id": "v3", "nombre": "La vista recorre los productos con @foreach", "fraccion": "0.2",
      "archivo": "resources/views/productos/index.blade.php", "patron": "@foreach\\s*\\(\\s*\\$productos\\s+as" },
    { "id": "v4", "nombre": "Los tres archivos no tienen errores de sintaxis", "fraccion": "0.2", "sintaxis": true, "reservada": true } ] }
```

## Versiones y segundo banco (5.4.2)

Cada ítem puede llevar `"version"`: `A` (si no se indica), `B`, `2A` o `2B`.

- **A y B** son dos versiones del mismo examen, con el mismo temario y distinto contexto. El examen lleva las dos y a cada estudiante le asigna una según su documento: siempre la misma, aunque cierre y vuelva a abrir. Se califica solo con los ítems de su versión.
- **2A y 2B** forman un segundo banco, con los mismos temas y pesos pero otros datos, para aplicarlo en otra jornada. Una foto del examen de la mañana no sirve en la noche. Cada banco se descarga como un archivo aparte.
- Cada versión debe sumar el `valor`. A y B, y 2A y 2B, deben tener los mismos tipos de ítem con los mismos pesos.
- Si una entrega trae una versión distinta a la que le corresponde por su documento, el calificador lo marca con una alerta.
- No hace falta escribir las versiones a mano. En **Actividades evaluables → Generar la versión B o un segundo banco con IA**, la IA las escribe a partir de un examen ya importado, y usted las revisa ítem por ítem junto al original antes de agregarlas.

```json
{ "version": "B", "tipo": "unica", "peso": "1", "enunciado": "En la fila del taller…", "opciones": { "a": "Cola", "b": "Pila" }, "correcta": "a" }
```

## Qué no sale nunca en el examen

`correcta`, `correctas`, `pares`, `orden`, `huecos`, `referencia`, `rubrica` y `verificaciones` se guardan cifrados en la base de datos y no se incluyen en el HTML del estudiante. Hay una prueba automática que lo verifica.
