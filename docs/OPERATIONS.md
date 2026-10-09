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
| `shop:reconcile-shkeeper-transfers` | every 5 minutes | Polls Shkeeper for payouts and crypto refunds whose callback has not arrived |
| `shop:subscription-reminders` | hourly | Emails buyers whose subscription access ends within `SHOP_RENEWAL_REMINDER_DAYS` |
| `shop:escalate-disputes` | every 15 minutes | Hands disputes to staff when the seller missed the response deadline |
| `shop:prune-gateway-logs` | daily 04:10 | Deletes gateway log entries older than 90 days |
| `shop:prune-saved-carts` | daily 04:20 | Deletes saved carts not changed for 30 days (`SHOP_SAVED_CART_DAYS`) |
| health heartbeats | every minute / 5 minutes | Scheduler heartbeat, and a no-op queue job so `/admin/health` can tell an idle worker from a stopped one |
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

### Automatic payouts and crypto refunds (optional)

Set `SHKEEPER_PAYOUTS_ENABLED=true`, `SHKEEPER_PAYOUT_USERNAME` and
`SHKEEPER_PAYOUT_PASSWORD` (a Shkeeper login; the payout API uses HTTP Basic
auth), and `SHKEEPER_PAYOUT_FEES` (for example `BTC:10,LTC:10`). Enable the
payout callback in Shkeeper; it is sent to `/webhooks/shkeeper/payouts`
(override with `SHKEEPER_PAYOUT_CALLBACK_URL`) and verified like payment
callbacks.

Flow: seller requests a payout, then staff approve it, then "Send via
Shkeeper" (a fresh quote converts the fiat amount to the seller's payout
crypto). The payout stays `processing` until the callback or the status poll
reports success (it is then marked paid with the transaction hash) or
failure (staff can resend or reject it). Crypto refunds follow the same path
from the admin order page. The refund is booked only after Shkeeper confirms
it, and while it is pending it counts against the refundable amount. Keep
the Shkeeper payout wallet funded only with what you intend to pay out.

## Accounts and roles

- Create the first admin: `php artisan shop:create-admin admin@example.com`.
  The `admin` role is shown as **Super admin**.
- Every staff account must turn on two-factor authentication. Without it, staff have no abilities anywhere, including the shared order, ticket and dispute pages.
- Staff tiers (change them at `/admin/users/{id}`; only super admins can change roles or suspend staff):

| Tier | Can |
|---|---|
| Super admin | Everything: settings, payment gateway, commission rates, roles, plus all below |
| Manager | Operations: orders and refunds, products, categories, sellers, users (not staff), payouts, disputes, reports, exports, email templates, audit log, system health |
| Finance | Money: payments, refunds, payouts, coupons, gift cards, exchange rates, balance adjustments, reports, exports, gateway log, system health |
| Moderator | Content: product review and bulk status, categories, reviews, disputes, tickets, announcements; orders and users read-only |
| Support | Tickets, announcements, review moderation, session sign-out; orders and users read-only |

The exact map is `App\Support\Permissions`.

- **Runtime settings** (super admin, `/admin/settings`):
  - Commission default, payout hold, minimum payout, order lifetime, open-order cap, download limit, quote validity, reminders, payout cool-down, dispute window and seller response time, support email.
  - They override `.env` and are audited.
  - They are never baked into the config cache, and queue workers re-read them before every job.

## Commission (how the platform earns)

The platform keeps a commission on every sale; the seller's ledger gets
the rest. The rate is resolved per order line, and the most specific level wins:

1. A product override, set on `/admin/commission`.
2. The seller's rate, set at approval or on `/admin/commission`.
3. The category rate.
4. The global default (`commission_bps` in Settings).

The resolved rate is frozen on the order line, so later changes never
touch past sales. Refunds reduce the commission proportionally. Only super
admins change rates (password confirmation, audited). The dashboard shows
the commission earned per period.

## Disputes

1. **Opening.** A buyer opens a dispute on one purchased line from the order
   page, within `dispute_window_days` (default 14) of delivery, or of payment
   if nothing was delivered. They give a reason and ask for a refund or a
   replacement.
2. **Seller response.** The seller has `dispute_response_days` (default 3)
   to answer. After that, `shop:escalate-disputes` hands the case to staff.
   While the case is open, the seller's earnings for the line are held from
   payouts.
