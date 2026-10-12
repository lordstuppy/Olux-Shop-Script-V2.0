# Operations Guide

## Processes

| Process | Command | Notes |
|---|---|---|
| Web | PHP-FPM behind nginx (`docker/nginx/default.conf.template`) | Only `public/index.php` is executable |
| Queue worker | `php artisan queue:work --tries=1` | Delivery, invoices, emails, webhook retries. Jobs and emails define their own retries and backoff (emails: 5 tries over about 20 minutes) |
| Scheduler | `php artisan schedule:work` (or cron `* * * * * php artisan schedule:run`) | See the table below |

Scheduled tasks (`routes/console.php`):

| Task | Frequency | Purpose |
|---|---|---|
| `shop:expire-orders` | every minute | Expires unpaid orders past `SHOP_ORDER_TTL_MINUTES`; releases stock and coupon reservations |
| `shop:reconcile-payments` | every 5 minutes, in the background | Polls Shkeeper invoice status for pending payments (covers lost webhooks), newest first; stops after 3 failed polls in a row (Shkeeper down) and tries again next run |
| `shop:retry-webhooks` | every minute | Re-queues failed webhook events whose backoff elapsed |
| `shop:reconcile-payouts` | daily 03:15 | Seller ledger vs completed orders and payouts; exits 1 and logs errors on mismatch |
| `shop:reconcile-shkeeper-transfers` | every 5 minutes, in the background | Polls Shkeeper for payouts and crypto refunds whose callback has not arrived; same stop rule |
| `shop:refresh-sales-ranking` | every 10 minutes | Recomputes units sold per product for the "best selling" sort and related products (rankings lag sales by up to 10 minutes) |
| `shop:prune-cache` | hourly | Deletes expired entries from the database cache table (per-address rate-limit counters would otherwise stay forever) |
| `shop:subscription-reminders` | hourly | Emails buyers whose subscription access ends within `SHOP_RENEWAL_REMINDER_DAYS` |
| `shop:escalate-disputes` | every 15 minutes | Hands disputes to staff when the seller missed the response deadline |
| `shop:prune-gateway-logs` | daily 04:10 | Deletes gateway log entries older than 90 days |
| `shop:recover-stuck-orders` | every minute | Re-queues delivery and invoice jobs of orders paid over 3 minutes ago that still have undelivered instant items or no invoice (jobs lost in a crash between payment and queueing) |
| `shop:prune-saved-carts` | daily 04:20 | Deletes saved carts not changed for 30 days (`SHOP_SAVED_CART_DAYS`) |
| `shop:staff-digest` | every 15 minutes | Emails the staff address a "needs attention" summary when new work arrived: seller applications, products submitted for review, payout requests, tickets and customer replies, locked accounts |
| `shop:prune-notifications` | daily 04:30 | Deletes read notification-centre entries after 90 days and all entries after a year |
| `shop:health-alerts` | every 5 minutes | Emails the staff address about each critical check on `/admin/health` (at most hourly per check; sent directly, not through the queue) |
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

- **Sign-in protection:**
  - Per minute: 5 attempts per email and address, 20 per address (429 page).
  - Per account, from any address: after 20 failed attempts within 15 minutes, sign-in for that email is paused for 15 minutes. The owner is emailed once ("Sign-in to your account was paused") and the lock is audited as `user.login_locked`. Unknown emails get the same answer.
  - A password reset lifts the lock at once. Tune it with `LOGIN_LOCK_ATTEMPTS`, `LOGIN_LOCK_WINDOW_MINUTES` and `LOGIN_LOCK_MINUTES`.

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

## Staff alerts

Staff alerts go to `SHOP_STAFF_ALERT_EMAIL` (empty means
`SHOP_SUPPORT_EMAIL`). Use a shared mailbox or a list the on-call people
read. Alerts that can repeat are throttled so an attack or an outage sends
one mail per period:

| Alert | When | Throttle |
|---|---|---|
| Dispute opened or escalated, or a new message while staff handle it | as it happens | none |
| Shkeeper callbacks rejected (wrong signature or sender address) | first rejection | 1 hour |
| Background job failed after all retries (mail, delivery, invoice) | when it gives up | 30 minutes per job type |
| Shkeeper payout failed / crypto refund failed | Shkeeper reports it | none |
| Infected upload removed | virus scan | none |
| Staff account sign-in locked | lock | 1 hour per account |
| Seller ledger mismatch | nightly reconciliation | 20 hours |
| Critical health check (including "Shkeeper is not answering") | `shop:health-alerts` | 1 hour per check |
| Needs-attention digest | `shop:staff-digest` | only when something new arrived |

The health alerts are sent synchronously, so they still arrive when the
queue worker is down. If the mail server itself is down they cannot be
sent; the failure is logged at critical level, so your log alerts catch it.

## Email to customers and sellers

Buyers always get emails about their orders, payments, refunds, disputes,
support replies and account security. Sellers can turn off the per-sale
and per-review emails under Account > Email notifications; sales and
reviews stay visible in the seller dashboard.

Emails are queued and retried 5 times over about 20 minutes. A mail that
still fails stays in the failed jobs list on `/admin/health`, where
managers and super admins can retry it (one or all) or delete it once the
mail server works again. The dashboard shows the count. Failed jobs are
pruned after 30 days.

