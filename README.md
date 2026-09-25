# inkaPos

Punto de venta SaaS multiempresa para Perú: facturación electrónica SUNAT (boletas, facturas, notas de crédito, bajas), inventario con costeo FIFO por capas y lotes, multi-sucursal, caja con arqueo, cuentas por cobrar y pagar, compras, transferencias, reportes y suscripciones por plan.

Stack: Laravel 13 · Inertia + Vue 3 + Tailwind 4 · PostgreSQL 17 · Greenter (SUNAT) · wkhtmltopdf.

## Requisitos

- PHP 8.4+ con extensiones `pdo_pgsql`, `openssl`, `soap`, `zip`, `mbstring`, `gd`
- PostgreSQL 15+ con extensiones `pgcrypto` y `pg_trgm`
- Node 20+
- wkhtmltopdf (tickets, A4 y reportes en PDF)
- Cuenta en Decolecta para consultar RUC/DNI (opcional)

## Instalación local

```bash
cp .env.example .env            # revisa DB_*, WKHTML_PDF_BINARY, API_TOKEN_SUNAT
composer install
npm install
php artisan key:generate

# Base de datos (el esquema NO se gestiona con migraciones de Laravel)
createdb pos_db
psql -d pos_db -f database/schema/pgsql-schema.sql   # tablas, triggers, funciones, extensiones
psql -d pos_db -f database/schema/catalogos.sql      # roles, rubros, tipos, medios de pago, unidades, planes
psql -d pos_db -f database/catalogos/ubigeos.sql     # ubigeos
for f in database/sql/*.sql; do psql -d pos_db -f "$f"; done   # cambios posteriores al dump (idempotentes)

php artisan db:seed                # empresa de demo (admin@pos.test / admin123); solo fuera de producción
php artisan serve & npm run dev
php artisan schedule:work          # en desarrollo: reintentos SUNAT y vencimientos
```

### Cambios de esquema

`database/schema/pgsql-schema.sql` es un `pg_dump --schema-only` de referencia. Cada cambio posterior va en `database/sql/NNN_descripcion.sql`, idempotente (`IF NOT EXISTS`, `ON CONFLICT`), y se aplica con `psql` en cada despliegue (`deploy/deploy.sh` lo hace). Cuando se acumulen varios, regenera el dump:

```bash
pg_dump -d pos_db --schema-only --no-owner --no-privileges -f database/schema/pgsql-schema.sql
```

## Tests

```bash
php artisan test
```

Corren contra la base de `.env` dentro de una transacción por test (`DatabaseTransactions`); no usan migraciones. No apuntes `.env` a producción al correrlos.

## Comandos de operación

| Comando | Para qué |
|---|---|
| `php artisan sunat:sincronizar` | Reenvía comprobantes pendientes y confirma bajas (el scheduler lo corre cada 10 min) |
| `php artisan suscripcion:activar RUC plan --meses=N --nota="..."` | Activa o renueva un plan tras el pago (`emprendedor`, `negocio`, `empresa`) |
| `php artisan suscripciones:vencer` | Marca vencidas las suscripciones pasadas (diario) |
| `php artisan empresa:cifrar-certificados` | Cifra certificados guardados en claro y registra su vencimiento |

## Despliegue (pos.inkanet.pro, VPS compartido con otros proyectos)

El despliegue es automático con GitHub Actions (`.github/workflows/deploy.yml`): en cada push a `main` corre los tests contra un Postgres efímero (cargando `database/schema/`), compila el frontend y, si la variable de repositorio `DEPLOY_HABILITADO` es `true`, sube el código y `public/build` por **rsync** al servidor y ejecuta `deploy/deploy.sh` allí. En el servidor no se compila nada ni se hace `git pull`.

Secrets del repositorio: `SSH_HOST`, `SSH_USER` (root), `SSH_KEY` (clave privada), `DEPLOY_PATH` (`/var/www/inkapos`), dentro del environment `produccion`.

Alta inicial (una vez, como root, con el código ya en `/var/www/inkapos`): `bash deploy/setup-servidor.sh`. Instala wkhtmltopdf, crea base y rol `inkapos` con `pgcrypto`/`pg_trgm`, genera `.env` (revisar `MAIL_*`, `API_TOKEN_SUNAT`, `APP_SOPORTE_*`), carga esquema y catálogos, enlaza `storage`, y da de alta el vhost (`deploy/nginx-inkapos.conf`), el worker (`deploy/supervisor-inkapos.conf`, programa `inkapos-queue`) y el cron (`deploy/cron-inkapos`: scheduler y backup diario a `/root/backups/inkapos`). Luego `certbot --nginx -d pos.inkanet.pro` y en Cloudflare SSL/TLS en "Full (strict)".

Lo que el deploy **nunca** hace, porque el servidor aloja otros proyectos: reiniciar `php-fpm`, `nginx`, `postgres` o supervisor completos, cambiar la versión de PHP por defecto, ni tocar el pool compartido (los límites de subida van por vhost con `fastcgi_param PHP_VALUE`). Solo `reload` de nginx en el alta y `supervisorctl restart inkapos-queue`.

Guarda `APP_KEY` fuera del servidor: cifra las claves SOL y los certificados; si se pierde, se pierden. Salud: `GET /up`.

### Facturación electrónica

Cada empresa carga su certificado (.pem o .pfx en base64) y sus credenciales SOL en **Empresa**; el certificado se valida al cargarlo y se cifra. Se empieza en `beta` (los comprobantes se imprimen con "AMBIENTE DE PRUEBAS · SIN VALOR TRIBUTARIO"); el cambio a `produccion` exige desactivar antes la facturación y queda auditado.

## Estructura

- `app/Services/` — lógica de negocio (`VentaService`, `InventarioService`, `NotaCreditoService`, `SunatService`, `SuscripcionService`, ...). Los controladores validan y orquestan.
- `app/Support/Permisos.php` — matriz de permisos por rol (`admin`, `cajero`, `vendedor`, `almacenero`), aplicada con `can:` en rutas y en el frontend.
- `app/Console/Commands/` — comandos programados y de operación.
- `database/schema/`, `database/sql/` — esquema y sus cambios.
- `deploy/` — ejemplos de nginx, supervisor, cron y scripts de despliegue/backup.
