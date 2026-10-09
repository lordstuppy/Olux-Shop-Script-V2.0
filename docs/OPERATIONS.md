# Operations Guide

## Processes

| Process | Command | Notes |
|---|---|---|
| Web | PHP-FPM behind nginx (`docker/nginx/default.conf`) | Only `public/index.php` is executable |
| Queue worker | `php artisan queue:work --tries=1` | Delivery, invoices, emails, webhook retries. Jobs define their own retries and backoff |
| Scheduler | `php artisan schedule:work` (or cron `* * * * * php artisan schedule:run`) | See the table below |

Scheduled tasks (`routes/console.php`):

| Task | Frequency | Purpose |
|---|---|---|
| `shop:expire-orders` | every minute | Expires unpaid orders past `SHOP_ORDER_TTL_MINUTES`; releases stock and coupon reservations |
| `shop:reconcile-payments` | every 5 minutes | Polls Shkeeper invoice status for pending payments (covers lost webhooks) |
| `shop:retry-webhooks` | every minute | Re-queues failed webhook events whose backoff elapsed |
| `shop:reconcile-payouts` | daily 03:15 | Seller ledger vs completed orders and payouts; exits 1 and logs errors on mismatch |
| `queue:prune-failed` | daily | Keeps failed jobs for 30 days |
| `auth:clear-resets` | hourly | Removes expired password reset tokens |

## Shkeeper

Shkeeper is installed as a set of containers on Kubernetes (k3s) with Helm.
Follow the upstream instructions at
<https://github.com/vsys-host/shkeeper.io>: install k3s and Helm, enable the
coins you want in `values.yaml`, then
`helm install -f values.yaml shkeeper vsys-host/shkeeper`. Put it behind TLS,
and restrict access to its admin UI.

Then configure the shop:

1. Generate an API key in the Shkeeper admin UI. Set `SHKEEPER_API_KEY`, and
   set `SHKEEPER_WEBHOOK_SECRET` to the same value: Shkeeper signs callbacks
   with the API key.
2. Set `SHKEEPER_BASE_URL` to the Shkeeper URL that the shop can reach.
3. The callback URL is sent with each invoice. It defaults to
   `https://<APP_URL host>/webhooks/shkeeper`. Set `SHKEEPER_CALLBACK_URL` if
   Shkeeper must use a different hostname.
4. Make sure the shop's clock is NTP-synchronised. Callbacks older than
   `SHKEEPER_WEBHOOK_TOLERANCE` (300 s) are rejected.

Shkeeper re-sends a callback every 60 seconds until it receives HTTP 202, and
it sends one callback per transaction, even after an invoice is paid. Both
cases are handled idempotently.

## Payment incidents

| Symptom | Where to look | Action |
|---|---|---|
| Buyer paid, order still pending | `/admin/orders/{id}` payments table; `/admin/webhooks` | If no event arrived, check Shkeeper can reach the callback URL and run `php artisan shop:reconcile-payments`. If the payment is `rejected`, read `failure_reason` (amount or currency mismatch) |
| Webhook events `failed` / `dead` | `/admin/webhooks`, log `Webhook event ... failed` | Fix the cause (database, mail), then use "Retry now" |
| Paid but not delivered | log `Failed to deliver product ...`; `delivery.failed` in the audit log | Add licence keys or files, then run `php artisan queue:retry all` or deliver manually |
| Late payment on an expired order | `payment.late_credited` / `payment.late_unresolved` in the audit log | Credited to the buyer balance; unresolved cases need a manual refund |
| 401 on webhooks | log `Webhook signature mismatch from ip ...` | Check `SHKEEPER_WEBHOOK_SECRET` and the clock |

## Refunds and payouts

- **Refunds:** `/admin/orders/{id}` records full or partial refunds.
  - Each refund is a `payments` row of kind `refund` linked to the charge.
  - "Balance" credits the buyer's balance.
  - "Manual" records an outgoing transfer made outside the shop, with its
    transaction reference.
  - Seller earnings are reduced in the ledger automatically.
- **Payouts:**
  - Sellers request payouts from earnings older than `SHOP_PAYOUT_HOLD_DAYS`.
  - Admins approve, mark paid (with the transaction reference) or reject
    them; rejecting returns the amount to the seller's balance.
  - Run `/admin/reconciliation` before paying.

## Logging and observability

- **Logs:** JSON lines in `storage/logs/shop-YYYY-MM-DD.log`, kept for
  `LOG_DAILY_DAYS` days. Every line carries `request_id`, which also appears
  in the `X-Request-Id` header, on error pages and in audit entries. Ship the
  files to your log system: Loki, ELK, or a self-hosted Sentry or GlitchTip
  via a log forwarder.
- **Health:** `GET /health` returns JSON with database status and latency,
  pending jobs and failed jobs. It answers 503 when the database is down.
  Use it for load balancer and container health checks.
- **Metrics:** `GET /metrics` with `Authorization: Bearer $METRICS_TOKEN`
  serves Prometheus text format. It covers orders by status, payments by
  provider and status, webhook events by status, net revenue per currency,
  queue backlog, failed jobs, users, and `shop_exceptions_total`.
- **Alerts to configure:**
  - `/health` not OK.
  - `shop_webhook_events{status="dead"} > 0`.
  - `shop_queue_failed_jobs` increasing.
  - `shop_exceptions_total` rate.
  - `shop:reconcile-payouts` exiting non-zero.

## Backups

`scripts/backup.sh [dir]` writes a timestamped directory containing:
- `database.dump`, from `pg_dump` in custom format;
- `files.tar.gz`, with product files and invoices;
- `SHA256SUMS`.

Run it at least daily from cron and copy the output off-host, encrypted. Keep
daily backups for 14 days and monthly ones for 12 months, or whatever your
data retention policy requires.

Restore procedure (test it quarterly on a staging host):

1. `php artisan down` and stop the queue workers and the scheduler.
2. `scripts/restore.sh backups/<timestamp>`. It verifies the checksums and
   asks for confirmation before replacing data.
3. `php artisan migrate --force`, in case the backup predates the current
   schema.
4. `php artisan shop:reconcile-payouts`, and check `/admin/webhooks` for
   events received after the backup was taken.
5. `php artisan up`. Run `php artisan shop:reconcile-payments` to pick up
   payments made while the shop was down.

`APP_KEY` must be the same as when the backup was taken. Otherwise encrypted
licence keys, delivery payloads and gift card hashes cannot be read. Store it
separately from the backups.

## Data retention (technical defaults)

| Data | Default retention | Where configured |
|---|---|---|
| Application logs | 30 days | `LOG_DAILY_DAYS` |
| Failed jobs | 30 days | `routes/console.php` |
| Password reset tokens | 60 minutes | `config/auth.php` |
| Sessions | 120 minutes idle | `SESSION_LIFETIME` |
| Orders, payments, invoices, audit log | kept (accounting) | define in your retention policy |

Adjust these to match the published data retention policy page.
