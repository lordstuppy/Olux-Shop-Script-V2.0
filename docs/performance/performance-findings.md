# Performance and Code Quality Findings

**Scope:** the whole application at `e224388` (baseline) and candidate `de3000b`, plus the deployment configuration. Measurements come from the simulation stack with the first-year dataset from `tests/performance/scale-data.sql` (conditions: [performance-baselines.md](performance-baselines.md)).

**How the findings were made:**

- A reading review of all code, with candidates listed by file and line.
- Each candidate checked against measurements: query counts and plans, page latency, browser metrics, load.
- Fixes covered by regression tests.

**Severity** reflects impact at the measured volume and how it grows:

| Severity | Meaning |
|---|---|
| High | Breaks or nearly breaks a path, or is a cheap amplifier on a public URL |
| Medium | Clear user-visible or capacity cost, or a correctness defect |
| Low | Small cost, or limited to staff or rare paths |
| Info | A decision or observation without a defect |

## Summary

| Severity | Found | Fixed and re-measured | Open (accepted or within budget) |
|---|---|---|---|
| Critical | 0 | 0 | 0 |
| High | 2 | 2 | 0 |
| Medium | 12 | 12 | 0 |
| Low | 10 | 7 | 3 |
| Info | 4 | 0 | 4 (no defect; decisions documented) |
| **Total** | **28** | **21** | **7** |

**By category** (primary category of each finding):

| Category | Findings |
|---|---|
| Database | 9 (PERF-04, 08, 09, 10, 15, 16, 18, 24, 26) |
| Network | 4 (PERF-03, 05, 07, 22) |
| Runtime / algorithm | 3 (PERF-01, 02, 06) |
| Correctness | 2 (PERF-11, 12) |
| Reliability | 2 (PERF-14, 27) |
| Code quality | 2 (PERF-17, 21) |
| Capacity | 2 (PERF-23, 25) |
| Build | 1 (PERF-13) |
| Resource limits | 1 (PERF-19) |
| Assets | 1 (PERF-20) |
| Integration | 1 (PERF-28) |

## Findings

"Before/after" values are p50 server latency of 30 sequential requests unless stated otherwise. A second identical run moved values by up to 18-25%. Only differences well above that are claimed as improvements.

### PERF-01: Reconciliation walked every paid order line in PHP (High, runtime; fixed)

**Where:** `PayoutService::reconcile()` check 1, used by `/admin/reconciliation` on every view and by the nightly `shop:reconcile-payouts`.

**Root cause:** `chunkById(500)` over all paid lines with `whereHas` and an eager load.

- 821 queries and 18.6 s per page view at 250,000 lines (`bench-baseline.json`).
- The cost is linear in sales. At about 3x the volume it reaches nginx's 60 s FastCGI timeout.

**Fix:** The check is computed in SQL and returns only mismatching lines. `PayoutService::earningSql()` is the PostgreSQL form of `earningFor()`: the commission is rounded half up as `(n * bps + 5000) / 10000` on non-negative integers.

**After:** 11 queries; 399 / 382 ms (run 1 / run 2); 1 query of 53-85 ms (`explain-*.txt` Q24).

**Tests:**

- `test_perf03_earning_sql_matches_earning_for`: 300 generated amounts, including all half-way cases; SQL and PHP must agree exactly.
- `test_perf03_reconciliation_reports_only_the_tampered_line`.

### PERF-02: "Best selling" sort and related products summed all order lines per view (High, database/runtime; fixed)

**Where:** `CatalogService::paginate(sort=best)` and `related()` (every product page). Each used a correlated `SUM(order_items.quantity)` over every listed product.

**Before:** The query took 986 ms (Q03-old). `/products?sort=best` took 859 ms; product pages took 210 ms, of which 131 ms was this query.

The URL is public and unauthenticated, so each request bought a second of database time.

**Fix:** a `products.units_sold` column with index `(status, units_sold, id)`.

- It is refreshed every 10 minutes by `shop:refresh-sales-ranking`. Only changed rows are written; the read part takes 131-180 ms (Q28).
- A per-payment counter was rejected: it would write the hot product row in every payment transaction.

**Trade-off (documented):** rankings lag sales by up to 10 minutes.

