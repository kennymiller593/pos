#!/usr/bin/env bash
# inkaPos — ALTA INICIAL en el VPS compartido (ejecutar UNA sola vez, como root).
#   bash setup-servidor.sh
#
# Da por hecho lo que ya existe en el servidor y NO lo reinstala ni lo cambia:
# PHP 8.4 (php8.4-fpm con su socket), PostgreSQL 17, nginx, supervisor, certbot,
# composer en /usr/local/bin. Lo único nuevo que instala es wkhtmltopdf.
# No reinicia php-fpm, nginx (solo reload), postgres ni supervisor completos.
set -euo pipefail

RUTA=/var/www/inkapos
DOMINIO=pos.inkanet.pro
BD=inkapos
ROL=inkapos
PHP=/usr/bin/php8.4

[ "$(id -u)" -eq 0 ] || { echo "Ejecutar como root"; exit 1; }
[ -f "$RUTA/artisan" ] || { echo "Primero sube el código a $RUTA (el workflow de GitHub Actions lo hace por rsync, o clónalo)"; exit 1; }

echo "== 1. wkhtmltopdf (paquete oficial con Qt parcheado, no necesita X)"
if ! command -v wkhtmltopdf >/dev/null; then
    apt-get install -y -qq fontconfig libjpeg-turbo8 libxrender1 xfonts-75dpi xfonts-base
    curl -fsSL -o /tmp/wkhtmltox.deb https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb
    dpkg -i /tmp/wkhtmltox.deb || apt-get install -f -y -qq
fi
wkhtmltopdf --version

echo "== 2. Base de datos y rol propios en el clúster compartido"
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$ROL'" | grep -q 1; then
    # se puede pasar por entorno para ejecuciones no interactivas: CLAVE_BD=... bash setup-servidor.sh
    if [ -z "${CLAVE_BD:-}" ]; then
        read -r -s -p "Contraseña para el rol Postgres '$ROL': " CLAVE_BD; echo
    fi
    sudo -u postgres psql -c "CREATE ROLE $ROL LOGIN PASSWORD '$CLAVE_BD';"
fi
sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$BD'" | grep -q 1 \
    || sudo -u postgres psql -c "CREATE DATABASE $BD OWNER $ROL ENCODING 'UTF8';"
# las extensiones las crea el superusuario, dentro de ESTA base
sudo -u postgres psql -d "$BD" -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;" -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"

echo "== 3. .env"
cd "$RUTA"
# el rsync del deploy no sube storage/ ni bootstrap/cache: se crean aqui una vez
mkdir -p bootstrap/cache storage/app/private/sunat storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
# artisan necesita vendor/ (en el primer alta el deploy aun no llego a composer install)
export COMPOSER_ALLOW_SUPERUSER=1
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i "s|^APP_ENV=.*|APP_ENV=production|; s|^APP_DEBUG=.*|APP_DEBUG=false|; s|^APP_URL=.*|APP_URL=https://$DOMINIO|" .env
    sed -i "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|; s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|; s|^SESSION_DRIVER=.*|SESSION_DRIVER=database|" .env
    sed -i "s|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=*|; s|^DB_DATABASE=.*|DB_DATABASE=$BD|; s|^DB_USERNAME=.*|DB_USERNAME=$ROL|; s|^DB_PASSWORD=.*|DB_PASSWORD=${CLAVE_BD:-}|" .env
    sed -i "s|^WKHTML_PDF_BINARY=.*|WKHTML_PDF_BINARY=/usr/local/bin/wkhtmltopdf|" .env
    $PHP artisan key:generate --force
    echo "   Revisa .env: MAIL_* (SMTP real), API_TOKEN_SUNAT, APP_SOPORTE_*. APP_KEY generada: respáldala."
fi

echo "== 4. Esquema y catálogos"
leer() { grep "^$1=" .env | cut -d= -f2- | tr -d '"'; }
export PGPASSWORD="$(leer DB_PASSWORD)"
if ! psql -h 127.0.0.1 -U "$ROL" -d "$BD" -tAc "SELECT 1 FROM pg_tables WHERE tablename='empresas'" | grep -q 1; then
    # las extensiones ya las creo el superusuario (paso 2); el rol de la app no puede tocarlas
    grep -vE '^(CREATE EXTENSION|COMMENT ON EXTENSION)' database/schema/pgsql-schema.sql \
        | psql -h 127.0.0.1 -U "$ROL" -d "$BD" -v ON_ERROR_STOP=1 -q
    psql -h 127.0.0.1 -U "$ROL" -d "$BD" -v ON_ERROR_STOP=1 -q -f database/schema/catalogos.sql
    psql -h 127.0.0.1 -U "$ROL" -d "$BD" -v ON_ERROR_STOP=1 -q -f database/catalogos/ubigeos.sql
fi
for f in database/sql/*.sql; do psql -h 127.0.0.1 -U "$ROL" -d "$BD" -v ON_ERROR_STOP=1 -q -f "$f"; done
# sesiones en base de datos (SESSION_DRIVER=database)
psql -h 127.0.0.1 -U "$ROL" -d "$BD" -v ON_ERROR_STOP=1 -q -c "CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(255) PRIMARY KEY, user_id UUID, ip_address VARCHAR(45), user_agent TEXT, payload TEXT NOT NULL, last_activity INTEGER NOT NULL); CREATE INDEX IF NOT EXISTS sessions_user_id_index ON sessions (user_id); CREATE INDEX IF NOT EXISTS sessions_last_activity_index ON sessions (last_activity);"

echo "== 5. Permisos y enlaces"
mkdir -p storage/app/private/sunat storage/logs bootstrap/cache /root/backups/inkapos
chown -R www-data:www-data "$RUTA"
$PHP artisan storage:link --force

echo "== 6. nginx (solo este vhost; reload, no restart)"
cp deploy/nginx-inkapos.conf /etc/nginx/sites-available/inkapos
ln -sf /etc/nginx/sites-available/inkapos /etc/nginx/sites-enabled/inkapos
nginx -t && systemctl reload nginx
echo "   Certificado: certbot --nginx -d $DOMINIO   (en Cloudflare, SSL/TLS en 'Full (strict)')"

echo "== 7. supervisor (solo el programa inkapos-queue)"
cp deploy/supervisor-inkapos.conf /etc/supervisor/conf.d/inkapos.conf
supervisorctl reread && supervisorctl update && supervisorctl status inkapos-queue || true

echo "== 8. cron"
cp deploy/cron-inkapos /etc/cron.d/inkapos
chmod 644 /etc/cron.d/inkapos

echo "== Listo. Ahora: certbot --nginx -d $DOMINIO, completa .env y lanza el primer deploy (push a main o bash deploy/deploy.sh)."
