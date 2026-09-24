#!/usr/bin/env bash
# Despliegue en el servidor (Ubuntu/Debian, php-fpm + nginx). Ejecutar desde /var/www/pos-app tras `git pull`.
#   sudo -u www-data ./deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "== Dependencias PHP"
composer install --no-dev --optimize-autoloader --no-interaction

echo "== Frontend"
npm ci --no-audit --no-fund
npm run build

echo "== Esquema de BD: scripts nuevos en database/sql (idempotentes)"
# Se aplican todos; cada script usa IF NOT EXISTS / ON CONFLICT y puede repetirse.
for f in database/sql/*.sql; do
    echo "   $f"
    PGPASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)" psql \
        -h "$(grep '^DB_HOST=' .env | cut -d= -f2-)" \
        -U "$(grep '^DB_USERNAME=' .env | cut -d= -f2-)" \
        -d "$(grep '^DB_DATABASE=' .env | cut -d= -f2-)" \
        -v ON_ERROR_STOP=1 -q -f "$f"
done

echo "== Caches de Laravel"
php artisan storage:link --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "== Certificados y colas"
php artisan empresa:cifrar-certificados
php artisan queue:restart

echo "== Listo. Comprueba https://$(grep '^APP_URL=' .env | cut -d/ -f3)/up"
