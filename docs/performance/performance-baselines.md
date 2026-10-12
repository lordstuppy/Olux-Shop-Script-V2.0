# Performance Baselines, Budgets and Measurements

All figures are lab measurements on the disposable simulation stack. They are not real-user data. The machine was a sandbox with 4 CPU cores shared by the load generator, PHP-FPM, PostgreSQL, nginx and the TLS front end. Production hardware, a separate load generator and real traffic were not available (see the limitations at the end).

## Conditions

### Stack and code

- **Stack:** `docker-compose.yml` with `docker-compose.test.yml`:
  - the production app image (PHP 8.3 FPM, OPcache on, no timestamp validation, 20 workers);
  - nginx 1.30 (unprivileged) with a TLS front end;
  - PostgreSQL 15;
  - database cache, sessions and queue (the shipped defaults).
- **Baseline:** code `e224388` (image `sha256:5dbe52973f08`, built from that revision with its own compose files, so `random_page_cost` was 4).
- **Candidate:** code `de3000b` (image `sha256:3276b8e01c84`, web image `sha256:d32c84c3ad54`), plus the nginx template change of `577d35f`, which is mounted by compose, not baked into the image.

The `revision` field in the bench JSON is the revision of the measuring harness, not of the measured code.

### Dataset

`tests/performance/scale-data.sql` creates a provisional "first year" volume on top of the simulation seed, restored from one `pg_dump` for every run so baseline and candidate saw identical data:

| Table | Rows |
|---|---|
| products | 20,200 (17,185 listed) |
| sellers | 512 |
| users | 50,565 |
| orders | 200,000 over 365 days (75% delivered, 12% expired, 5% cancelled, 6% refunded, 2% pending) |
| order lines | 250,000 |
| payments | 200,000 |
| invoices | 156,000 |
| ledger entries | 420,000 |
| reviews | 40,000 |
| notifications | 500,000 |
| audit entries | 1,000,000 |
| webhook events | 200,000 |

Two accounts are deliberately heavy: buyer01 has 500 orders and seller01 has 416 products and 5,880 sales. 24 products got real images (`seed-images.py`) before the browser measurements.

### Page benchmark (`bench.py`)

- One request with statement logging to count SQL statements and round trips.
- Then 3 warm-up and 30 timed sequential requests over one keep-alive TLS connection, with queue worker and scheduler paused.
- Latency is client-side time to the full body (server time plus TLS on localhost).

**Noise:** two candidate runs of identical code differed by -18% to +15% per page (median -1%). An earlier pair of runs differed by -25% to +12%. Differences smaller than about 25% are not claimed.

## Budgets (provisional)

`tests/performance/budgets.json`. They were proposed for this audit because no budgets existed. They are **provisional until the owner approves them**.

| Scope | p95 (single user, lab) | Queries per request |
|---|---|---|
| Public, buyer and seller pages | 300 ms | 25 |
| Live-update polls | 100 ms | 20 |
| Search suggestions | 150 ms | 15 |
| Sitemap | 300 ms | 10 |
| Admin pages | 1,000 ms | 60 |
| Admin reconciliation (audit tool) | 5,000 ms | 25 |
| CSS | 25,000 bytes raw, 6,500 gzip | - |
| JavaScript | 30,000 bytes raw, 8,000 gzip | - |
| App image | 400 MB | - |
| Web image | 120 MB | - |

**Lab Web Vitals** (thresholds from web.dev "Web Vitals"):

| Metric | Budget |
|---|---|
| LCP | 2,500 ms |
| CLS | 0.1 |
| INP (approximated in the lab) | 200 ms |
| TBT | 200 ms (lab proxy for interactivity) |

**Result:**

| Run | Over budget |
|---|---|
| Baseline | 5 pages: best-selling catalogue, sitemap, seller live poll, reconciliation, admin dashboard poll (query count) |
| Candidate, run 1 | 0 |
| Candidate, run 2 | 0 |
| CSS and JavaScript size budgets | Pass (17.9 KB / 4.7 KB gzip; 20.5 KB / 5.6 KB gzip) |

