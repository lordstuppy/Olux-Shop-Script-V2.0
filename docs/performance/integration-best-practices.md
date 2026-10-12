# Integrations and Third-Party Packages

This report covers what was checked against the vendors' own documentation and what was changed.

The documentation pages below were downloaded during this audit (2026-10-12) through the sandbox's proxy and read for the points listed. Pages that could not be fetched are marked.

## Official documentation consulted

| Source (version) | Points checked | Used for |
|---|---|---|
| PHP manual, `Pdo\Pgsql` and PDO_PGSQL constants | `ATTR_DISABLE_PREPARES`: "Send the query and the parameters to the server together in a single call ... If the query is only going to be executed once this can reduce latency by avoiding an unnecessary server round-trip"; the `PDO::PGSQL_ATTR_DISABLE_PREPARES` alias is deprecated as of PHP 8.5 | PERF-03 (`config/database.php` uses `Pdo\Pgsql::ATTR_DISABLE_PREPARES` when defined, the alias on 8.3) |
| PostgreSQL 15: runtime-config-query | `random_page_cost` default 4.0; lowering it "might be appropriate" when data is largely cached | PERF-09 |
| PostgreSQL 15: pg_trgm | GIN and GiST trigram indexes support `LIKE` and `ILIKE`; search works by extracting trigrams (none for terms under 3 characters) | PERF-09 and its remaining limit |
| PostgreSQL 15: CREATE INDEX | `CONCURRENTLY` builds without locking out writes | Index migrations |
| Laravel 13: Queues | "The `--timeout` value should always be at least several seconds shorter than your `retry_after` configuration value" | PERF-27 (worker 60 s, `retry_after` 90 s: compliant) |
| Laravel 13: Task Scheduling | Tasks due at the same time "will execute sequentially"; `runInBackground()` for commands | PERF-14 |
| Laravel 13: HTTP Client | `timeout`, `connectTimeout` (default 10 s), `retry` | Shkeeper client review |
| Laravel 13: Queries; Eloquent relationships | "If you are updating database records while chunking results, your chunk results could change in unexpected ways ... it is always best to use the `chunkById` method"; eager loading against N + 1 | PERF-10, PERF-11, PERF-04 |
| nginx: `ngx_http_fastcgi_module` | `fastcgi_buffering` on by default; responses that do not fit memory buffers go to a temporary file up to `fastcgi_max_temp_file_size` (default 1024m) | PERF-19 |
| nginx: `ngx_http_gzip_module` | Directives used for static assets | Compression review |
| web.dev: Web Vitals; LCP and lazy loading | Thresholds LCP 2.5 s, INP 200 ms, CLS 0.1 at the 75th percentile; lazy-loading images in the viewport delays LCP | Budgets, PERF-20 |
| nginx: `ngx_http_limit_conn_module` | `limit_conn_zone` and `limit_conn` per key, used in a download location (the manual's own example); a connection is counted once its request header has been read | PERF-19. That the limit still holds after the internal redirect to `index.php` was verified by experiment: 6 x 200 and 19 x 429 |
| Shkeeper README (`vsys-host/shkeeper.io`, main branch) | Invoices are unique per `external_id`, and repeating the creation updates the same invoice; callbacks must be verified with the API key as HMAC secret; payout endpoints use Basic auth; the `/crypto` list is cached by Shkeeper for up to 60 s | Integration review below |

## Shkeeper (payment gateway)

| Point | Implementation | Verdict |
|---|---|---|
| Timeouts | 10 s request (`SHKEEPER_TIMEOUT`) and 5 s connect on every call (`ShkeeperClient`) | OK |
| Redirects | Not followed (`withoutRedirecting`): the API key is never sent to another host | OK (security audit SEC-27) |
| Retries | No automatic retry on creating invoices or payouts. Invoice creation is idempotent per `external_id` (Shkeeper README), so the checkout can safely create again; payouts are not, so a timeout is "outcome unknown" and is resolved by status polling | OK, matches the vendor semantics |
| Webhooks | HMAC-SHA256 with the API key over the raw body, plus timestamp tolerance; stored by event key for deduplication; processed in a job; failed events retried with backoff | OK (WebhookTest, simulation phase 2: duplicate delivered five times, out-of-order) |
| Polling vs events | Callbacks are primary. Polling covers lost callbacks (every 5 min), in the background now, newest first, and stops after 3 failures in a row | Changed (PERF-14) |
| Rate limits and quotas | The README documents none | Polling bounded at 200 invoices plus 200 transfers per 5 min (PERF-28) |
| Crypto list | Cached by the application for 10 min; Shkeeper itself caches for 60 s | OK |
| Failure of Shkeeper | Checkout shows an error; pending payments are polled later; the health page shows the last successful call; simulation phase 8 covers the mock being stopped | OK |

## ClamAV (virus scanning)

| Point | Implementation |
|---|---|
| Transport | clamd `INSTREAM` over TCP in chunks |
| Timeouts | `CLAMAV_TIMEOUT` (60 s) for connect and reads |
| Where it runs | In a queued job (`ScanProductFile`, 5 tries with backoff), never on the request path |

**Open (PERF-27):** the worker's 60 s timeout equals the scan timeout. Scans approaching 60 s would be killed and retried; set an explicit job `$timeout` if real scans get that slow.

## Mail

- Every Mailable implements `ShouldQueue`, with 5 tries over about 20 minutes. The single exception is the health alert, which is sent directly so it still arrives when the queue is down.
- In-app notification entries are written in the caller's transaction.
- **Contract fix (PERF-17):** `InAppNotice` now requires `envelope()`, which the notifier calls.

## Third-party packages

**Runtime packages:** 84. All direct Composer dependencies are on their latest releases (`composer outdated --direct`: "All your direct dependencies are up to date"). Advisories are covered by the security gate (composer audit, npm audit, OSV).

| Package | Use | Assessment |
|---|---|---|
| laravel/framework 13.35 | Framework | Supported; documented patterns followed (lazy-loading guard, queued mail, `chunkById` now); config, route and view caches built at container start |
| barryvdh/laravel-dompdf (dompdf) | Invoice PDFs | Large (45 MB on disk with fonts) but used only in a queued job, never on the request path; no lighter option with equal output was justified |
| chillerlan/php-qrcode | QR code of the payment address on the pay page | Rendered per page view (cheap). The pay page reloads every 30 s; caching per address is possible but not needed |
| pragmarx/google2fa | TOTP | Small, maintained |
| brick/math | Exact money arithmetic | Needed; no float money |
| larastan/larastan 3.13 + phpstan 2.3 (dev, new) | Static analysis gate | Added for P13; dev only, not in the image |
| @playwright/test 1.56.1, @axe-core/playwright 4.10.2 (dev, browser tests) | Browser tests | 1.64.0 and 4.13.0 exist; pinned on purpose (Dependabot with 7-day cooldown); dev only |

No package duplicates framework functionality. The frontend has no npm runtime dependencies: one hand-written `enhance.js` of 20 KB, loaded with `defer`.

**Initialisation:**

- `ShkeeperClient` and the virus scanner are bound in the container and built when resolved (cheap: configuration values and Laravel's shared HTTP factory). No client is set up in a loop.
- The exchange-rate converter caches rates per instance but is not shared between the services of one request, so a catalogue page may read the same rate twice. That is two primary-key lookups; left as is.
