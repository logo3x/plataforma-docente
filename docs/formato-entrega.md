# Formato «plataforma-entrega» v1

Archivo que descarga el examen al finalizar: `SIGLA_DOCUMENTO.json`.

```json
{
  "formato": "plataforma-entrega",
  "version": 1,
  "sigla": "ED-2026B-P2",
  "artefacto": "uuid del examen",
  "documento": "1098765432",
  "generado": "2026-11-25T14:03:00.000Z",
  "cifrado": { "alg": "RSA-OAEP-256+A256GCM", "clave": "base64", "iv": "base64", "datos": "base64" }
}
```

## Cifrado

1. El navegador genera una clave AES-256 aleatoria y cifra los datos con AES-GCM (IV de 12 bytes). `datos` es el texto cifrado seguido de la etiqueta de 16 bytes, que es lo que devuelve Web Crypto.
2. Esa clave se cifra con la **clave pública del periodo** usando RSA-OAEP, SHA-256 y MGF1-SHA-256. El resultado va en `clave`.
3. La clave privada existe solo en el servidor, cifrada con `APP_KEY`, y hay una por dictado.

Si el navegador no permite cifrar, `cifrado` llega en `null` y los datos van en `datos_planos`. El calificador los acepta y los marca con la alerta «sin cifrar».

## Datos descifrados

```json
{
  "sigla": "...", "artefacto": "...", "documento": "...", "nombre": "...", "grupo": "Diurno",
  "integrantes": ["documentos de los demás, si es grupal"],
  "inicio": "ISO-8601", "fin": "ISO-8601", "cierre": "manual | automatico",
  "respuestas": { "<id del ítem>": "valor según el tipo" },
  "sesion": {
    "salidas": [{ "inicio": "ISO-8601", "segundos": 12 }],
    "pegados": 0, "ejecuciones": 7, "errores_sintaxis": 1,
    "reconexiones": [], "restauraciones": 0, "navegador": "user agent"
  }
}
```

## Validaciones al recibir

- El documento visible del sobre debe coincidir con el cifrado. Si no, se genera la alerta «identidad no coincide» (archivo manipulado).
- El identificador del examen debe ser el de la actividad. Si la entrega es de otro examen, se rechaza y se dice a qué actividad pertenece (6.1.4).
- El estudiante debe estar en la lista del periodo. Si su grupo no es de la actividad, se genera la alerta «otro grupo».
- Un archivo idéntico, con el mismo SHA-256, no se procesa dos veces.

## Formato anterior

Las entregas del calificador HTML anterior se podrán cargar agregando un adaptador en `ProcesadorEntregas::leerSobre()`. Para escribirlo hace falta una entrega real de ejemplo.
