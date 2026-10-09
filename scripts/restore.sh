#!/usr/bin/env sh
# Restores a backup created by scripts/backup.sh into the configured database.
# Usage: scripts/restore.sh backups/<timestamp>
# Encrypted backups (*.age) need BACKUP_AGE_IDENTITY pointing to the age private key file.
# DESTRUCTIVE: replaces database objects and private files. Put the shop in
# maintenance mode first (php artisan down) and stop queue workers.
set -eu

SRC=${1:?usage: scripts/restore.sh <backup-dir>}

if [ -f "$SRC/database.dump.age" ]; then
    [ -n "${BACKUP_AGE_IDENTITY:-}" ] || { echo "Encrypted backup: set BACKUP_AGE_IDENTITY to the age key file" >&2; exit 1; }
    WORK=$(mktemp -d)
    trap 'rm -rf "$WORK"' EXIT
    for f in database.dump files.tar.gz; do
        age -d -i "$BACKUP_AGE_IDENTITY" -o "$WORK/$f" "$SRC/$f.age"
    done
    cp "$SRC/SHA256SUMS" "$WORK/"
    SRC="$WORK"
fi
[ -f "$SRC/database.dump" ] || { echo "No database.dump in $SRC" >&2; exit 1; }

( cd "$SRC" && sha256sum -c SHA256SUMS )

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

printf 'Restore %s into database %s? Type "restore" to continue: ' "$SRC" "${DB_DATABASE:-shop}"
read -r answer
[ "$answer" = "restore" ] || { echo "Aborted."; exit 1; }

export PGPASSWORD="${DB_PASSWORD:-}"
pg_restore --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-5432}" --username="${DB_USERNAME:-shop}" \
    --dbname="${DB_DATABASE:-shop}" --clean --if-exists --no-owner "$SRC/database.dump"

tar -xzf "$SRC/files.tar.gz"
php artisan optimize:clear
echo "Restore complete. Run php artisan shop:reconcile-payouts, then php artisan up."