**After:**

| | Before | After |
|---|---|---|
| Query, best selling | 986 ms | 0.3 ms (Q03) |
| `/products?sort=best` | 859 ms | 91 / 72 ms |
| Related products query | 101 ms | 0.22 ms (Q04) |
| Product page | 210 ms | 63 / 61 ms |

**Test:** `test_perf02_sales_ranking_counts_paid_units_only` (paid units only, idempotent refresh, and no aggregation of order lines in the catalogue query).

### PERF-03: Three database round trips per query (Medium, network/database; fixed)

**Root cause:** PDO's default named prepared statements send PREPARE, EXECUTE and DEALLOCATE separately for every query, and statements are never reused within a PHP request.

Measured on the catalogue page:

- **Before:** 36 round trips for 11 queries.
- **After:** 11, with the `Pdo\Pgsql::ATTR_DISABLE_PREPARES` option (`PDO::PGSQL_ATTR_DISABLE_PREPARES` on PHP 8.3).

The query still travels with its parameters separately (PQexecParams), so the SQL injection protection is unchanged. Confirmed in the PostgreSQL log: no `DEALLOCATE`, only unnamed statements.

**Effect:** The local Docker network round trip is about 0.05 ms, so the saving is within noise here. With a database on another host (0.3-1 ms per round trip) it saves 7-25 ms per page; that figure is calculated, not measured.

The PHP manual for `Pdo\Pgsql::ATTR_DISABLE_PREPARES`: "If the query is only going to be executed once this can reduce latency by avoiding an unnecessary server round-trip."

### PERF-04: N+1: seller status checked per product in wishlist, cart and reorder (Medium, database; fixed)

**Where:** `Product::isPurchasable()` queried the seller for every product.

- Wishlist: up to 200 extra queries.
- Cart and checkout pages: one per line.
- Reorder: one per item.

**Test evidence before the fix:** wishlist 8 -> 14 queries for 2 -> 8 items; cart 3 -> 8 queries for 1 -> 6 lines.

**Fix:** The check runs in memory when the seller relation is loaded, and the lists eager-load it. Checkout still asks the database (under the row lock), on purpose. The catalogue no longer loads the unused seller rows.

**Tests:** `test_perf04_*`, which check that query counts stay constant as data grows on:

- wishlist and cart;
- catalogue, home and product pages;
- order list, order page, notifications and the live poll;
- seller dashboard, sales and products;
- six admin lists.

### PERF-05: The seller filter listed every seller on every catalogue page (Medium, network; fixed)

**Before:** 426 `<option>` elements made up 28 KB of the 42 KB catalogue page at 500 sellers, and the size grows with every seller. HTML is not compressed (PERF-22), so all of it is transferred.

**Fix:** The filter lists the 50 best-selling sellers plus the selected one, cached for 10 minutes. "More from this seller" on every product page still reaches any seller.

**After:**

- Catalogue HTML: 42.0 KB -> 19.1 KB.
- Lab TBT: 127 -> 64 ms.
- DOM nodes: 647 -> 272.

**Test:** `test_perf05_seller_filter_lists_the_top_sellers_and_the_selected_one`.

### PERF-06: Sitemap rendered 17,000 URLs on every request (Medium, runtime; fixed)

**Before:** 891 ms and 2.07 MB per request on a public, unthrottled URL.

**Fix:**

- Rendered at most once an hour and stored gzip-compressed (base64, because the cache column is text).
- Served compressed when the client accepts it: the XML holds no secrets.
- No session, and `Cache-Control: public, max-age=3600`.

**After:** 37 / 30 ms and 154 KB.

**Test:** `test_perf06_sitemap_is_cached_compressed_and_sessionless`.

### PERF-07: Product images went through the full session stack (Medium, network; fixed)

**Before:** every thumbnail paid for a framework boot plus session read, session write and a `Set-Cookie` header. A catalogue page showed 12 of them. The cookie also stops shared caches from keeping the image, although the response said `public, immutable`.

**Fix:**

- Listed products' images use a route without session middleware.
- Unlisted ones go through a new `/preview` route that needs the signed-in owner or staff (seller form, admin product page).

**Test:** `test_images_are_reencoded_and_served` (preview rules; no cookies on the public image).

