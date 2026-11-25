# Formato «plataforma-banco» v1

Archivo JSON con una actividad evaluable (quiz, parcial o taller). Se importa en **Actividades evaluables** de cada materia. Es el mismo formato que producirá el generador con IA. Hay un ejemplo completo en [`ejemplos/banco-ejemplo.json`](ejemplos/banco-ejemplo.json).

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
- `valor`: la suma de los `peso` de todos los ítems debe ser exactamente este valor. Si no coincide, el banco no se importa (9.2.4).

## `configuracion` (todo opcional)

| Campo | Qué controla | Por defecto |
|---|---|---|
| `instrucciones` | Texto de la portada | — |
| `reloj_minutos` | Límite de tiempo con cierre automático; `null` = sin límite | `null` |
| `umbral_salidas` / `umbral_salidas_segundos` | Cantidad o duración acumulada de salidas que generan la alerta | 3 / 60 |
| `duracion_minima_minutos` | Por debajo de esta duración se marca «duración anómala» | — |
| `modo_desconectado` | Bloquea el examen mientras el equipo tenga internet | `false` |
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
| `reto` | `plantilla_codigo`, `casos_visibles` [{entrada, esperada, descripcion}], `verificaciones` [{id, nombre, fraccion, prueba, reservada}] | En el navegador del docente; las fracciones suman 1 |

### Retos de código

- `entrada` de un caso visible es una expresión de JavaScript que el estudiante puede ejecutar con «Probar», por ejemplo `total([1000, 2500])`.
- `prueba` es una expresión que debe dar exactamente `true`, por ejemplo `total([]) === 0`. Se evalúa después del código del estudiante, en un Worker aislado, sin red y con límite de tiempo.
- `nombre` es lo que se lee en el detalle: escríbalo en lenguaje natural («Un carrito vacío suma cero»), no como expresión técnica (6.4).
- Una verificación con `"reservada": true` no se muestra al estudiante. El examen solo anuncia cuántas hay (4.3.3).

## Qué no sale nunca en el examen

`correcta`, `correctas`, `pares`, `orden`, `huecos`, `referencia`, `rubrica` y `verificaciones` se guardan cifrados en la base de datos y no se incluyen en el HTML del estudiante. Hay una prueba automática que lo verifica.
