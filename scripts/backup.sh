#!/usr/bin/env sh
# Backs up the PostgreSQL database and private files (product files, images, invoices).
# Usage: scripts/backup.sh [backup-dir]
# Reads DB_* from the environment (or .env). Run from the application root.
#
# Optional environment:
#   BACKUP_AGE_RECIPIENT  age public key; files are encrypted and the plaintext removed
#   BACKUP_RCLONE_REMOTE  rclone destination (e.g. "s3:shop-backups"); the encrypted set is uploaded
#   BACKUP_KEEP_DAYS      delete local backup directories older than this (default 14)
# The "backup" Compose profile runs this daily with cron.
set -eu

DEST=${1:-backups}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
TARGET="$DEST/$STAMP"
mkdir -p "$TARGET"

# Reads DB_* keys from .env without executing it; variables already set in
# the environment take precedence.
load_db_env() {
    [ -f .env ] || return 0
    while IFS='=' read -r key value; do
        case "$key" in DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD) ;; *) continue ;; esac
        eval "current=\${$key:-}"
        [ -n "$current" ] && continue
        value=$(printf '%s' "$value" | sed -e 's/^"//' -e 's/"$//')
        export "$key=$value"
    done < .env
}
load_db_env

export PGPASSWORD="${DB_PASSWORD:-}"
pg_dump --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-5432}" --username="${DB_USERNAME:-shop}" \
    --format=custom --no-owner --file="$TARGET/database.dump" "${DB_DATABASE:-shop}"

tar -czf "$TARGET/files.tar.gz" storage/app/private storage/invoices

( cd "$TARGET" && sha256sum database.dump files.tar.gz > SHA256SUMS )

if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then
    for f in database.dump files.tar.gz; do
        age -r "$BACKUP_AGE_RECIPIENT" -o "$TARGET/$f.age" "$TARGET/$f"
        rm -f "$TARGET/$f"
    done
    echo "Encrypted with age for $BACKUP_AGE_RECIPIENT"
fi

if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
    rclone copy "$TARGET" "$BACKUP_RCLONE_REMOTE/$STAMP"
    echo "Uploaded to $BACKUP_RCLONE_REMOTE/$STAMP"
fi

KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}
find "$DEST" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +

echo "Backup written to $TARGET"
