# Performance and Code Quality Audit: Summary

**Decision: BLOCKED - DO NOT SHIP.**

All 28 findings are either fixed or documented as decisions; the open ones are within budget. Every page meets its proposed budget in two benchmark runs, and all functional, performance and security regression checks that could run here pass.

The decision is BLOCKED because three gates need things this audit cannot supply:

| Gate | Status | What it needs |
|---|---|---|
| P14 | BLOCKED | The owner's approval of the latency and resource budgets, which this audit proposed because none existed |
| P10 | NOT VERIFIED | Measurements on production-like hardware with a separate load generator, against a target workload |
| P7 | NOT VERIFIED | Real-user Web Vitals; only lab data exists |

The security audit's own BLOCKED decision ([docs/security](../security/security-audit-summary.md)) also still applies.

## Tested revision and artifacts

- **Baseline:** code `e224388`, app image `5dbe52973f08` (1.01 GB).
- **Candidate:** code `de3000b`, app image `3276b8e01c84` (330 MB) and web image `d32c84c3ad54`.
- **Runtime configuration:** the nginx template of `577d35f`, mounted by compose.

Later commits add documentation and evidence only.

Both were measured on the same simulation stack with the same restored dataset:

- 20,200 products, 200,000 orders, 250,000 order lines;
- 1,000,000 audit entries, 500,000 notifications;
- 4 shared CPU cores.

## Gates

**14 gates: 11 PASS, 1 BLOCKED, 2 NOT VERIFIED, 0 FAIL, 0 NOT APPLICABLE.**

Machine-readable: [performance-gates.json](performance-gates.json).

| Gate | Status | Basis |
|---|---|---|
| P1 Architecture and code quality | PASS | High-impact defects fixed; PHPStan level 5 from 392 findings to 0 |
| P2 Algorithmic and runtime | PASS | Reconciliation 18.6 s -> 0.4 s, best selling 859 -> 84 ms, sitemap 891 -> 36 ms; 0 pages over budget |
| P3 Database | PASS | 46 pages' SQL and 30 query plans on representative data; 6 indexes, each justified by a plan, 1 rejected after measurement |
| P4 Caching | PASS | Only public, non-financial data cached, with TTLs; expired entries pruned; no cookies on public images and the sitemap |
| P5 Compression and transfer | PASS | Static assets gzip; sitemap 2.07 MB -> 154 KB; catalogue HTML 42 -> 19 KB; about 3x fewer database round trips |
| P6 Images and assets | PASS | Asset budgets met; no lazy image above the fold; catalogue lab LCP 592 -> 436 ms |
| P7 Web Vitals | NOT VERIFIED | Lab only: LCP 392-448 ms, CLS <= 0.026, TBT <= 64 ms, INP (approximation) <= 32 ms |
| P8 Integrations | PASS | Vendor documentation checked; Shkeeper polling stops after 3 failures, runs in the background |
| P9 Packages | PASS | Direct dependencies current; documented usage; deprecated PDO constant avoided |
| P10 Resources and concurrency | NOT VERIFIED | Sandbox: 20 req/s at p95 123 ms (before: 6.9 s), saturation at 60 req/s, clean recovery; slow downloads hold no PHP worker |
| P11 Reliability | PASS | Polling stop, sweep fixes, bounded queue retries; simulation recovery scenarios pass |
| P12 Build and deployment | PASS | Image 1.01 GB -> 330 MB; migrations under 3 s on the full dataset, indexes built concurrently |
| P13 Maintainability and tests | PASS | 314/314 tests; 19 performance regression tests (15 failed before the fixes) |
| P14 Benchmarks and budgets | BLOCKED | All provisional budgets met in two runs; budgets need approval, and production-like hardware is needed |

## Findings

**28 findings:**

| Severity | Count | Status |
|---|---|---|
| Critical | 0 | |
| High | 2 | Fixed |
| Medium | 12 | Fixed |
| Low | 10 | 7 fixed; 3 open within budget |
| Info | 4 | Decisions documented |

By category: database 9, network 4, runtime 3, correctness 2, reliability 2, code quality 2, capacity 2, build 1, resource limits 1, assets 1, integration 1.

Details: [performance-findings.md](performance-findings.md).

**The ones that mattered most:**

- **PERF-01 (High):** the reconciliation page took 18.6 s with 821 queries. Now 0.4 s and 11 queries.
- **PERF-02 (High):** "best selling" sorting summed all 250,000 order lines on every view of a public page. Now it reads a ranking refreshed every 10 minutes.
- **PERF-03:** every query cost three database round trips.
- **PERF-09:** the search ignored its trigram indexes until the planner cost was set for SSD storage.
- **PERF-05:** the seller filter was 28 KB of every catalogue page.
- **PERF-06:** the sitemap cost 0.9 s of CPU per hit.
- **Correctness:**
  - two scheduled sweeps crashed outside production once two cases were due, and could skip cases (PERF-11);
  - dashboard top lists were wrong for minor currencies (PERF-12).
- **Reliability:** Shkeeper polling could spend over half an hour on timeouts (PERF-14). Buffered downloads had no concurrency bound (PERF-19).

## Baseline and final benchmarks

p50 of 30 sequential requests, run 1 / run 2. Full tables: [performance-baselines.md](performance-baselines.md).

