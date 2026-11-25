# Instalación

## En el equipo del docente con WAMP (o XAMPP, Laragon)

Requisitos: PHP 8.3 o superior con las extensiones `pdo_mysql` (o `pdo_sqlite`), `mbstring`, `xml`, `zip`, `gd`, `intl`, `fileinfo`; Composer; MySQL 8 o MariaDB 10.6 o superior. **No se necesita Node ni `npm`.**

1. Clone el repositorio dentro de `www` (o donde tenga sus proyectos):
   ```bash
   git clone https://github.com/logo3x/plataforma-docente.git
   cd plataforma-docente
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   ```
2. Cree una base de datos vacía (por ejemplo `plataforma_docente`, cotejamiento `utf8mb4_unicode_ci`) y ajuste `.env`:
   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://plataforma.test
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=plataforma_docente
   DB_USERNAME=root
   DB_PASSWORD=
   ```
3. Cree las tablas y su cuenta:
   ```bash
   php artisan migrate --force
   php artisan docente:crear
   ```
4. Apunte un virtual host de Apache a la carpeta `public/` del proyecto (en WAMP: *Your VirtualHosts → VirtualHost Management*), o pruebe sin virtual host con `php artisan serve`.
5. Entre, vaya a **Seguridad** y active el segundo factor.

### Probar con datos de demostración

Solo en desarrollo (`APP_ENV=local`):

```bash
php artisan migrate:fresh --seed
```

Usuario: `demo@ejemplo.edu.co`, contraseña `demo-plataforma-2026`.

## En un servidor del programa

Además de lo anterior:

- **HTTPS obligatorio.** La aplicación maneja notas e identificación de estudiantes.
- `APP_DEBUG=false` siempre. Con `true`, un error muestra variables de entorno en pantalla.
- La `APP_KEY` cifra los solucionarios, las claves de API y las claves privadas guardadas. **Guárdela aparte**: si se pierde, esos datos no se pueden descifrar. Si se filtra, hay que rotarla.
- Copias de seguridad diarias de la base de datos, cifradas y guardadas fuera del servidor. El respaldo JSON por dictado (en la página del periodo) es un complemento, no un reemplazo.
- `php artisan config:cache && php artisan route:cache && php artisan view:cache` después de cada actualización.

## Actualizar

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```
