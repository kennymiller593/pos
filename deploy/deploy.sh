#!/usr/bin/env bash
# inkaPos — despliegue en el VPS (Ubuntu 22.04, PHP 8.4 FPM, Postgres 17 compartido).
# Lo ejecuta GitHub Actions por SSH despues de subir el codigo y public/build por rsync.
# Tambien sirve a mano:  bash /var/www/inkapos/deploy/deploy.sh
#
# Solo toca esta carpeta y su programa de supervisor. NO reinicia php-fpm, nginx,
# postgres ni supervisor completos: el servidor aloja otros proyectos en produccion.
set -euo pipefail

PROYECTO="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-/usr/bin/php8.4}"
COMPOSER="${COMPOSER_BIN:-/usr/local/bin/composer}"
PROGRAMA_SUPERVISOR="${PROGRAMA_SUPERVISOR:-inkapos-queue}"

cd "$PROYECTO"

[ -f .env ] || { echo "Falta $PROYECTO/.env (ver deploy/setup-servidor.sh)"; exit 1; }
[ -f public/build/manifest.json ] || { echo "Falta public/build (el build se hace en GitHub Actions)"; exit 1; }

leer() { grep "^$1=" .env | cut -d= -f2- | tr -d '"' | sed 's/[[:space:]]*#.*$//'; }

# Sin modo mantenimiento: ningún paso lo necesita (los scripts SQL son idempotentes y las cachés
# se regeneran en un segundo) y apagar la app dejaba a los clientes viendo un 503 en cada subida.
# Lo rápido que deja la app lista va primero; las tareas largas e idempotentes corren al final, ya en línea.

echo "→ Dependencias PHP"
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
$PHP "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "→ Esquema: scripts de database/sql (idempotentes)"
for f in database/sql/*.sql; do
    echo "   $f"
    PGPASSWORD="$(leer DB_PASSWORD)" psql -h "$(leer DB_HOST)" -p "$(leer DB_PORT)" \
        -U "$(leer DB_USERNAME)" -d "$(leer DB_DATABASE)" -v ON_ERROR_STOP=1 -q -f "$f"
done

echo "→ Cachés"
$PHP artisan optimize:clear
$PHP artisan storage:link --force >/dev/null
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

echo "→ Permisos (el deploy corre como root; PHP-FPM y el worker corren como www-data)"
chown -R www-data:www-data storage bootstrap/cache public/build
chown www-data:www-data .env

# Desde aquí la app ya atiende: todo artisan corre como www-data. Si corriera como root, lo que escriba
# en storage (caché, logs) quedaría de root y la web no podría usarlo (login con error 500 según la IP).
if [ "$(id -u)" = "0" ] && command -v sudo >/dev/null; then
    COMO_WEB="sudo -u www-data"
else
    COMO_WEB=""
fi

echo "→ Workers"
$COMO_WEB $PHP artisan queue:restart
if command -v supervisorctl >/dev/null && supervisorctl status "$PROGRAMA_SUPERVISOR" >/dev/null 2>&1; then
    supervisorctl restart "$PROGRAMA_SUPERVISOR"
fi

echo "✔ Aplicación actualizada y en línea: $(leer APP_URL)/up"

# ---- tareas de mantenimiento de datos: idempotentes y a veces largas; la app ya está sirviendo ----
echo "→ Certificados guardados en claro (si los hubiera)"
$COMO_WEB $PHP artisan empresa:cifrar-certificados || true

echo "→ Fotos de productos sin optimizar (si falla no detiene el despliegue)"
$COMO_WEB $PHP artisan productos:optimizar-imagenes || true

echo "→ Presentaciones que repetían el nombre del producto"
$COMO_WEB $PHP artisan productos:normalizar-presentaciones || true

# por si algún paso dejó algo de root: los permisos se dejan bien al terminar
chown -R www-data:www-data storage bootstrap/cache

echo "✔ Despliegue completo"