### PERF-08: Indexes missing for measured hot and periodic queries (Medium, database; fixed)

Before and after times come from `explain-baseline.txt` and `explain-after.txt`:

| Index | Query | Before | After |
|---|---|---|---|
| `order_items (seller_id, id)` | Seller's latest sales (Q12), seller live poll (Q10, every 10 s per tab) | 56 ms; 51 ms | 0.2 ms; 0.06 ms |
| `orders (paid_at)` | Dashboard periods (Q14, Q15) | 48 ms; 89 ms | 20 ms; 47 ms |
| `orders (paid_at)` | `shop:recover-stuck-orders`, every minute (Q18) | 45 ms | 17 ms |
| `orders (paid_at)` | Reports (Q20) | 44 ms | 17 ms |
| `orders (created_at)` | Conversion KPIs (Q16), exports | 35 ms | 3 ms |
| `order_items (delivered_at)` | Admin order list live version (Q17) | 27 ms | 0.13 ms |
| `products (status, created_at, id)` | Catalogue and home, newest first (Q01) | 25-34 ms | 0.2 ms |

All are built `CONCURRENTLY`. Migration times on the full dataset: 2.0 s, 0.7-0.8 s and 0.05 s.

Rejected after measurement: `orders (buyer_id, updated_at)`. With realistic data the planner already uses `(buyer_id, id)` (Q09b 0.24 ms). The earlier evidence came from a faulty dataset; see the remediation log.

### PERF-09: The catalogue search never used its trigram indexes (Medium, database; fixed in deployment config)

**Root cause:** with PostgreSQL's default `random_page_cost = 4` the planner priced the BitmapOr of the two GIN trigram indexes above a sequential scan, and evaluated `ILIKE` on all 20,000 descriptions.

**Fix:** `random_page_cost = 1.1` in docker-compose.yml, with OPERATIONS.md telling managed-database users to set it too.

**After:**

| Query | Before | After |
|---|---|---|
| Search (Q05) | 59 ms | 3.7 ms |
| Search count (Q06) | 64 ms | 1.5 ms |
| Suggestions (Q07) | 60 ms | 1.8 ms |
| `/products?q=font` page | 207 ms | 67 / 67 ms |
| `/search/suggest` page | 108 ms | 54 / 48 ms |

**Side effect:** the setting alone made the newest-first catalogue query slower (34 -> 40 ms, other plan). The new catalogue index (PERF-08) removes that: 0.2 ms.

**Remaining:** search terms shorter than 3 characters cannot use trigrams and still scan (about 60 ms).

### PERF-10: CSV exports paged with OFFSET (Low, database; fixed)

**Root cause:** `chunk(1000)` re-reads every earlier row for each page, so a large export is quadratic. Page 150 of a year of orders took 227-412 ms (Q22); the keyset form took 21-36 ms (Q23).

**Fix:** `chunkById` (keyset) in `ExportController` and the bulk order export.

### PERF-11: Lazy loading on multi-row results in two scheduled sweeps (Medium, correctness; fixed)

**Where:**

- `DisputeService::escalateOverdue()` (buyer, seller and item per dispute).
- `ShkeeperPayoutService::reconcile()` (order per pending refund).

**Effect:**

- Outside production the N+1 guard throws as soon as two cases are due, so the sweep stops.
- In production it is N+1.
- `each()` paged with OFFSET while escalation changed the filtered status, so cases beyond 1,000 would be skipped.

**Fix:** eager loads, plus `lazyById`.

**Tests:** `test_perf11_several_overdue_disputes_escalate_in_one_sweep` and `test_perf11_several_pending_refunds_are_polled_in_one_run`. Both failed before the fix (`evidence/regression-tests-before-fix.txt`).

### PERF-12: Top products and sellers per currency cut before filtering (Medium, correctness; fixed)

**Where:** `AnalyticsService` took the top 50 rows across all currencies and then filtered by currency in PHP. A currency outside that top 50 showed an empty or wrong list on the dashboard.

**Fix:** the currency filter in SQL.

**Test:** `test_perf12_top_lists_are_filtered_by_currency_in_sql` (55 USD lines over 2 EUR lines). It returned an empty list before.

