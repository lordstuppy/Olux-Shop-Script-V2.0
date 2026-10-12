# Database Performance Audit

PostgreSQL 15 (production compose and simulation stack), with Laravel 13 Eloquent and the query builder. Plans were taken with `EXPLAIN (ANALYZE, BUFFERS)` on the dataset of `tests/performance/scale-data.sql`, on a disposable copy (`tests/performance/explain.sql`). Full plans:

- [evidence/explain-baseline.txt](evidence/explain-baseline.txt)
- [evidence/explain-after.txt](evidence/explain-after.txt)

Volumes, conditions and all timings are in [performance-baselines.md](performance-baselines.md).

## What was inspected

**Every page in the benchmark (46 pages).** For each:

- every SQL statement it runs, with its duration and round trips, taken from the PostgreSQL log (`bench.py`);
- the slowest statements, in their real shape.

**Every scheduled task in `routes/console.php`**, checked for full scans of growing tables:

- expiry;
- stuck-order recovery;
- reconciliation;
- staff digest;
- escalation;
- pruning.

**Every `chunk`, `each`, `lazy` and loop that runs queries** (search over `app/` and `routes/`).

**The existing indexes per table** (listed by `pg_indexes`), against the WHERE, JOIN and ORDER BY patterns of the hot queries.

## Query efficiency findings

| Finding | Pattern | Fix | Evidence |
|---|---|---|---|
| PERF-01 | Reconciliation iterated all paid lines in PHP: 821 queries per view | Check in SQL, mismatches only | 18.6 s -> 0.4 s; 11 queries |
| PERF-02 | Correlated `SUM` over order lines per listed product, for sorting | Stored `units_sold`, refreshed every 10 min | 986 ms -> 0.3 ms (Q03) |
| PERF-03 | PREPARE / EXECUTE / DEALLOCATE per query | Unnamed statements (`ATTR_DISABLE_PREPARES`) | Round trips 36 -> 11 on the catalogue |
| PERF-04 | Query per product for the seller status (wishlist, cart, reorder) | Eager-loaded seller, checked in memory | Query count independent of lines (tests) |
| PERF-10 | `chunk()` with OFFSET in exports | `chunkById` (keyset) | Q22 227-412 ms vs Q23 21-36 ms per page |
| PERF-11 | Lazy loading in two scheduled sweeps; `each()` with OFFSET while changing the filtered column | Eager loads, `lazyById` | Regression tests failed before |
| PERF-12 | Top 50 across currencies, then filtered in PHP | Currency in SQL | Regression test failed before |
| PERF-16 | Two queries per licence key under a row lock | One lookup, batched inserts | Under 25 queries for 450 keys |
| PERF-18 | Eight aggregates per dashboard poll | One statement with subqueries | 22 -> 15 queries per poll |

**N+1 guard.** The application already throws on lazy loading outside production (`Model::preventLazyLoading`). The simulation's production logs over 101 scenarios contained no lazy-loading warning.

The two sweeps of PERF-11 were the exceptions: the scenarios never had two cases due at once. The new query-count tests now cover the pages:

- they load each page at two data sizes;
- they require the same number of queries at both sizes.

## Index decisions

Each index has a measured query behind it, and each was built `CONCURRENTLY`.

### Added

| Index | Justification (plan before -> after) | Write cost |
|---|---|---|
| `products (status, units_sold, id)` | Best-selling order without sorting: Q03 986 -> 0.3 ms, Q04 101 -> 0.22 ms | Updated by the 10-minute refresh, changed rows only |
| `products (status, created_at, id)` | Default newest-first order, every catalogue and home page: Q01 25-34 -> 0.2 ms (backward index scan, 12 rows) | Product inserts and status changes (rare) |
| `order_items (seller_id, id)` | Seller's latest sales and the seller live poll (every 10 s per tab): Q12 56 -> 0.2 ms, Q10 51 -> 0.06 ms (was a backward scan of the primary key filtering 244,000 rows) | One entry per order line |
| `order_items (delivered_at)` | `max(delivered_at)` for the admin order list's live version (every 10 s per admin tab): Q17 27 -> 0.13 ms | One update per delivery |
| `orders (paid_at)` | Dashboard and report periods and the every-minute recovery job: Q14 48 -> 20 ms, Q15 89 -> 47 ms, Q18 45 -> 17 ms, Q20 44 -> 17 ms | One update per payment |
| `orders (created_at)` | Conversion KPIs and exports: Q16 35 -> 3 ms | Insert only |

