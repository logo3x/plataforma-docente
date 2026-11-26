# Integración con Google Classroom

La plataforma puede **leer las entregas** de una tarea de Classroom y **escribirle las notas** (decisión pendiente 4).
Esta página es para quien administra la plataforma: se hace una sola vez por institución. Después, cada docente
conecta su propia cuenta desde **Classroom** (enlace en la barra superior).

## Qué hace y qué no

- **Cruza a los estudiantes por correo institucional**, nunca por nombre (3.1.5). Quien no tenga correo en la
  plataforma, o tenga uno distinto en Classroom, aparece en el reporte del cruce para que el docente lo corrija.
- **Trae las entregas** de cualquier tarea (las «Entregadas» y «Devueltas»), descarga los archivos adjuntos y los
  procesa como si se hubieran subido a mano:
  - en una actividad con examen digital, el `.json` se identifica por su sigla y su documento cifrado, así que un
    archivo subido a la tarea equivocada se rechaza y uno subido por otra persona queda con la alerta «La subió
    otra persona»;
  - en un trabajo, se toma el primer archivo que sirve (el PDF, si la actividad lo pide) y queda a nombre de quien
    lo entregó en Classroom.
  - Lo entregado tarde queda con la alerta «Entregada tarde».
- **Escribe las notas solo en tareas creadas desde la plataforma.** Es una regla de Classroom: solo el proyecto de
  Google que creó la tarea puede ponerle notas. Para las tareas creadas a mano en Classroom, la plataforma lee las
  entregas, pero las notas se pasan a mano. Desde la página Classroom de cada actividad se puede **crear la tarea**
  (como borrador o publicada), con los puntos de la escala del periodo.
- Las notas van **como borrador** por defecto: el docente las revisa y las devuelve desde Classroom. Con la casilla
  «También como asignadas» quedan asignadas, pero devolverlas sigue siendo del docente. Cada envío queda registrado
  (qué nota, cuántos puntos, cómo y quién).
- La nota que se envía es la vigente del registro, con supletorios y recuperaciones, convertida a los puntos de la
  tarea.

## Crear las credenciales (una vez)

1. Entre a <https://console.cloud.google.com/> con una cuenta administradora del dominio institucional y cree un
   proyecto, por ejemplo «Plataforma docente».
2. **API y servicios → Biblioteca:** habilite **Google Classroom API** y **Google Drive API**.
3. **API y servicios → Pantalla de consentimiento de OAuth:** tipo de usuario **Interno** (solo cuentas del dominio
   institucional; así no hace falta la verificación de Google). Agregue los permisos:
   - `.../auth/classroom.courses.readonly`
   - `.../auth/classroom.rosters.readonly`
   - `.../auth/classroom.profile.emails`
   - `.../auth/classroom.coursework.students`
   - `.../auth/drive.readonly`
4. **API y servicios → Credenciales → Crear credenciales → ID de cliente de OAuth**, tipo **Aplicación web**. En
   «URI de redireccionamiento autorizados» escriba la dirección de la plataforma seguida de `/classroom/callback`,
   por ejemplo `https://docente.ejemplo.edu.co/classroom/callback`.
5. Copie el ID y el secreto en el `.env` del servidor:

```
GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxxxxxxx
GOOGLE_REDIRECT_URI=https://docente.ejemplo.edu.co/classroom/callback
```

6. `php artisan config:clear`. La página Classroom ya muestra «Conectar con Google».

## Privacidad

- Los tokens de cada docente se guardan **cifrados** con la llave de la aplicación y no se muestran nunca.
  «Desconectar» revoca el acceso en Google y los borra.
- La plataforma no guarda la lista de Classroom: solo el identificador de Classroom de cada estudiante que cruzó
  por correo.
- Los archivos que se traen quedan en el almacenamiento de la plataforma, como cualquier entrega subida a mano.
