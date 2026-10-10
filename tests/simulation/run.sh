#!/usr/bin/env sh
# Hostile-conditions simulation (docs/SIMULATION.md).
#
# Usage: tests/simulation/run.sh <command> [args]
#   up                 start the stack (fresh database, seeded)
#   reset              fresh database + simulation seed, restart workers
#   test [args]        run phases (see simulate.py --help), e.g. test --runs 5
#   load               phase 7 (k6)
#   down               stop and remove everything
#   stop-service S / start-service S / restart-workers   used by the phases
#
# SIM_BACKEND=compose (default): docker-compose.yml + docker-compose.test.yml,
#   the production image with PHP-FPM. Needs the image to build.
# SIM_BACKEND=host: same containers for TLS, PostgreSQL 15, Mailpit and the
#   Shkeeper mock; the app, queue worker and scheduler run as host PHP
#   processes from a copy of the committed code (git HEAD) in SIM_WORK.
set -eu
export PYTHONDONTWRITEBYTECODE=1
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
HERE="$ROOT/tests/simulation"
BACKEND=${SIM_BACKEND:-compose}
W=${SIM_WORK:-$HERE/work}
CMD=${1:-up}
[ $# -gt 0 ] && shift
COMPOSE="docker compose -p shopsim -f $ROOT/docker-compose.yml -f $ROOT/docker-compose.test.yml --profile test"

tls_certs() {
    mkdir -p "$HERE/tls"
    if [ ! -f "$HERE/tls/cert.pem" ]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=localhost" \
            -addext "subjectAltName=DNS:localhost" -keyout "$HERE/tls/key.pem" -out "$HERE/tls/cert.pem" 2>/dev/null
        chmod 644 "$HERE/tls/key.pem"
    fi
}

# ---- host backend -----------------------------------------------------------

host_stop_procs() {
    host_stop_queue
    [ -f "$W/pids" ] || return 0
    while read -r pid; do kill -TERM -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true; done < "$W/pids"
    rm -f "$W/pids"
    sleep 1
}

host_start_procs() {
    cd "$W/app"
    php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q
    : > "$W/pids"
    setsid nohup python3 -I "$ROOT/docker/test/fakeclamd.py" > "$W/logs/clamd.log" 2>&1 &
    echo $! >> "$W/pids"
    # PHP's built-in server with the production image's php.ini limits (uploads 50M, posts 55M).
    (cd public && PHP_CLI_SERVER_WORKERS=16 setsid nohup php -d upload_max_filesize=50M -d post_max_size=55M -d memory_limit=256M -d expose_php=Off \
        -d max_execution_time=30 -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > "$W/logs/web.log" 2>&1 & echo $! >> "$W/pids")
    host_start_queue
    setsid nohup php artisan schedule:work > "$W/logs/scheduler.log" 2>&1 &
    echo $! >> "$W/pids"
    sleep 2
}

host_start_queue() {
    cd "$W/app"
    setsid nohup php artisan queue:work --tries=1 --sleep=1 > "$W/logs/queue.log" 2>&1 &
    echo $! > "$W/queue.pid"
}

host_stop_queue() {
    [ -f "$W/queue.pid" ] && { kill -KILL -- "-$(cat "$W/queue.pid")" 2>/dev/null || kill -KILL "$(cat "$W/queue.pid")" 2>/dev/null || true; rm -f "$W/queue.pid"; }
    return 0
}

host_up() {
    mkdir -p "$W/app" "$W/logs" "$W/backups"
    (cd "$ROOT" && git archive HEAD | tar -x -C "$W/app")
    if [ ! -f "$W/app/vendor/autoload.php" ]; then
        [ -d "$ROOT/vendor" ] && (cd "$ROOT" && tar -c --exclude=.git vendor | tar -x -C "$W/app")
        (cd "$W/app" && composer install --no-dev --classmap-authoritative --no-interaction --quiet)
    fi
    # New classes since the last deploy must be in the authoritative class map.
    (cd "$W/app" && composer dump-autoload --no-dev --classmap-authoritative --no-interaction --quiet)
    key=$(grep '^APP_KEY=base64' "$W/app/.env" 2>/dev/null || true)
    cp "$HERE/host.env" "$W/app/.env"
    if [ -n "$key" ]; then sed -i "s#^APP_KEY=.*#$key#" "$W/app/.env"; else (cd "$W/app" && php artisan key:generate --force -q); fi
    tls_certs
    docker inspect shopsim-db >/dev/null 2>&1 || docker run -d --name shopsim-db --network host \
        -e POSTGRES_USER=shop -e POSTGRES_PASSWORD=shop -e POSTGRES_DB=shop -e PGPORT=5433 postgres:15-alpine \
        postgres -c log_min_duration_statement=200 -c log_statement=mod -c "log_line_prefix=%m [%p] %u@%d " >/dev/null
    docker start shopsim-db >/dev/null
    until docker exec shopsim-db pg_isready -p 5433 -q; do sleep 1; done
    docker rm -f shopsim-tls shopsim-mock shopsim-mailpit >/dev/null 2>&1 || true
    docker run -d --name shopsim-mailpit --network host axllent/mailpit:v1.21.8 >/dev/null
    host_start_mock
    docker run -d --name shopsim-tls --network host -v "$ROOT/docker/test/host-tls.conf:/etc/nginx/conf.d/default.conf:ro" \
        -v "$HERE/tls:/etc/nginx/tls:ro" nginx:1.27-alpine >/dev/null
    (cd "$W/app" && php artisan migrate --force -q)
    host_stop_procs
    host_start_procs
}

host_start_mock() {
    docker rm -f shopsim-mock >/dev/null 2>&1 || true
    docker run -d --name shopsim-mock --network host -e MOCK_API_KEY=sim-api-key-0123456789 -e MOCK_STATE_FILE=/tmp/state.json \
        -e PHP_CLI_SERVER_WORKERS=8 -v "$ROOT/docker/shkeeper-mock:/mock:ro" php:8.3-cli-alpine php -S 127.0.0.1:8081 /mock/server.php >/dev/null
}

host_reset() {
    host_stop_procs
    rm -rf "$W/app/storage/app/private" "$W/app/storage/invoices"
    rm -f "$W/app/storage/logs/shop-"*.log
    (cd "$W/app" && php artisan migrate:fresh --force -q && \
        SHOP_SIMULATION=allow-known-passwords php artisan db:seed --class='Database\Seeders\SimulationSeeder' --force -q)
    host_start_procs
}

# ---- compose backend --------------------------------------------------------

compose_up() {
    [ -f "$ROOT/.env" ] || cp "$ROOT/.env.example" "$ROOT/.env"
    if [ ! -f "$HERE/sim.env" ]; then
        printf 'APP_KEY=base64:%s\nSHOP_SIMULATION=allow-known-passwords\n' "$(openssl rand -base64 32)" > "$HERE/sim.env"
    fi
    tls_certs
    $COMPOSE up -d --build
    until $COMPOSE exec -T db pg_isready -q 2>/dev/null; do sleep 1; done
    until $COMPOSE exec -T app php artisan migrate:status >/dev/null 2>&1; do sleep 2; done
}

compose_reset() {
    $COMPOSE stop queue scheduler >/dev/null
    $COMPOSE exec -T app sh -c 'rm -rf storage/app/private/products/* storage/app/private/product-images storage/invoices/* storage/logs/shop-*.log'
    $COMPOSE exec -T app php artisan migrate:fresh --force -q
    $COMPOSE exec -T -e SHOP_SIMULATION=allow-known-passwords app php artisan db:seed --class='Database\Seeders\SimulationSeeder' --force -q
    $COMPOSE start queue scheduler >/dev/null
}

# ---- dispatch -----------------------------------------------------------------

case "$BACKEND:$CMD" in
    host:up) host_up; host_reset ;;
    compose:up) compose_up; compose_reset ;;
    host:reset) host_reset ;;
    compose:reset) compose_reset ;;
    host:restart-workers) host_stop_procs; host_start_procs ;;
    compose:restart-workers) $COMPOSE restart queue scheduler >/dev/null ;;
    host:stop-service)
        case "$1" in
            db) docker stop shopsim-db >/dev/null ;;
            mock) docker stop shopsim-mock >/dev/null ;;
            queue) host_stop_queue ;;
            *) echo "unknown service $1" >&2; exit 2 ;;
        esac ;;
    host:start-service)
        case "$1" in
            db) docker start shopsim-db >/dev/null; until docker exec shopsim-db pg_isready -p 5433 -q; do sleep 1; done ;;
            mock) host_start_mock ;;
            queue) host_start_queue ;;
            *) echo "unknown service $1" >&2; exit 2 ;;
        esac ;;
    compose:stop-service)
        if [ "$1" = queue ]; then $COMPOSE kill queue >/dev/null; else $COMPOSE stop "$( [ "$1" = mock ] && echo shkeeper-mock || echo "$1")" >/dev/null; fi ;;
    compose:start-service)
        $COMPOSE start "$( [ "$1" = mock ] && echo shkeeper-mock || echo "$1")" >/dev/null
        [ "$1" = db ] && until $COMPOSE exec -T db pg_isready -q 2>/dev/null; do sleep 1; done; true ;;
    *:test) cd "$ROOT" && SIM_BACKEND=$BACKEND SIM_WORK=$W python3 -I "$HERE/simulate.py" "$@" ;;
    *:load) cd "$ROOT" && SIM_BACKEND=$BACKEND sh "$HERE/load/run.sh" "$@" ;;
    host:down)
        host_stop_procs
        docker rm -f shopsim-tls shopsim-mock shopsim-mailpit shopsim-db >/dev/null 2>&1 || true
        echo "Stopped." ;;
    compose:down) $COMPOSE down -v ;;
    *) echo "Usage: $0 [up|reset|test|load|down|stop-service S|start-service S|restart-workers]" >&2; exit 2 ;;
esac