### PERF-13: Runtime image carried git metadata, tests and docs (Low, build; fixed)

**Root cause:** When Composer cannot download a dist archive (rate limit, proxy), it clones from git, and those `.git` directories (340 MB of the 466 MB vendor directory in the sandbox build) went into the image. Tests and docs were also copied.

**Fix:** `.git` directories are pruned in the vendor stage, and `tests`, `docs`, `scripts`, `.github` and `.semgrep` are in `.dockerignore`.

**After:** the app image is 1.01 GB -> 330 MB (sandbox build; a dist-based CI build has no `.git` directories to begin with). The budget is checked in CI (`GATE_IMAGE`).

### PERF-14: Shkeeper polling had no stop after repeated failures (Medium, reliability; fixed)

**Where:** `reconcilePending()` polled up to 200 invoices one after another, with a 10 s timeout each. `ShkeeperPayoutService::reconcile()` polled up to 100 payouts plus 100 refunds the same way.

**Effect:** With Shkeeper down, one run could take over half an hour and hold back later tasks of the same scheduler run. The Laravel scheduling documentation says tasks due at the same time "will execute sequentially".

**Fix:**

- Stop after 3 failures in a row.
- Poll newest first.
- `runInBackground()` for both commands.

**Tests:** `test_perf14_payment_polling_stops_after_failures_in_a_row` and `test_perf14_transfer_polling_stops_after_failures_in_a_row` (exactly 3 requests for 5 due items).

### PERF-15: Database cache never deleted expired entries (Low, database; fixed)

**Root cause:** Laravel's database cache store deletes an expired row only when that same key is read again, so per-address rate-limit counters of one-time visitors stay forever.

**Fix:** `shop:prune-cache` runs hourly, in batches of 5,000.

**Test:** `test_perf15_expired_cache_entries_are_pruned`.

### PERF-16: Licence key import ran two queries per key under a row lock (Low, database; fixed)

**Before:** up to 2,000 statements while the product row was locked.

**Fix:** one lookup and batched inserts through the model's encryption cast.

**Test:** `test_perf16_licence_key_import_uses_a_fixed_number_of_queries`. It allows fewer than 25 queries for 450 keys; the keys must be stored encrypted and readable. Before the fix the same import ran 866 queries.

### PERF-17: Static analysis: 392 findings at PHPStan level 5 (Medium, code quality; fixed)

Details are in [code-quality-report.md](code-quality-report.md).

- Most findings came from configuration and missing relation generics, not defects.
- Real issues fixed:
  - Notifier called `envelope()`, which the `InAppNotice` contract did not require.
  - The JSON log tap assumed every handler takes a formatter.
  - The simulation seeder read `env()`, which is empty once configuration is cached.
  - Three `match` expressions had no failure branch.
- **After:** 0 findings; gate check `quality-types`.

### PERF-18: Admin dashboard live version: eight queries per poll (Low, database; fixed)

**Before:** 22 queries per poll, every 10 s per open dashboard. This was over the provisional poll budget of 20. The test counted 6 version queries before the fix.

**Fix:** one statement with subqueries. **After:** 15 queries.

**Test:** `test_perf18_admin_dashboard_version_is_one_query_and_still_tracks_changes`.

### PERF-19: Buffered downloads had no concurrency bound (Medium, resource; fixed)

**Measured** (`evidence/slow-downloads.json`):

- 25 slow clients on a 20 MB file held no PHP-FPM worker: only 8 workers were alive, all sleeping, and the catalogue stayed at 67-72 ms.
- nginx buffers FastCGI responses (default `fastcgi_buffering on`).
- The buffer is `/tmp`, a tmpfs, so the bytes sit in memory with no bound on concurrent downloads.

**Fix:** `limit_conn` of 6 downloads per client address, answering 429 before PHP counts a download.

**Re-run:** 6 x 200 and 19 x 429; the download counter shows 6.

**Residual:** a client with many addresses (R-04 in the summary).

### PERF-20: LCP image lazy-loaded (Low, assets; fixed)

**Where:** every product card image had `loading="lazy"`, including the first one, which is the LCP element on the catalogue and search pages.

