# Digital Goods Shop

A server-rendered marketplace for lawful digital goods (software tools,
tutorials, licence keys, subscriptions) with buyer, seller and admin roles,
per-user balances, and self-hosted crypto payments through
[Shkeeper](https://github.com/vsys-host/shkeeper.io).

It replaces the legacy "Olux/Felux Shop Script V2.0", which was removed from
this repository. [docs/GAP_ANALYSIS.md](docs/GAP_ANALYSIS.md) records what the
legacy code did, which parts were illegal and excluded, and why everything was
rebuilt.

Every page, and the full browse, cart, checkout, login and order flow, works
with JavaScript disabled. One optional deferred script adds live search
suggestions and double-submit protection.

## Stack

| Concern | Choice |
|---|---|
| Language / framework | PHP 8.3, Laravel 13 (Laravel 11 is out of security support) |
| Templates | Blade, server-rendered, auto-escaped `{{ }}` |
| Database | PostgreSQL 15+ with migrations; money in integer minor units |
| Payments | Self-hosted Shkeeper: invoices plus HMAC-signed callbacks |
| Queue / cache / sessions | Database drivers (no Redis required) |
| Logging | Monolog JSON lines in `storage/logs/shop-YYYY-MM-DD.log` |
| PDF invoices | dompdf, stored in `storage/invoices/` |
| Tests | PHPUnit (unit + feature), Playwright (no-JS + axe a11y), k6 (load) |

## Architecture

```
app/
  Services/          Domain logic. Controllers stay thin.
    CatalogService      list, search, filter, paginate products
    CartService         session cart; totals always recomputed server-side
    OrderService        create from cart under row locks, state machine, expiry
    PaymentService      Shkeeper invoices, balance payments, notification handling
    Shkeeper/ShkeeperClient  createInvoice, getInvoiceStatus, verifyWebhookSignature
    DeliveryService     files (signed links) and licence keys into order_items
    RefundService       full/partial refunds as separate payment rows
    PayoutService       seller ledger, payout requests, reconciliation
    UserService         registration, login, password reset, seller onboarding
    CouponService, GiftCardService, BalanceService, TicketService,
    InvoiceService, CurrencyConverter, WebhookProcessor, AuditLogger
  Http/Middleware/   request id, security headers + CSP, roles, CSRF
  Jobs/              DeliverOrder, GenerateInvoicePdf, ProcessWebhookEvent
database/migrations  schema with CHECK constraints for statuses and money
resources/views      Blade templates (layout, pages, admin, seller, errors)
docker/              nginx, PHP config, Shkeeper mock
tests/               Unit, Feature, browser (Playwright), load (k6)
```

### Order and payment lifecycle

1. **Checkout** (`POST /checkout`, rate limited, CSRF protected) locks the
   product rows in id order, checks stock, converts prices into the cart
   currency with a stored rate, reserves a coupon redemption, and creates a
   `pending` order. Each rendered checkout form carries an idempotency key
   (unique per buyer), so a double submit returns the first order.
2. **Crypto payment:** the shop calls `POST /api/v1/{crypto}/payment_request`
   on Shkeeper with the server-side total and stores the invoice id in
   `payments.provider_reference`. Shkeeper has **no hosted checkout page**: it
   returns a wallet address and a crypto amount, which the shop renders with
   a server-generated QR code. The page refreshes with
   `<meta http-equiv="refresh">`, so no script is needed.
3. **Webhook** `POST /webhooks/shkeeper`:
   - **Verification:** `X-Shkeeper-Signature` is checked as
     HMAC-SHA256(`timestamp.body`) with `hash_equals`, and the timestamp must
     be within 5 minutes. Unsigned or mismatched requests get **401**.
   - **Storage:** the event is stored before processing. An identical body is
     a no-op.
   - **Paid transition:** the order and payment rows are locked, then
     currency and amount are compared with the order. The order moves to
     `paid` inside one transaction; a mismatch leaves it unpaid with a
     specific message.
   - **Idempotency:** once the payment is `confirmed`, further callbacks for
     the same invoice change nothing.
   - **Retries:** transient failures are retried by the queue with
     exponential backoff (30 s, 2 min, 10 min, 30 min, 2 h).
   - **Response:** 202, which is what Shkeeper expects.
4. **Delivery** (queued, retried) writes `order_items.delivered_payload`,
   encrypted at rest: file references and one licence key per unit. Downloads
   go through `DownloadController` behind a signed, expiring URL plus an
   ownership check. Manual products are delivered by the seller.
5. **Never trusted:** the client redirect or result page. They only display
   server state. `shop:reconcile-payments` polls Shkeeper's invoice status
   (server to server) to catch lost webhooks.

Balance payments, refunds (to balance, manual, or crypto via Shkeeper), gift
cards, coupons, the seller ledger and payouts (manual or through Shkeeper's
payout API) are described in [docs/OPERATIONS.md](docs/OPERATIONS.md).

### Features at a glance

- **Buyers:**
  - Catalog: keyword search, filters for category, seller, price range (in the shopper's currency) and listing currency, best sellers.
  - Product pages: images, reviews from verified buyers, wishlist.
  - Checkout: session cart with products from several sellers in one order, balance or crypto, coupons, quote refresh.
  - After purchase: downloads with limits, subscriptions with expiry and reminders, invoices.
  - Help: disputes on a purchased item, tickets.
  - Account: gift cards, 2FA, email change, session management.
- **Sellers:**
  - Onboarding.
  - Products with files, licence keys and images, virus-scanned and reviewed by staff.
  - Manual delivery, sales, and responding to disputes.
  - Ledger-based payouts (disputed earnings are held), payout address change with confirmation.
  - The effective commission is shown per product.
- **Platform revenue:** a commission on every sale. The rate is set per product, per seller, per category or globally (the most specific wins) and frozen on the order line.
- **Staff:** five tiers behind 2FA: super admin, manager, finance, moderator, support.
  - Analytics dashboard: revenue trend, order volume, top products and sellers, active sellers, gateway health.
  - Orders: refund, cancel, deliver, retry, invoice, reset downloads.
  - Disputes with refund, replacement or rejection.
  - Bulk actions: approve or disable products, suspend users, export orders.
  - Payment gateway page: methods, coins, order limits and the gateway log.
  - Money: payments, webhooks, payouts, reconciliation, reports with charts, CSV exports, balance adjustments, coupons, gift cards, exchange rates, commission.
  - Content: categories, product review with file inspection, seller approval, tickets with assignment and internal notes, reviews moderation, announcements.
  - Administration: editable email templates, settings, system health, audit log.

The conversion rules are in [docs/CURRENCY_POLICY.md](docs/CURRENCY_POLICY.md).

## Quick start (local)

Requirements: PHP 8.3 with `pdo_pgsql`, `intl`, `zip`, `gd`, `sodium`;
Composer; PostgreSQL 15+.

```sh
composer install
cp .env.example .env            # set APP_ENV=local, DB_*, APP_URL; for http://localhost set SESSION_SECURE_COOKIE=false
php artisan key:generate
php artisan migrate --seed      # demo data, refuses to run in production
php -S 127.0.0.1:8081 docker/shkeeper-mock/server.php &   # Shkeeper API emulator
# .env: SHKEEPER_BASE_URL=http://127.0.0.1:8081 SHKEEPER_API_KEY=dev-api-key SHKEEPER_WEBHOOK_SECRET=dev-api-key
php artisan serve --no-reload &
php artisan queue:work &
```

The demo accounts are `admin@example.test`, `seller@example.test` and
`buyer@example.test`. Their password is `correct-horse-battery-1` (local only).
The demo admin has two-factor authentication with the development-only secret
`JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`; add it to an authenticator app. In
production, create the first admin with `php artisan shop:create-admin`.

Simulate a payment from Shkeeper (sends a signed callback):

```sh
curl -X POST http://127.0.0.1:8081/__mock/pay/<order-uuid> -d '{"amount":"25.00"}'
```

## Docker Compose

```sh
cp .env.example .env    # set APP_KEY (php artisan key:generate --show), DB_PASSWORD, SHKEEPER_*
docker compose --profile mock up -d --build
docker compose exec app php artisan migrate --force
```

The Compose services are:
- `app`: PHP-FPM.
- `web`: nginx on port 8080.
- `db`: PostgreSQL 15.
- `queue` and `scheduler`.
- `clamav`: virus scanning of seller uploads.
- `shkeeper-mock`: started only with the `mock` or `test` profile.
- `backup`: scheduled, encrypted backups, started only with the `backup` profile.

All application containers run with read-only root filesystems, no Linux
capabilities and `no-new-privileges`. The `app` container caches config and
routes at start-up and runs migrations (`RUN_MIGRATIONS=true`).

When using the mock in Compose, set `SHKEEPER_BASE_URL=http://shkeeper-mock:8081`
and `SHKEEPER_CALLBACK_URL=http://web:8080/webhooks/shkeeper`.

For production, deploy a real Shkeeper instance (see
[docs/OPERATIONS.md](docs/OPERATIONS.md#shkeeper)) and terminate TLS at nginx or
at a load balancer.

## Tests

```sh
php artisan test               # PHPUnit: unit + feature (needs a PostgreSQL database "shop_test")
./tests/browser/run.sh         # Playwright: every page with JS disabled, purchase flows, axe a11y
./tests/load/run.sh            # k6: concurrent checkouts and duplicate webhooks + integrity checks
./tests/live/run.sh            # end-to-end run on a production-like stack (see docs/LIVE_TEST.md)
./scripts/check-ascii.sh       # source files must be ASCII-only
vendor/bin/pint --test         # code style
```

`tests/live/run.sh` builds the committed code into a separate directory
(`LIVE_DIR`, default `/tmp/shop-live`) and needs Docker; it uses ports 2525,
3310, 5433, 8000, 8080, 8081 and 8443. `tests/live/run.sh down` stops it.

CSRF verification stays enabled in tests. `tests/browser/run.sh` and
`tests/load/run.sh` **drop and re-seed** the database named in `.env`, so run
them against a disposable database only.

## Translations

Every user-facing string goes through Laravel's translator with the English
text as the key (`__('Your cart')`). The shop ships in English only.

- `lang/en.json` lists every key. It is generated from the source by
  `php artisan shop:lang-extract`; a unit test fails when it is out of date.
- `lang/en/*.php` hold the framework messages (validation, passwords,
  pagination).
- To add a language, copy `lang/en.json` to `lang/<locale>.json` and
  `lang/en/` to `lang/<locale>/`, translate the values (keep `:placeholders`
  unchanged), and set `APP_LOCALE=<locale>`. Missing keys fall back to
  English.
- Money and dates keep their current fixed formats (`12.34 USD`, ISO dates)
  in every locale.

## Security

See [SECURITY.md](SECURITY.md) for the disclosure process and a summary of the
controls: Argon2id, CSRF, rate limits, CSP, signed downloads, audit log and
webhook verification.

## Legal pages

`/terms`, `/privacy` and `/data-retention` are **placeholders**. Replace them
with text reviewed for your jurisdiction before going live.