| Page | Before | After |
|---|---|---|
| Catalogue, best selling | 859 ms | 84 / 85 ms |
| Search | 207 ms | 70 / 79 ms |
| Product page | 210 ms | 76 / 71 ms |
| Sitemap | 891 ms, 2.07 MB | 36 / 39 ms, 154 KB |
| Seller dashboard | 203 ms | 102 / 108 ms |
| Seller live poll | 103 ms | 61 / 60 ms |
| Admin dashboard | 844 ms | 640 / 635 ms |
| Admin reconciliation | 18,585 ms | 386 / 382 ms |
| Pages without a code change | - | Within noise (up to 18-25% between runs) |

**Load** (staged k6, abort on p95 above 5 s; k6 on the same 4 cores):

| | p95 at 10 req/s | p95 at 20 req/s | Aborted in stage |
|---|---|---|---|
| Before | 0.8 s | 6.9 s | 40 req/s |
| After | 0.12 s | 0.12 s | 60 req/s (p95 at 40 req/s: 0.55 s) |

No request failed in either run.

## Database

**Query plans (before -> after):**

| Query | Before | After |
|---|---|---|
| Best selling | 986 ms | 0.3 ms |
| Related products | 101 ms | 0.2 ms |
| Catalogue newest page | 25-34 ms | 0.2 ms |
| Search | 59-64 ms | 1.5-3.7 ms |
| Seller's latest sales | 56 ms | 0.2 ms |
| Seller poll | 51 ms | 0.06 ms |
| Admin order poll | 27 ms | 0.13 ms |
| Dashboard and recovery-job periods | 35-89 ms | 3-47 ms |

**Round trips per page:** 3x fewer, for example catalogue 36 -> 11.

Details: [database-performance-audit.md](database-performance-audit.md).

## Images, bundle and transfer

| Item | Before | After |
|---|---|---|
| CSS and JavaScript | 17.9 KB and 20.5 KB (4.7 / 5.6 KB gzip) | Unchanged, within budgets |
| Catalogue HTML | 42 KB | 19 KB |
| Catalogue transfer, cold, mobile | 91.6 KB | 65.0 KB |
| Catalogue DOM nodes | 647 | 272 |
| Catalogue lab TBT | 127 ms | 64 ms |

The first product images now load eagerly. Public images are cacheable without cookies.

Details: [asset-optimization-report.md](asset-optimization-report.md).

## Documentation and integration checks

Official documentation was read for:

- the PHP PDO_PGSQL constants;
- PostgreSQL 15 planner settings, pg_trgm and CREATE INDEX;
- Laravel 13 queues, scheduling, the HTTP client and queries;
- nginx FastCGI, gzip and limit_conn;
- web.dev Web Vitals and LCP lazy loading;
- the Shkeeper README.

Details: [integration-best-practices.md](integration-best-practices.md).

## Resources and load

- **Slow downloads:** 25 slow clients held no PHP-FPM worker, because nginx buffers each download. Concurrent downloads per client are now capped at 6, since the buffers live in memory.
- **After the load:** the stack answered in 82-107 ms again, used 68 MB in the app container and left no database connections open.
- **Not done:** multi-hour soak tests.

## Functional and security regression

| Check | Result |
|---|---|
| PHPUnit | 314 / 314 (4,103 assertions) |
| Pint | Clean |
| PHPStan level 5 | 0 errors |
| ASCII check | Clean |
| Simulation, 101 scenarios on the candidate image | SIM_RESULT |
| Security gate on the candidate | SECURITY_RESULT |
| Security regression tests | Part of the PHPUnit suite, all passing |
| Performance gate (`scripts/performance-gate.sh`) | PASS for every check that runs without the stack (style, types, performance tests, assets). With the measurement files of this audit, the bench, Web Vitals and image checks also pass. |

## Checks not performed, and why

- **Production hardware, network and a remote database:** not available in this sandbox. The round-trip saving of PERF-03 is calculated for a remote database, not measured.
- **Real-user Web Vitals (75th percentile):** none exist before launch.
- **Load generator on a separate machine; multi-hour soak; multiple app instances:** not available.
- **A dist-based (production) image build:** the sandbox proxy refuses GitHub archives, so Composer installs from git. The CI job builds the real image and checks its size budget.

## Approved exceptions and residual risks

**No exception was approved.** The audit does not approve its own exceptions.

| Risk | Description | Severity | Proposed owner action |
|---|---|---|---|
| R-01 | Budgets are provisional (P14) | - | Approve or replace |
| R-02 | Capacity on production hardware unknown (P10); polling at about 0.1 req/s per visible signed-in tab (PERF-25) | - | Load test on staging; decide the poll interval before large audiences |
| R-03 | Database-backed cache, sessions and rate limits (PERF-23) | - | Move to Redis at several times the measured load |
| R-04 | Download buffers in memory are bounded per client address, not in total | Low | A tmpfs size limit or a CDN for downloads |
| R-05 | Admin dashboard, admin user search and audit log counts scale linearly (PERF-24, 26) | Low | None needed now; all within budget |
| R-06 | Queue worker timeout equals the virus scan timeout (PERF-27) | Low | Raise if real scans approach 60 s |

## To reach PASS

1. Approve or adjust `tests/performance/budgets.json`, and set its `status` to approved.
2. Run `tests/performance/` on staging hardware with k6 on another machine, against an agreed target workload. Then run `GATE_STRICT=1 GATE_BENCH=... GATE_WEBVITALS=... GATE_IMAGE=... scripts/performance-gate.sh`.
3. After launch, collect real-user Web Vitals (75th percentile) and compare them with the budgets.
4. Make the CI job `performance` a required check, together with `php`, `security` and `browser`.