**Fix:** the first two cards on home and catalogue load eagerly with `fetchpriority="high"`.

**Lab result (with PERF-05):**

| Page | Before LCP | After LCP |
|---|---|---|
| Catalogue | 592 ms | 436 ms |
| Search | 724 ms | 424 ms |

### PERF-21: Dead code and duplicate queries (Low, code quality; fixed)

- `AnalyticsService::attention()` had no callers.
- `fresh()` was called twice in one expression in `Admin/UserController` and `Admin/PayoutController`.

### PERF-22: HTML is not compressed (Info, network; accepted, unchanged)

**Measured:** the catalogue is 19.1 KB raw and 4-5 KB gzip.

**Kept on purpose:** pages carry the CSRF token next to reflected input (the search box), which is what BREACH needs (OPERATIONS.md). CSS, JavaScript, SVG and the sitemap are compressed.

**Possible later:** per-response token masking, after which HTML could be compressed.

### PERF-23: Infrastructure queries per request (Info, capacity; documented)

**Measured:** 8-17 queries per page. Of these, 3-9 are infrastructure, because cache, sessions and rate limiting use PostgreSQL:

- the settings cache;
- session read and write;
- the announcement cache;
- the rate-limit transaction of four statements on throttled routes.

All are primary-key lookups (0.05-4 ms).

**Recommendation:** at several times the measured load, move cache, sessions and rate limits to Redis. This is an architecture change, not needed at the measured volume.

### PERF-24: Admin dashboard: 43 queries, 640 ms (Low, database; open, within budget)

**Measured:** 618 / 569 ms after the indexes, against 844 ms before, which is under the provisional admin budget of 1 s.

**Possible later:** cache the KPI block for 1-5 minutes, or merge the per-period figures into one `FILTER` query.

### PERF-25: Live-update polling cost (Info, capacity; documented)

**Measured per poll:** 15-18 queries and about 50 ms, every 10 s per visible signed-in tab.

**Implication:** 1,000 visible tabs mean about 100 requests/s for polling alone. That is beyond what this sandbox sustained (about 40 requests/s at p95 under 0.6 s).

**Recommendation (owner decision):** a longer interval or server push before that scale.

### PERF-26: Admin user search and audit log counts scan (Low, database; open, within budget)

**Admin user search:** `ILIKE '%term%'` on email and name of 50,000 users, with no trigram index, takes 184 ms.

**Audit log:** exact `count(*)` over 1,000,000 rows on every page. Both grow linearly; both are staff-only and within budget.

**Possible later:** trigram indexes on `users`, and `simplePaginate` for logs.

### PERF-27: Queue timeouts (Low, reliability; open)

The worker's default `--timeout` (60 s) equals `CLAMAV_TIMEOUT` (60 s). A scan of a large file that takes longer is killed and retried, up to 5 tries.

`retry_after` (90 s) is above the worker timeout, as the Laravel queue documentation requires, so jobs are not run twice.

**Recommendation:** an explicit `$timeout` on `ScanProductFile` and a matching worker `--timeout`, if real scans approach 60 s. This was not observed with the test scanner.

### PERF-28: Polling of expired invoices for 7 days (Info, integration; documented)

At the dataset's order rate, up to 200 status calls go to Shkeeper every 5 minutes, for invoices of expired orders that may still be paid late. PERF-14 bounds the cost when Shkeeper fails.

**Possible later:** decaying intervals for old invoices, if Shkeeper load matters.

## Candidates checked and not confirmed

| Candidate | Result |
|---|---|
| Downloads hold a PHP-FPM worker per slow client | Not confirmed (PERF-19 experiment); the memory side was fixed |
| N+1 on the order, notification, seller and admin list pages | Not confirmed; guard tests keep it that way |
| `orders (buyer_id, updated_at)` needed for the live poll | Not confirmed with realistic data |
| Mailables sent synchronously | All implement `ShouldQueue`; the health alert is sent directly on purpose |
| Missing HTTP timeouts | Shkeeper calls have a 10 s timeout and a 5 s connect timeout, with deliberately no automatic retries (non-idempotent creates) |
| Unbounded caches | Every cache entry has a TTL, except email templates, which are invalidated on save; expired entries are now pruned (PERF-15) |
