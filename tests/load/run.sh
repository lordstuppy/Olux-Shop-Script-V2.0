#!/usr/bin/env sh
# Load test against a disposable local database. Requires k6 on PATH (or K6=path).
# --no-reload is required for PHP_CLI_SERVER_WORKERS to take effect.
# The PHP built-in server stands in for php-fpm + nginx here; numbers from the
# Docker stack (docker compose up) are the ones to compare between releases.
set -eu
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$ROOT"
K6=${K6:-k6}
export LOAD_USERS=${LOAD_USERS:-50} LOAD_WEBHOOK_ORDERS=${LOAD_WEBHOOK_ORDERS:-500}

php artisan migrate:fresh --force
php artisan db:seed --class='Database\Seeders\LoadTestSeeder' --force

# Raised limits for this disposable environment only.
RATE_LIMIT_LOGIN_PER_IP=100000 RATE_LIMIT_LOGIN_PER_EMAIL=100000 RATE_LIMIT_CHECKOUT=100000 \
RATE_LIMIT_FORMS=100000 RATE_LIMIT_WEBHOOK=100000 QUEUE_CONNECTION=database SESSION_SECURE_COOKIE=false \
SHKEEPER_WEBHOOK_SECRET=dev-api-key LOG_LEVEL=warning PHP_CLI_SERVER_WORKERS=${WORKERS:-16} \
    php artisan serve --port=8000 --no-reload >/dev/null 2>&1 &
APP_PID=$!
trap 'kill $APP_PID 2>/dev/null || true' EXIT
sleep 3

# Both scenarios always run; the script fails at the end if either crossed a threshold.
STATUS=0
"$K6" run tests/load/checkout.js || STATUS=1
"$K6" run tests/load/webhook.js || STATUS=1

echo "Post-run integrity checks:"
php artisan tinker --execute '
$dup = DB::table("orders")->select("buyer_id", "idempotency_key")->groupBy("buyer_id", "idempotency_key")->havingRaw("COUNT(*) > 1")->count();
$sold = (int) DB::table("order_items")->join("orders", "orders.id", "=", "order_items.order_id")->where("orders.idempotency_key", "not like", "load-%")->sum("quantity");
$stock = (int) DB::table("products")->where("slug", "load-test-product")->value("stock");
$neg = DB::table("users")->where("balance_minor", "<", 0)->count();
$paidWebhook = DB::table("orders")->where("idempotency_key", "like", "load-%")->whereIn("status", ["paid", "delivered"])->count();
$ledger = DB::table("seller_ledger_entries")->where("type", "sale")->count();
$paidAll = DB::table("orders")->whereIn("status", ["paid", "delivered"])->count();
echo "duplicate idempotency keys: $dup\n";
echo "units sold: $sold, stock left: $stock, stock + sold = ".($stock + $sold)." (expected 1000000)\n";
echo "negative balances: $neg\n";
echo "webhook orders paid: $paidWebhook of ".getenv("LOAD_WEBHOOK_ORDERS")."\n";
echo "sale ledger entries: $ledger for $paidAll paid orders\n";
$events = DB::table("webhook_events")->select("status", DB::raw("COUNT(*) AS c"))->groupBy("status")->pluck("c", "status")->toJson();
echo "webhook events by status: $events\n";
'
exit $STATUS