### Considered and rejected

| Index | Why not |
|---|---|
| `orders (buyer_id, updated_at)` | With realistic timestamps the planner uses the existing `(buyer_id, id)` index for the buyer's latest change: Q09 0.31 ms and Q09b 0.24 ms before any change. An earlier measurement suggesting it came from a faulty dataset; see the remediation log. |
| `seller_ledger_entries (seller_id, id)` | Q11 is 1.5-3.6 ms through the existing `(seller_id, currency, created_at)` index at 5,880 sales for one seller. Not worth the write cost yet. |
| `webhook_events (provider, status, processed_at)` | Q21 (30 ms) is a fallback on the health page, used only when the gateway log is empty. |
| Trigram indexes on `users (email, name)` | Admin user search is 184 ms at 50,000 users, within the admin budget (PERF-26). |
| `text_pattern_ops` for the audit action prefix filter | Staff-only, within budget. |
| Partial index on paid order states | `paid_at` alone already serves the measured ranges; nearly all orders with `paid_at` are in paid states. |

### Existing indexes reviewed

`products_status_category_id_index`, the two trigram GIN indexes, the foreign-key indexes from `2026_10_13_000100_index_foreign_keys` and the unique constraints were all used by measured plans or enforce integrity.

No index was found redundant enough to drop with evidence. `products_category_id_index` overlaps `(status, category_id)` but serves the foreign key for category deletes.

## Planner configuration (PERF-09)

The catalogue search (`title ILIKE '%term%' OR description ILIKE '%term%'`) has GIN trigram indexes on both columns. With `random_page_cost = 4`, the default, which models spinning disks, PostgreSQL chose a sequential scan and evaluated `ILIKE` on every description:

| Query | `random_page_cost = 4` | `random_page_cost = 1.1` (BitmapOr of both trigram indexes) |
|---|---|---|
| Search (Q05) | 59 ms | 3.7 ms |
| Count (Q06) | 64 ms | 1.5 ms |
| Suggestions (Q07) | 60 ms | 1.8 ms |

The PostgreSQL 15 manual (runtime-config-query) says decreasing it "might be appropriate" when data is largely cached, and that the default of 4 assumes most random accesses are cached. 1.1 is the common choice for SSD storage. It is set in `docker-compose.yml` (and the simulation overlay); OPERATIONS.md asks managed-database users to set it.

**Side effect:** the newest-first catalogue query got a worse plan (34 -> 40 ms) until its own index was added (0.2 ms).

**Not covered:** terms shorter than 3 characters cannot use trigrams; pg_trgm documents that the search works by extracting trigrams. They still scan (about 60 ms at 20,000 products).

## Transactions, locking and correctness

| Topic | Result |
|---|---|
| Checkout row locks | Unchanged. `OrderService` still locks products and asks the database for the seller state (not the in-memory check). |
| Licence key import | Still one transaction with the product row locked, now with a fixed number of statements. |
| Reconciliation | Read-only. The SQL earning expression is tested against the PHP formula on 300 generated amounts, including all half-way rounding cases. |
| Sales ranking refresh | One `UPDATE ... FROM` with only changed rows written. No lock on a hot path (product rows are read with `FOR SHARE` only at checkout). |
| Dispute escalation | `lazyById` pages by key, so escalating (which changes the filtered status) no longer skips rows. Each case is still re-checked under `lockForUpdate`. |
| Index builds | `CREATE INDEX CONCURRENTLY` in non-transactional migrations, so writes continue during a deploy. |
| Migrations on the full dataset | 2.0 s (ranking column with initial fill), 0.7-0.8 s (four indexes), 0.05 s (catalogue index). |

## Connections and timeouts

- PHP-FPM opens one connection per request (no persistent connections), with a 5 s connect timeout (`DB_CONNECT_TIMEOUT`).
- After the staged load, no connections were left open (3 sessions: the monitoring query, queue and scheduler).
- With unnamed statements the application also works behind PgBouncer in transaction mode; named server-side prepared statements do not.
- There is no `statement_timeout`. All web queries measured here take under 0.5 s. A per-role statement timeout could cap a runaway report, but needs care for exports and migrations: an owner decision, not changed.

## Remaining limits

The open items are listed in the findings:

- PERF-23 (database-backed cache and sessions)
- PERF-24 (admin dashboard)
- PERF-26 (admin user search, exact log counts)

None exceeds its provisional budget at the measured volume.
