#!/usr/bin/env bash
# Backup diario: base de datos + storage (XML/CDR de SUNAT, logos, imágenes) + .env.
# SUNAT exige conservar los comprobantes electrónicos; copia estos archivos fuera del servidor
# (rclone a S3/Backblaze/Drive) o móntalos en un disco aparte.
#   DESTINO=/root/backups/inkapos RETENCION_DIAS=14 bash deploy/backup.sh   (lo llama /etc/cron.d/inkapos)
set -euo pipefail

cd "$(dirname "$0")/.."

DESTINO="${DESTINO:-/root/backups/inkapos}"
RETENCION_DIAS="${RETENCION_DIAS:-14}"
FECHA="$(date +%Y%m%d-%H%M)"

leer() { grep "^$1=" .env | cut -d= -f2- | tr -d '"'; }

mkdir -p "$DESTINO"

echo "== Base de datos"
PGPASSWORD="$(leer DB_PASSWORD)" pg_dump -h "$(leer DB_HOST)" -U "$(leer DB_USERNAME)" -d "$(leer DB_DATABASE)" \
    --format=custom --file="$DESTINO/inkapos-$FECHA.dump"

echo "== Storage y .env"
tar -czf "$DESTINO/inkapos-storage-$FECHA.tar.gz" storage/app .env

echo "== Limpieza (> $RETENCION_DIAS días)"
find "$DESTINO" -type f -mtime +"$RETENCION_DIAS" -delete

echo "OK $FECHA -> $DESTINO"
# Restaurar la base:  pg_restore -h HOST -U USUARIO -d inkapos --clean --if-exists inkapos-FECHA.dump
# Restaurar storage:  tar -xzf inkapos-storage-FECHA.tar.gz -C /var/www/inkapos
