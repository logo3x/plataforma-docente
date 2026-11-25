# Plataforma docente UNIPAZ (plataforma-docente)

Aplicación Laravel que unifica el registro de notas, la generación de actividades, el calificador y el contenido de las materias. El ciclo es: preparar contenido → generar la actividad → aplicarla → calificarla → registrar la nota, sin escribir un dato dos veces.

- Requerimientos: documento v3 (fuera del repositorio).
- Revisión del documento: [`docs/revision-requerimientos-v3.md`](docs/revision-requerimientos-v3.md)
- Diseño del modelo de datos y del motor de IA: [`docs/diseno-modelo-datos-y-motor-ia.md`](docs/diseno-modelo-datos-y-motor-ia.md)

## Estado

Fases 0 a 2 (esqueleto), sin interfaz todavía. Ya funciona:

| Pieza | Dónde | Requerimientos |
|---|---|---|
| Modelo de datos completo: materia, dictado, divisiones anidables, grupos, estudiantes, artefactos, banco, actividades, entregas, notas, IA | `database/migrations`, `app/Models` | 2.6, 2.7, 9.1, 11.5 |
| Cálculo de notas exacto (sin `float`): promedio que ignora vacías, definitiva, aporte, estados, escala y redondeo configurables, conversión de escala | `app/Notas/CalculadoraNotas.php`, `Escala.php` | 3.1.6, 3.2 |
| Simulador de cierre: nota necesaria, «no alcanza», «aprueba por redondeo», al margen | `CalculadoraNotas::simularCierre` | 3.7 |
| Registro de notas con bitácora inmutable (triggers en BD), deshacer por operación, vacías a ceros reversible, eliminación con confirmación | `app/Notas/RegistroNotas.php` | 3.1.9, 3.5, 9.1 |
| Calificación híbrida: el servidor califica única, V/F, múltiple con crédito parcial, emparejar, ordenar y huecos; el navegador del docente reporta qué verificaciones de código pasaron y el servidor calcula el puntaje | `app/Calificacion` | 5.3, 6.1, 6.4, revisión A1 |
| Anulación de ítem: dar por bueno o retirar y reescalar | `CalificadorEntrega::nota` | 6.5.5 |
| Entrega grupal: la nota baja a todos, tope por tamaño de grupo, alerta de estudiante en dos entregas | `app/Calificacion/AsignacionGrupal.php` | 6.8 |
| Motor de IA intercambiable: Ollama, servidores compatibles con OpenAI (LM Studio, llama.cpp, vLLM) y Claude en la nube con el SDK oficial | `app/IA` | 1.5 |

### Reglas que el código garantiza

- **Con datos de estudiantes, solo motor local.** Sin autorización explícita (`IA_PERMITIR_NUBE_CON_DATOS_ESTUDIANTES=true`), esas tareas se niegan a usar un motor en la nube.
- **Nunca se cae en silencio a otro motor.** Si el motor configurado no responde, la aplicación avisa y se detiene.
- **El solucionario no se filtra.** `solucionario`, `clave`, `api_key` y la clave privada se guardan cifrados (cast `encrypted`) y están ocultos en la serialización.
- **El navegador no puede inventar puntajes.** Las verificaciones y sus valores viven en el servidor. Si el navegador envía una verificación que sobra o falta, el resultado se rechaza.

## Puesta en marcha

Requisitos: PHP 8.3 o superior, Composer, y SQLite para desarrollo o MySQL 8 para producción.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan test
```

### Motor de IA local (opcional)

Con [Ollama](https://ollama.com) instalado en el equipo o en un servidor del programa, se registra un motor en la tabla `motores_ia` con `driver = ollama`, `base_url = http://<ip>:11434` y el modelo descargado. Mientras no exista la interfaz, el registro se hace con `php artisan tinker`.

## Próximos pasos

1. Autenticación del docente (Laravel Breeze o Fortify) e interfaz del registro de notas con Livewire o Inertia.
2. Cargue masivo con la plantilla oficial de secretaría (3.4).
3. Página del calificador en el navegador que ejecuta los retos con el entorno de la sección 4 y llama a `POST /calificador/entregas/{entrega}/resultados-navegador`.
4. Descifrado de entregas compatible con el formato actual (AES-GCM 256 + RSA-OAEP 2048) para migrar el corte en curso.
