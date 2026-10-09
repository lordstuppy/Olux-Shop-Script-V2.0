#!/usr/bin/env sh
# Runs the browser checks against a freshly seeded local shop.
# Requires: PostgreSQL reachable per .env, PHP, Node 18+.
set -eu
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$ROOT"

php artisan migrate:fresh --seed --force
MOCK_STATE_FILE="${TMPDIR:-/tmp}/shkeeper-mock-$$.json" php -S 127.0.0.1:8081 docker/shkeeper-mock/server.php >/dev/null 2>&1 &
MOCK_PID=$!
QUEUE_CONNECTION=sync SHKEEPER_BASE_URL=http://127.0.0.1:8081 SHKEEPER_API_KEY=dev-api-key SHKEEPER_WEBHOOK_SECRET=dev-api-key \
    SESSION_SECURE_COOKIE=false PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8000 --no-reload >/dev/null 2>&1 &
APP_PID=$!
trap 'kill $APP_PID $MOCK_PID 2>/dev/null || true' EXIT
sleep 2

cd tests/browser
npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund
SHKEEPER_MOCK_URL=http://127.0.0.1:8081 npx playwright test "$@"
