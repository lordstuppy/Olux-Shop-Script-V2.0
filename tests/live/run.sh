#!/usr/bin/env sh
# Live end-to-end test on a production-like stack, built from the committed
# code (git HEAD) in a separate work directory:
#   nginx (TLS on 8443, internal plain HTTP on 8080) -> app on 127.0.0.1:8000
#   queue worker, scheduler, PostgreSQL 15 (port 5433), Shkeeper mock (8081),
#   a fake clamd (3310, flags EICAR) and an SMTP sink (2525).
# The app runs with APP_ENV=production, cached config/routes/views, Secure
# session cookies, Argon2id, required virus scanning and SMTP mail.
#
# Usage: tests/live/run.sh [all|up|test|restart|down]
#   all (default)  up + test
#   up             build the work dir and start everything (keeps the database)
#   test           reset to a fresh install and run every scenario
#   restart        restart the app processes with a fresh config cache
#   down           stop processes and containers
# Needs: docker, php 8.3, composer, python3, openssl, curl, pg_dump/pg_restore.
# PHP's built-in server stands in for PHP-FPM behind nginx.
set -eu
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
HERE="$ROOT/tests/live"
W=${LIVE_DIR:-/tmp/shop-live}
CMD=${1:-all}

stop_procs() {
    [ -f "$W/pids" ] || return 0
    while read -r pid; do kill -TERM -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true; done < "$W/pids"
    rm -f "$W/pids"
    sleep 1
}

start_procs() {
    cd "$W/app"
    php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q
    setsid nohup python3 -I "$HERE/smtpsink.py" "$W/logs/mail.txt" > "$W/logs/smtp.log" 2>&1 &
    echo $! >> "$W/pids"
    setsid nohup python3 -I "$HERE/fakeclamd.py" > "$W/logs/clamd.log" 2>&1 &
    echo $! >> "$W/pids"
    PHP_CLI_SERVER_WORKERS=8 setsid nohup php artisan serve --host=127.0.0.1 --port=8000 --no-reload > "$W/logs/web.log" 2>&1 &
    echo $! >> "$W/pids"
    setsid nohup php artisan queue:work --tries=1 --sleep=1 > "$W/logs/queue.log" 2>&1 &
    echo $! >> "$W/pids"
    setsid nohup php artisan schedule:work > "$W/logs/scheduler.log" 2>&1 &
    echo $! >> "$W/pids"
    sleep 2
}

up() {
    mkdir -p "$W/app" "$W/tls" "$W/logs" "$W/backups"
    (cd "$ROOT" && git archive HEAD | tar -x -C "$W/app")
    if [ ! -f "$W/app/vendor/autoload.php" ]; then
        # Reuse the local vendor tree when present (works offline), then drop dev packages.
        [ -d "$ROOT/vendor" ] && (cd "$ROOT" && tar -c --exclude=.git vendor | tar -x -C "$W/app")
        (cd "$W/app" && composer install --no-dev --classmap-authoritative --no-interaction --quiet)
    fi
    if [ ! -f "$W/tls/cert.pem" ]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=localhost" \
            -addext "subjectAltName=DNS:localhost" -keyout "$W/tls/key.pem" -out "$W/tls/cert.pem" 2>/dev/null
    fi
    key=$(grep '^APP_KEY=base64' "$W/app/.env" 2>/dev/null || true)
    cp "$HERE/app.env" "$W/app/.env"
    if [ -n "$key" ]; then
        sed -i "s#^APP_KEY=.*#$key#" "$W/app/.env"
    else
        (cd "$W/app" && php artisan key:generate --force -q)
    fi
    docker inspect shoplive-db >/dev/null 2>&1 || docker run -d --name shoplive-db --network host \
        -e POSTGRES_USER=shop -e POSTGRES_PASSWORD=live-db-pass -e POSTGRES_DB=shop -e PGPORT=5433 postgres:15-alpine >/dev/null
    docker start shoplive-db >/dev/null
    until docker exec shoplive-db pg_isready -p 5433 -q; do sleep 1; done
    docker rm -f shoplive-nginx shoplive-mock >/dev/null 2>&1 || true
    docker run -d --name shoplive-mock --network host -e MOCK_API_KEY=live-api-key-0123456789 -e MOCK_STATE_FILE=/tmp/state.json \
        -v "$ROOT/docker/shkeeper-mock:/mock:ro" php:8.3-cli-alpine php -S 127.0.0.1:8081 /mock/server.php >/dev/null
    docker run -d --name shoplive-nginx --network host -v "$HERE/nginx.conf:/etc/nginx/conf.d/default.conf:ro" \
        -v "$W/tls:/etc/nginx/tls:ro" nginx:1.27-alpine >/dev/null
    (cd "$W/app" && php artisan migrate --force -q)
    stop_procs
    start_procs
    echo "Stack is up: https://localhost:8443 (work dir $W)"
}

case "$CMD" in
    all) up; python3 -I "$HERE/reset.py" "$W" && python3 -I "$HERE/live_test.py" "$W" ;;
    up) up ;;
    test) python3 -I "$HERE/reset.py" "$W" && python3 -I "$HERE/live_test.py" "$W" ;;
    restart) stop_procs; start_procs ;;
    down) stop_procs; docker rm -f shoplive-nginx shoplive-mock shoplive-db >/dev/null 2>&1 || true; echo "Stopped." ;;
    *) echo "Usage: $0 [all|up|test|restart|down]" >&2; exit 2 ;;
esac