3. **Resolution.** Staff with `disputes.manage` (super admin, manager,
   moderator) resolve it on `/disputes/{id}`:
   - **Refund:** of that line only, to the balance, manually, or in crypto
     via Shkeeper (booked when Shkeeper confirms).
   - **Replacement:** fresh licence keys from stock, fresh download links,
     or new delivery text.
   - **Rejection:** with a reason.

Internal notes are staff-only. Every step is audited and emailed (staff
alerts go to the support email).

## Payment gateway page

`/admin/gateway` (view: super admin, manager, finance; change: super admin):

- **Payment methods:** switch crypto and balance payments on or off and
  choose the accepted coins. Checkout, the payment page and the services
  enforce these switches.
- **Order limits:** minimum and maximum order totals in the default
  currency. Orders in other currencies are converted with the exchange
  rate; without a rate the limits are not applied.
- **Connection:** the settings from `.env`, shown read-only; secrets are
  shown only as "configured" or "missing". A button tests the connection.
- **Gateway log:** every payment and payout callback (accepted, duplicate,
  rejected signature or address, bad request) and every Shkeeper API call
  (ok, error, timeout, with duration and request id). Filterable; kept 90 days.

## Email templates

`/admin/email-templates` (super admin, manager) edits the subject and
plain-text body of every shop email:

- **Placeholders:** texts use the `{placeholders}` listed next to the
  editor. Unknown placeholders are refused, and emails with a confirmation
  or action link must keep it.
- **Preview and reset:** "Preview" fills in sample data without saving.
  "Reset" returns to the built-in, translatable text.
- **Scope:** an edited text is single-language and used for every
  recipient of that email.

## System health

`/admin/health` (super admin, manager, finance) shows the following, each
with an OK, Check or Problem label:

- **Database:** connection, latency, size, pending migrations.
- **Background work:** queue worker and scheduler heartbeats, waiting jobs
  and the age of the oldest, failed jobs.
- **Storage:** free disk space and the size of uploaded product files,
  writability.
- **Payments and scanning:** the last successful Shkeeper webhook and API
  call, ClamAV reachability.
- **Application:** debug mode, HTTPS and config cache in production.

Machines should use `/health` and `/metrics` instead.

## Bulk actions

The product, user and order lists have a bulk form. Without JavaScript,
the row checkboxes join it through the HTML `form` attribute.

- **Scope:** an action applies to the ticked rows, or to every row
  matching the current filter, up to 500 at a time.
- **Products:** set to active, disabled or pending review. Approval runs
  the same checks as a single approval.
- **Users:** suspend or reactivate. Your own account is always skipped,
  and staff accounts are skipped unless you are a super admin.
- **Orders:** export the ticked or filtered orders to CSV.

Skipped rows are listed with the reason, and every change is audited.

## Virus scanning

Seller uploads are scanned by ClamAV (`clamav` service, clamd on port 3310)
through a queued job. The `clamav` container needs outbound access to
`database.clamav.net` to update its signatures (`freshclam`). With
`SHOP_VIRUS_SCAN=required` (production) a file is never delivered, and its
product cannot be approved, until the scan reports it clean. A scanner outage
is retried with backoff. `SHOP_VIRUS_SCAN=disabled` marks files `skipped` and
is meant for development only.

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
- `files.tar.gz`, with product files, product images and invoices;
- `SHA256SUMS`.

Optional settings:
- **`BACKUP_AGE_RECIPIENT`:** encrypt both files with [age](https://age-encryption.org)
  and delete the plaintext.
- **`BACKUP_RCLONE_REMOTE`:** upload the set off-site with rclone. Put
  `rclone.conf` in `docker/rclone/`, which is git-ignored.
- **`BACKUP_KEEP_DAYS`:** prune old local backups (default 14).

`docker compose --profile backup up -d` runs it daily (`BACKUP_CRON`,
default 02:15 UTC) in a dedicated container. Keep the age private key
offline: it is the only way to read the backups.

Restore procedure (test it quarterly on a staging host):

1. `php artisan down` and stop the queue workers and the scheduler.
2. `scripts/restore.sh backups/<timestamp>`. It decrypts `*.age` files with
   the key file in `BACKUP_AGE_IDENTITY`, verifies the checksums, and asks for
   confirmation before replacing data.
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
| Saved carts of signed-in buyers | 30 days after the last change | `SHOP_SAVED_CART_DAYS` |
| Password reset tokens | 60 minutes | `config/auth.php` |
| Sessions | 120 minutes idle | `SESSION_LIFETIME` |
| Orders, payments, invoices, audit log | kept (accounting) | define in your retention policy |

Adjust these to match the published data retention policy page.
