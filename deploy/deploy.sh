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

echo "→ Mantenimiento"
$PHP artisan down --retry=15 || true

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

echo "→ Certificados guardados en claro (si los hubiera)"
$PHP artisan empresa:cifrar-certificados

echo "→ Permisos (el deploy corre como root; PHP-FPM y el worker corren como www-data)"
chown -R www-data:www-data storage bootstrap/cache public/build
chown www-data:www-data .env

echo "→ Workers"
$PHP artisan queue:restart
if command -v supervisorctl >/dev/null && supervisorctl status "$PROGRAMA_SUPERVISOR" >/dev/null 2>&1; then
    supervisorctl restart "$PROGRAMA_SUPERVISOR"
fi

echo "→ Fin de mantenimiento"
$PHP artisan up

echo "✔ Despliegue completo: $(leer APP_URL)/up"
