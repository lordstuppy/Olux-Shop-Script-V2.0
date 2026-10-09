#!/bin/sh
# Container start-up: cache configuration and routes from the runtime
# environment (bootstrap/cache is a tmpfs because the root filesystem is
# read-only), optionally run migrations, then exec the given command.
set -eu
cd /var/www/html

php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
