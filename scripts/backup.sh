#!/usr/bin/env sh
# Backs up the PostgreSQL database and private files (product files, invoices).
# Usage: scripts/backup.sh [backup-dir]
# Reads DB_* from the environment (or .env). Run from the application root.
# Schedule it (for example daily via cron) and copy the output off-host.
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
echo "Backup written to $TARGET"