## Notification centre and live updates

- Every user-facing email is sent through `App\Services\Notifier`, which also
  writes an entry in the user's notification centre (`user_notifications`,
  shown at `/notifications` with a badge in the header). Entries do not
  depend on the mail server.
- Staff alerts are also entries for super admins and managers.
- Signed-in browsers with JavaScript poll `GET /live` every 10 seconds while
  the tab is visible and was used in the last 20 minutes; idle tabs stop,
  so sessions still expire. Each poll is a handful of indexed queries.
  Expect about 6 requests a minute per active user; `/live` is limited to
  30 a minute per user.
- If `/live` is blocked (for example by a proxy rule), the shop keeps working
  as before; pages simply do not update themselves.

## System health

`/admin/health` (super admin, manager, finance) shows the following, each
with an OK, Check or Problem label:

- **Database:** connection, latency, size, pending migrations.
- **Background work:** queue worker and scheduler heartbeats, waiting jobs
  and the age of the oldest, failed jobs. Below the checks: the latest
  failed jobs with Retry and Delete buttons (super admin, manager).
- **Storage:** free disk space and the size of uploaded product files,
  writability.
- **Payments and scanning:** the last successful Shkeeper webhook and API
  call, Shkeeper API errors in the last 15 minutes (critical when the last
  three calls failed), ClamAV reachability.
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

## Web server, caching and compression

- CSS and JavaScript are linked with a content hash (`?v=...`) and served
  with `Cache-Control: public, max-age=31536000, immutable`; a deploy that
  changes a file changes its URL. Without the hash: one hour.
- nginx gzips CSS, JavaScript and SVG, also behind a TLS terminator that
  forwards HTTP/1.0. HTML is not compressed on purpose: it contains the
  CSRF token next to reflected input (the search box), which is what the
  BREACH attack needs. Pages are small and change per user anyway.
- HTML responses are `Cache-Control: private, must-revalidate`; prices and
  stock are always read from the database at checkout, never from a cache.
- Product images are WebP in two sizes (480 px and 1600 px), served with
  `srcset` and fixed dimensions; originals are not kept (this also drops
  EXIF metadata such as GPS positions).
- `/.well-known/security.txt` names `SHOP_SECURITY_EMAIL` as the
  vulnerability contact (RFC 9116).
- Images of listed products and `/sitemap.xml` are served without a session
  (no cookies), so a CDN or shared cache may keep them. The sitemap is
  rendered at most once an hour and sent gzip-compressed (it holds no secrets).
- Downloads go through PHP (authorisation, download limits) and nginx buffers
  them in `/tmp` (a tmpfs, so memory), which frees the PHP worker at once even
  for a slow client. nginx allows 6 concurrent downloads per client address
  (429 beyond that, before a download is counted).

## Database performance settings

- Set `random_page_cost = 1.1` on the PostgreSQL server (docker-compose.yml
  does; on a managed database set it in its parameter group). With the default
  of 4 the planner skips the trigram indexes of the catalogue search: 60-130 ms
  sequential scans instead of 2-4 ms at 20,000 products
  (docs/performance/database-performance-audit.md).
- PDO sends each query with its parameters in one round trip
  (`ATTR_DISABLE_PREPARES` in `config/database.php`). This also works behind
  PgBouncer in transaction mode.
- Indexes added by migrations on a live shop are built `CONCURRENTLY`, so
  writes continue during the deploy.
- The performance dataset, page benchmark and query plans in
  `tests/performance/` reproduce the measurements; see its README.

## Reverse proxy, client addresses and TLS

The shop builds every link from `APP_URL` (never from the request's Host
header), and it learns the client's address and scheme only from proxies it
trusts. Set these together, or downloads and email-verification links fail
with 403 (their signature includes the scheme and host the shop sees):

| Setting | Where | Value |
|---|---|---|
| `APP_URL` | app `.env` | The public `https://` address customers use (one host; redirect `www` to it or the other way round) |
| `NGINX_TRUSTED_PROXY` | environment of the `web` service | Address or CIDR of the TLS terminator / load balancer in front of nginx. Default `127.0.0.1/32` = nobody: use it only when nginx itself terminates TLS for clients |
| `TRUSTED_PROXIES` | app `.env` | Not needed with the bundled nginx: it already hands PHP the client address (`REMOTE_ADDR`) and scheme (`HTTPS`) and replaces every client-sent `X-Forwarded-*` header. Only set it if PHP-FPM is reached through a different proxy |

Never set `NGINX_TRUSTED_PROXY` to a whole private range when clients can
reach nginx through Docker's userland proxy: they would all appear to come
from the bridge gateway and could choose their own IP address. After a
change, sign in from outside and check that **Account > Sessions** shows your
real address.

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
| Notification-centre entries | read: 90 days; all: 1 year | `shop:prune-notifications` |
| Password reset tokens | 60 minutes | `config/auth.php` |
| Sessions | 120 minutes idle | `SESSION_LIFETIME` |
| Orders, payments, invoices, audit log | kept (accounting) | define in your retention policy |

Adjust these to match the published data retention policy page.