## Page latency, queries, round trips and size

Before means `bench-baseline.json`; after means candidate run 1 and run 2 (`bench-after.json`, `bench-after-run2.json`). Body bytes are as transferred (static files gzip; HTML is not compressed, see PERF-22).

| Page | p50 ms before | p50 after (run 1 / run 2) | p95 before | p95 after (run 1 / run 2) | Queries | Round trips | Body bytes |
|---|---|---|---|---|---|---|---|
| home | 75 | 52 / 59 | 88 | 60 / 68 | 8 -> 8 | 27 -> 9 | 7,435 -> 8,357 |
| catalog | 94 | 88 / 86 | 110 | 115 / 114 | 11 -> 10 | 36 -> 11 | 41,963 -> 19,126 |
| catalog page 50 | 97 | 112 / 123 | 116 | 155 / 141 | 11 -> 10 | 36 -> 11 | 41,717 -> 16,993 |
| catalog best sellers | 859 | 84 / 85 | 1029 | 107 / 95 | 11 -> 10 | 36 -> 11 | 41,693 -> 16,969 |
| catalog category | 78 | 70 / 59 | 103 | 82 / 70 | 11 -> 10 | 36 -> 11 | 41,788 -> 17,390 |
| catalog price range | 88 | 86 / 87 | 101 | 105 / 95 | 12 -> 11 | 39 -> 12 | 42,208 -> 19,064 |
| catalog seller | 63 | 66 / 74 | 70 | 78 / 104 | 11 -> 10 | 36 -> 11 | 42,073 -> 17,674 |
| search | 207 | 70 / 79 | 270 | 80 / 99 | 11 -> 10 | 36 -> 11 | 41,814 -> 18,042 |
| search rare | 57 | 54 / 58 | 68 | 68 / 75 | 7 -> 8 | 24 -> 9 | 34,686 -> 9,962 |
| search suggest | 108 | 53 / 55 | 134 | 65 / 68 | 12 -> 12 | 35 -> 13 | 859 -> 859 |
| product | 210 | 76 / 71 | 258 | 88 / 90 | 19 -> 18 | 60 -> 19 | 9,578 -> 9,578 |
| sitemap | 891 | 36 / 39 | 1047 | 42 / 43 | 5 -> 2 | 18 -> 3 | 2,073,357 -> 154,262 |
| buyer orders | 59 | 62 / 71 | 69 | 83 / 85 | 10 -> 10 | 33 -> 11 | 16,297 -> 16,297 |
| buyer order | 77 | 82 / 76 | 86 | 109 / 94 | 17 -> 17 | 54 -> 18 | 8,086 -> 8,086 |
| buyer notifications | 51 | 63 / 57 | 60 | 78 / 75 | 10 -> 10 | 33 -> 11 | 17,045 -> 17,045 |
| buyer live poll | 53 | 68 / 62 | 61 | 76 / 74 | 16 -> 16 | 47 -> 17 | 892 -> 892 |
| buyer wallet | 44 | 58 / 57 | 53 | 67 / 63 | 8 -> 8 | 27 -> 9 | 6,141 -> 6,141 |
| buyer product | 226 | 96 / 86 | 270 | 116 / 104 | 24 -> 22 | 75 -> 23 | 11,405 -> 11,405 |
| seller dashboard | 203 | 102 / 108 | 261 | 120 / 127 | 17 -> 17 | 54 -> 18 | 11,290 -> 11,282 |
| seller sales | 173 | 100 / 110 | 213 | 120 / 127 | 14 -> 17 | 45 -> 18 | 22,416 -> 22,408 |
| seller products | 66 | 78 / 82 | 77 | 92 / 102 | 10 -> 10 | 33 -> 11 | 19,541 -> 19,533 |
| seller product edit | 64 | 63 / 59 | 74 | 77 / 76 | 12 -> 12 | 39 -> 13 | 13,662 -> 13,654 |
| seller payouts | 109 | 94 / 98 | 121 | 113 / 122 | 13 -> 13 | 42 -> 14 | 7,497 -> 7,489 |
| seller live poll | 103 | 61 / 60 | 130 | 69 / 68 | 18 -> 18 | 53 -> 19 | 892 -> 892 |
| seller reviews | 78 | 74 / 78 | 88 | 91 / 105 | 11 -> 12 | 36 -> 13 | 31,943 -> 31,935 |
| admin dashboard | 844 | 640 / 635 | 901 | 721 / 735 | 50 -> 43 | 153 -> 44 | 51,462 -> 51,463 |
| admin reports | 331 | 290 / 256 | 365 | 348 / 319 | 12 -> 12 | 39 -> 13 | 30,673 -> 30,673 |
| admin orders | 106 | 122 / 113 | 114 | 143 / 135 | 12 -> 12 | 39 -> 13 | 67,305 -> 67,305 |
| admin orders search | 109 | 113 / 127 | 124 | 141 / 151 | 10 -> 10 | 33 -> 11 | 45,101 -> 45,101 |
| admin order | 69 | 82 / 67 | 83 | 100 / 78 | 17 -> 17 | 54 -> 18 | 15,194 -> 15,194 |
| admin products | 74 | 90 / 76 | 90 | 105 / 85 | 11 -> 11 | 36 -> 12 | 59,912 -> 59,737 |
| admin users | 65 | 71 / 70 | 77 | 91 / 94 | 8 -> 8 | 27 -> 9 | 31,504 -> 31,504 |
| admin users search | 183 | 194 / 184 | 213 | 261 / 241 | 11 -> 8 | 36 -> 9 | 17,351 -> 17,351 |
| admin user | 63 | 75 / 63 | 79 | 95 / 85 | 11 -> 11 | 36 -> 12 | 22,086 -> 22,086 |
| admin audit | 91 | 96 / 110 | 109 | 107 / 138 | 9 -> 9 | 30 -> 10 | 31,631 -> 31,631 |
| admin payments | 72 | 73 / 82 | 89 | 84 / 102 | 9 -> 9 | 30 -> 10 | 37,824 -> 37,824 |
| admin payouts | 48 | 52 / 54 | 63 | 61 / 69 | 8 -> 8 | 27 -> 9 | 8,637 -> 8,637 |
| admin gateway | 59 | 63 / 58 | 70 | 79 / 71 | 12 -> 12 | 39 -> 13 | 14,809 -> 41,549 |
| admin health | 97 | 99 / 95 | 107 | 118 / 129 | 21 -> 21 | 66 -> 22 | 14,252 -> 14,253 |
| admin reviews | 65 | 73 / 72 | 74 | 98 / 85 | 11 -> 11 | 36 -> 12 | 43,778 -> 43,778 |
| admin sellers | 62 | 62 / 69 | 78 | 83 / 94 | 10 -> 10 | 33 -> 11 | 23,442 -> 23,442 |
| admin disputes | 57 | 55 / 61 | 78 | 63 / 70 | 9 -> 9 | 30 -> 10 | 8,816 -> 8,816 |
| admin reconciliation | 18585 | 386 / 382 | 19488 | 435 / 455 | 821 -> 11 | 2466 -> 12 | 8,463 -> 8,463 |
| admin live poll dashboard | 53 | 66 / 60 | 67 | 79 / 75 | 22 -> 15 | 65 -> 16 | 892 -> 892 |
| admin live poll orders | 36 | 47 / 42 | 41 | 59 / 52 | 16 -> 16 | 47 -> 17 | 892 -> 892 |

