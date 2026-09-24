#!/usr/bin/env bash
# Backup diario: base de datos + storage (XML/CDR de SUNAT, logos, imágenes) + .env.
# SUNAT exige conservar los comprobantes electrónicos; copia estos archivos fuera del servidor
# (rclone a S3/Backblaze/Drive) o móntalos en un disco aparte.
#   DESTINO=/var/backups/pos-app RETENCION_DIAS=30 ./deploy/backup.sh
set -euo pipefail

cd "$(dirname "$0")/.."

DESTINO="${DESTINO:-/var/backups/pos-app}"
RETENCION_DIAS="${RETENCION_DIAS:-30}"
FECHA="$(date +%Y%m%d-%H%M)"

leer() { grep "^$1=" .env | cut -d= -f2- | tr -d '"'; }

mkdir -p "$DESTINO"

echo "== Base de datos"
PGPASSWORD="$(leer DB_PASSWORD)" pg_dump -h "$(leer DB_HOST)" -U "$(leer DB_USERNAME)" -d "$(leer DB_DATABASE)" \
    --format=custom --file="$DESTINO/pos_db-$FECHA.dump"

echo "== Storage y .env"
tar -czf "$DESTINO/storage-$FECHA.tar.gz" storage/app .env

echo "== Limpieza (> $RETENCION_DIAS días)"
find "$DESTINO" -type f -mtime +"$RETENCION_DIAS" -delete

echo "OK $FECHA -> $DESTINO"
# Restaurar la base:  pg_restore -h HOST -U USUARIO -d pos_db --clean --if-exists pos_db-FECHA.dump
# Restaurar storage:  tar -xzf storage-FECHA.tar.gz -C /var/www/pos-app
