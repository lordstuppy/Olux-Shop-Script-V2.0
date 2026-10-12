# Performance Remediation Log

Each change in the order it was made, with its regression test and the measurement before and after. Commits are on branch `claude/zen-pasteur-vs99pr`.

**Baseline:** `e224388`. **Candidate:** `de3000b`, application code and image. The nginx template change of `577d35f` is mounted at run time.

**Measured with:**

- `tests/performance/bench.py` (p50; the second value is the candidate's second run);
- `tests/performance/explain.sql`;
- the browser and load tooling in the same folder.

Conditions are in [performance-baselines.md](performance-baselines.md).

## Changes

| Finding | Commit | Files | Regression test | Before | After |
|---|---|---|---|---|---|
| PERF-03 round trips | ad68a6a | `config/database.php` | Full suite (behaviour unchanged); PostgreSQL log shows no `DEALLOCATE` or named statements | 36 round trips for 11 queries (catalogue) | 11 for 10 |
| PERF-02 best selling | ad68a6a | migration `2026_10_16_000100`, `CatalogService`, `routes/console.php` (`shop:refresh-sales-ranking`, every 10 min) | `test_perf02_sales_ranking_counts_paid_units_only`; `test_best_seller_sort_and_related_products` updated (runs the refresh) | 859 ms (sort=best), 210 ms (product) | 91 / 72 ms, 63 / 61 ms |
| PERF-01 reconciliation | ad68a6a | `PayoutService` | `test_perf03_earning_sql_matches_earning_for`, `test_perf03_reconciliation_reports_only_the_tampered_line`, existing `test_reconciliation_detects_tampering` | 18,585 ms, 821 queries | 399 / 382 ms, 11 queries |
| PERF-08 indexes | ad68a6a, dfcf4b9 | migrations `2026_10_16_000200` (4 indexes) and `000300` (catalogue order) | Plans in `explain-after.txt` | Q10 51 ms, Q12 56 ms, Q01 25-34 ms, Q17 27 ms, Q16 35 ms | 0.06, 0.2, 0.2, 0.13, 3 ms |
| PERF-09 search planner | ad68a6a | `docker-compose.yml`, `docker-compose.test.yml` (`random_page_cost=1.1`), OPERATIONS.md | Plans Q05-Q07 | Search page 207 ms | 67 / 67 ms |
| PERF-05 seller filter | ad68a6a, b8e72ce | `CatalogService::sellers()`, `ProductController` | `test_perf05_seller_filter_lists_the_top_sellers_and_the_selected_one`; existing seller filter test | 41,963 B catalogue HTML | 19,126 B |
| PERF-06 sitemap | ad68a6a | `PageController`, `routes/web.php` | `test_perf06_sitemap_is_cached_compressed_and_sessionless`; existing sitemap tests | 891 ms, 2.07 MB | 37 / 30 ms, 154 KB |
| PERF-07 images without session | ad68a6a | `ProductImageController`, `ProductImage::url()`, routes, two views | `test_images_are_reencoded_and_served` (updated: preview route, no cookies) | Session read and write plus `Set-Cookie` per image | None |
| PERF-04 N+1 | ad68a6a | `Product::isPurchasable()`, `WishlistController`, `CartService`, `OrderController` (reorder), `CatalogService` | `test_perf04_*` (5 tests, 18 pages) | Wishlist 8 -> 14 queries, cart 3 -> 8 when data grew | Constant |
| PERF-10 exports | ad68a6a | `ExportController`, `BulkController` | Existing export tests | Q22 OFFSET page 227-412 ms | Keyset Q23 21-36 ms |
| PERF-11 sweeps | ad68a6a | `DisputeService`, `ShkeeperPayoutService` | `test_perf11_several_overdue_disputes_escalate_in_one_sweep`, `test_perf11_several_pending_refunds_are_polled_in_one_run` | Exception (lazy loading) with 2 cases | Pass |
| PERF-12 top lists | ad68a6a | `ReportService`, `AnalyticsService` | `test_perf12_top_lists_are_filtered_by_currency_in_sql` | Empty list | Correct |
| PERF-14 polling | ad68a6a | `PaymentService`, `ShkeeperPayoutService`, schedule | `test_perf14_payment_polling_stops_after_failures_in_a_row`, `test_perf14_transfer_polling_stops_after_failures_in_a_row` | Up to 200 sequential timeouts | Stops after 3 |
| PERF-15 cache pruning | ad68a6a | `routes/console.php` (`shop:prune-cache`, hourly) | `test_perf15_expired_cache_entries_are_pruned` | Never pruned | Hourly |
| PERF-13 image | ad68a6a | `Dockerfile`, `.dockerignore` | Image size budget (gate `image`) | 1.01 GB | 330 MB |
| PERF-16 key import | b8e72ce | `Seller\ProductController::addKeys` | `test_perf16_licence_key_import_uses_a_fixed_number_of_queries` | 866 queries for 450 keys | Under 25 |
| PERF-20 LCP image | b8e72ce | `partials/product-card`, home, catalogue | Lab Web Vitals | Catalogue LCP 592 ms | 436 ms |
| PERF-21 dead code | b8e72ce | `AnalyticsService`, `Admin\PayoutController`, `Admin\UserController` | Full suite | - | - |
| PERF-17 static analysis | 571ac38 | `phpstan.neon`, models (generics), `InAppNotice`, `JsonFormatterTap`, seeders, `config/shop.php`, three `match` expressions | Gate `quality-types` | 392 findings | 0 |
| PERF-18 admin poll | de3000b | `LivePages` | `test_perf18_admin_dashboard_version_is_one_query_and_still_tracks_changes` | 22 queries per poll | 15 |
| PERF-19 download memory | 577d35f | `docker/nginx/default.conf.template` (`limit_conn` 6 per client) | `tests/performance/slow-downloads.py` | 25 of 25 buffered at once | 6 x 200, 19 x 429; counter +6 |
| Gate and CI | b8e72ce, 571ac38 | `scripts/performance-gate.sh`, `tests/performance/budgets.json`, `check-budgets.py`, `.github/workflows/ci.yml` (job `performance`), `composer.json` (Larastan) | Gate run locally: PASS for every check that can run without the stack | - | - |

## Before-and-after verification

- **Before the fixes:** 15 of the 19 regression tests failed on the baseline code ([evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt)). The 4 that passed are guards for pages that had no N+1.
- **After:**
  - PHPUnit 313 / 313 (4,098 assertions);
  - Pint clean; PHPStan level 5 with 0 errors;
  - ASCII check clean;
  - all page and query budgets met in two benchmark runs;
  - lab Web Vitals within budget.
- **Simulation:** 101 scenarios on the candidate image, recorded in the summary.

## Process errors, corrected

These affected intermediate measurements, not the final evidence. They are listed so the evidence can be judged.

1. **Uniform timestamps in the first dataset.** `scale-data.sql` used `CROSS JOIN LATERAL (SELECT now() - random() ...)` without referring to the outer row. PostgreSQL evaluated it once, so every order had the same timestamp. Time-based plans measured on that data were not representative.
   - One index, `orders (buyer_id, updated_at)`, had been added on that evidence; it was removed again.
   - The generator was fixed (`+ 0 * g`), and the baseline was rebuilt from the baseline code and its own compose files and measured again.
2. **Encrypted column.** The first dataset put plain text into the encrypted `delivered_payload` column, so order pages returned 500. The column is now left empty.
3. **Earnings rounding.** Generated earnings and ledger entries used truncating division instead of the half-up commission rounding, which would have shown false reconciliation mismatches. Fixed before the measured baseline.
4. **Benchmark tooling:**
   - The first pilot signed in again for every page and hit the login rate limit.
   - Reading the PostgreSQL log with `docker logs --since` scanned the whole log each time; it now reads from a byte offset.
   - `ALTER SYSTEM` cannot override the stack's command-line logging setting; a role setting is used.
   - Counting unnamed statements' parse messages as extra round trips overstated the candidate; corrected before the final run.
5. **Cold statistics.** The first after-measurement ran right after `pg_restore`, without statistics, and showed several plans as slower. Every measurement now runs after `VACUUM ANALYZE` and a warm-up pass.
6. **Wrapped tool output.** The static-analysis output is shortened by a wrapper in this environment. The full list was read from PHPStan's own baseline file, written to a scratch directory and not committed.