## Query plans (EXPLAIN ANALYZE, warm cache)

From `explain-baseline.txt` and `explain-after.txt`. "n/a" means the query shape did not exist before (new column) or was added to the set later. Single executions, so they carry the same noise as above.

| Query | Before ms | After ms |
|---|---|---|
| Q01 catalog, newest first (page 1) | 25.24 | 0.193 |
| Q01b catalog, page 50 (deep page: still sorts) | n/a | 35.304 |
| Q02 catalog count | 22.758 | 17.83 |
| Q03-old catalog, best selling (before: units summed per product in the query) | 985.971 | 890.877 |
| Q04-old related products (before) | 101.007 | 163.992 |
| Q08-old seller filter options (before: every seller) | 3.838 | 3.359 |
| Q03 catalog, best selling (stored ranking; after migration 2026_10_16_000100) | n/a | 0.304 |
| Q04 related products in a category (stored ranking) | n/a | 0.221 |
| Q05 search "font", title or description | 58.835 | 3.743 |
| Q06 search count | 63.859 | 1.532 |
| Q07 search suggestions "fon" | 60.126 | 1.813 |
| Q08 seller filter options (top sellers by units sold) | n/a | 21.698 |
| Q09 buyer order list version (live updates): latest change of the buyer's orders | 0.313 | 0.284 |
| Q09b the same for a buyer whose last order is old (most buyers): the latest-change scan walks all newer orders | 0.241 | 0.102 |
| Q10 seller live version: latest sale id | 50.882 | 0.064 |
| Q11 seller live version: latest ledger entry id | 3.56 | 2.331 |
| Q12 seller dashboard / sales page: latest paid sales | 55.703 | 0.204 |
| Q13 seller balances: held earnings | 48.843 | 21.373 |
| Q14 admin dashboard KPI: paid orders in the last 30 days | 47.617 | 20.434 |
| Q15 admin dashboard KPI: commission in the last 30 days | 88.922 | 47.114 |
| Q16 admin dashboard KPI: orders created in the last 30 days | 35.238 | 3.122 |
| Q17 admin order list version (live updates): latest delivery | 27.439 | 0.132 |
| Q18 shop:recover-stuck-orders (every minute): uninvoiced paid orders of the last 7 days | 44.95 | 16.712 |
| Q19 shop:recover-stuck-orders: undelivered instant lines | 0.109 | 0.103 |
| Q20 admin reports: revenue per day, last 30 days | 43.562 | 16.546 |
| Q21 system health: last processed webhook | 31.412 | 30.406 |
| Q22 CSV export, OFFSET paging: page 150 of a year of orders | 226.62 | 412.329 |
| Q23 CSV export, keyset paging: the same page | 35.924 | 20.68 |
| Q24 reconciliation check 1 in SQL: lines whose earning does not follow from their amounts | 53.307 | 84.894 |
| Q25 sitemap | 22.695 | 46.379 |
| Q26 unread notifications badge (every signed-in page and poll) | 0.369 | 0.107 |
| Q27 admin audit log, newest page | 0.279 | 0.146 |
| Q28 sales ranking refresh (shop:refresh-sales-ranking), read part | 130.492 | 179.624 |

