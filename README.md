# Plataforma docente UNIPAZ (plataforma-docente)

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
7. **Entregar las notas.** Excel con todas las notas, planilla CSV para Excel en español y respaldo completo en JSON.

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

| Exportación: Excel con una hoja por corte y planilla; CSV con `;` y BOM; un periodo incompleto no sale como nota final | `app/Notas/ExportadorNotas.php` | 3.1.11, 3.1.14 |
| Cierre: simulador de lo que necesita cada estudiante (meta redondeada hacia arriba), al margen, aprobados por vacía o por redondeo, revisión de ceros y vacías | `app/Notas/CierrePeriodo.php`, `/grupos/{grupo}/cierre` | 3.7 |
| Edición de estudiante y retiro del grupo sin perder notas | `app/Http/Controllers/EstudianteController.php` | 3.4.5, 3.4.6 |
| Respaldo JSON del dictado con estructura, estudiantes, notas y bitácora | `app/Dictados/RespaldoDictado.php` | 3.1.10 |

### Fuera del MVP (siguientes fases)

- **Calificador de entregas cifradas.** El servidor ya califica los ítems y recibe los resultados de los retos, pero falta la página del navegador y el descifrado. Para eso hacen falta un archivo de entrega y el calificador HTML actuales, así queda compatible con el formato vigente (AES-GCM 256 + RSA-OAEP 2048).
- Generación con IA (los motores ya están), contenido de la materia, supletorios y habilitaciones en pantalla (el modelo ya existe), y restaurar un respaldo.

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
php artisan migrate:fresh --seed      # datos de demostración: demo@unipaz.edu.co / unipaz-demo-2026
php artisan serve                     # http://127.0.0.1:8000
php artisan test
```

### Motor de IA local (opcional)

Con [Ollama](https://ollama.com) instalado en el equipo o en un servidor del programa, se registra un motor en la tabla `motores_ia` con `driver = ollama`, `base_url = http://<ip>:11434` y el modelo descargado. Mientras no exista la interfaz, el registro se hace con `php artisan tinker`.

## Próximos pasos

1. Página del calificador en el navegador que ejecuta los retos con el entorno de la sección 4 y llama a `POST /calificador/entregas/{entrega}/resultados-navegador`.
2. Descifrado de entregas compatible con el formato actual (AES-GCM 256 + RSA-OAEP 2048) para migrar el corte en curso.