## Browser (lab Core Web Vitals)

`webvitals.mjs`: median of 5 cold-cache loads, mobile profile (412 x 915, DPR 2, CPU 4x slower, 9 Mbit/s, 60 ms latency). INP is approximated from Event Timing entries of one scripted interaction per page.

| Page | LCP ms | CLS | TBT ms | INP (lab) ms | Transfer bytes | HTML bytes | DOM nodes | LCP element |
|---|---|---|---|---|---|---|---|---|
| home | 376 -> 392 | 0 -> 0 | 36 -> 43 | 32 -> 24 | 22,351 -> 56,383 | 7,435 -> 8,357 | 138 -> 144 | P -> IMG.card-image |
| catalog | 592 -> 436 | 0 -> 0 | 127 -> 64 | 0 -> 0 | 91,587 -> 65,031 | 43,814 -> 19,126 | 647 -> 272 | IMG.card-image -> IMG.card-image |
| search | 724 -> 424 | 0 -> 0 | 99 -> 46 | 32 -> 32 | 92,186 -> 65,626 | 42,742 -> 18,042 | 657 -> 282 | IMG.card-image -> IMG.card-image |
| product | 496 -> 448 | 0.026 -> 0.026 | 20 -> 43 | 16 -> 16 | 81,842 -> 81,377 | 9,077 -> 9,077 | 118 -> 118 | IMG -> IMG |

Notes:

- **Home** had no product images in the baseline run (LCP element `P`) but six in the candidate run, so its LCP and transfer bytes are not comparable.
- **Catalogue and search:** lower HTML size (PERF-05), fewer DOM nodes, an eagerly loaded LCP image (PERF-20) and half the TBT.
- **CLS:** 0.026 on the product page in both runs.

## Staged load (k6)

`staged-load.js`: 40 s per stage at constant arrival rates. Mix: 35% catalogue (incl. "best selling" and deep pages), 25% product, 15% search, 10% home, 15% live polls of 40 signed-in buyers. The test aborts itself when p95 passes 5 s or errors pass 5%. k6 ran on the same 4 cores as the stack.

| Arrival rate | Before: p50 / p95 / max | After: p50 / p95 / max |
|---|---|---|
| 10 requests/s | 121 / 827 / 1401 ms, 0.0% failed | 77 / 122 / 185 ms, 0.0% failed |
| 20 requests/s | 1944 / 6945 / 10418 ms, 0.0% failed | 77 / 123 / 176 ms, 0.0% failed |
| 40 requests/s | 4700 / 6000 / 6259 ms, 0.0% failed | 108 / 550 / 1248 ms, 0.0% failed |
| 60 requests/s | not reached (test aborted) | 3277 / 5825 / 6982 ms, 0.0% failed |
| 80 requests/s | not reached (test aborted) | not reached (test aborted) |

| | Requests completed | Dropped iterations | Stopped |
|---|---|---|---|
| Before | 1226 | 123 | Aborted by its own threshold in the 40/s stage |
| After | 4250 | 276 | Aborted in the 60/s stage |

No request failed in either run.

**After the load (candidate):**

- The catalogue answered in 82-107 ms again.
- The app container used 68 MB with idle FPM workers.
- No database connections were left behind.

**Capacity statement (limited to this sandbox):**

| | Sustained | p95 |
|---|---|---|
| Before | 10 requests/s | 0.8 s; 20/s already gave p95 6.9 s |
| After | 20 requests/s | 0.12 s |
| After | 40 requests/s | 0.55 s |

This is not a production capacity figure. The sandbox shares 4 cores with the load generator, and scaling is not extrapolated.

## Slow downloads (`slow-downloads.py`)

25 clients each read a 20 MB purchased file at 50 KB/s for 45 s, against 20 FPM workers.

**Without a limit:**

- Only 8 workers were alive, all sleeping; nginx had buffered the files.
- The catalogue stayed at 72 ms p50 (max 249 ms).
- The buffers were in memory (tmpfs).

**With the 6-per-client limit (candidate):** 6 downloads got 200 and 19 got 429. The catalogue stayed at 67 ms p50 (max 144 ms), and the buyer's download counter rose by 6 only.

## Limitations

**Not measured here:**

- Production hardware and network.
- A remote database: the round-trip saving of PERF-03 is calculated, not measured.
- Real-user (field) Web Vitals.
- Traffic patterns from a live shop.
- Sustained multi-hour load and memory growth over days.
- Autoscaling.
- Multiple app instances.
- A dist-based production image build: the sandbox proxy refuses GitHub archives, so Composer installed from git.

**What would turn the NOT VERIFIED and BLOCKED gates into PASS:** the owner approves or replaces the provisional budgets, the measurements are repeated on staging hardware with the load generator on another machine, and real-user data is collected after launch.
