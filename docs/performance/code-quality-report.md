# Code Quality Report

**Scope:**

- `app/`: 206 PHP files, 14,555 lines.
- `routes/`, `database/`, `config/`.
- Blade views and `public/js/enhance.js` (reviewed by reading).

## Method

1. **Read the code**, one area at a time:
   - controllers, services, models and jobs;
   - scheduled commands;
   - views and the layout view composer;
   - JavaScript.

   The review was a structured reading pass; the candidates are in [performance-findings.md](performance-findings.md).
2. **Static analysis:**
   - PHPStan 2.3.1 with Larastan 3.13.0, new as a dev dependency (`phpstan.neon`);
   - Pint (code style);
   - `scripts/check-ascii.sh`.
3. **Tests:**
   - PHPUnit, 314 tests and 4,103 assertions at the candidate;
   - the browser tests (unchanged);
   - the 101-scenario simulation on the production image.

## Static analysis

| Step | Findings at level 5 |
|---|---|
| First run | 392 |
| Larastan reads the models' `casts()` methods (`parseModelCastsMethod: true`) | 138 |
| Generic return types on all 57 model relations (`@return BelongsTo<Product, $this>`) | 28 |
| Remaining fixes (below) | **0** |

**Why the first two steps removed so much.** Without them Larastan typed every enum attribute as a string and every relation as `Model`. That produced hundreds of "always false" and "undefined property" reports.

These were configuration and documentation gaps, not defects:

- the test suite exercises the same comparisons;
- the N+1 guard is active in tests.

**Real issues among the remaining 28 (all fixed):**

| Issue | Risk | Fix |
|---|---|---|
| `Notifier::send()` calls `$mail->envelope()`, but the `InAppNotice` contract did not require it | A future in-app mail without `envelope()` would fail at run time | The contract requires `envelope(): Envelope` |
| `JsonFormatterTap` called `setFormatter()` on every handler | A handler without a formatter (for example a stack of other handlers) would crash logging setup | Only `FormattableHandlerInterface` handlers are formatted |
| `SimulationSeeder` read `env('SHOP_SIMULATION')` | `env()` is empty once configuration is cached | It reads `config('shop.simulation')` |
| Three `match` expressions with no failure branch (dispute action, export type, email template) | Inputs are validated, so not reachable today | Each now fails explicitly with an exception that names the value |
| `ReviewService::summary()` read aggregate columns through a model | Fragile | Reads them through the base query builder |
| Return types | Too narrow or too wide | `CarbonInterface` for date helpers, `stdClass` rows for reports |
| `?->` inside `??` | Redundant | Simplified in 10 places |

**Kept as a scoped exception, with the reason in `phpstan.neon`:** the seeders call `$this->command?->`. Seeders also run outside Artisan, where Laravel leaves `$command` unset although its PHPDoc says otherwise.

**Level 6 (not gated): 56 more findings.** These are typing debt, not defects:

| Kind | Count |
|---|---|
| Missing iterable value types (`array` without shape) | 35 |
| Missing generics | 11 |
| Template types | 6 |
| Missing parameter and return types | 4 |

By location:

| Location | Count |
|---|---|
| `app/Services` | 30 |
| `app/Http` | 17 |
| `app/Support` | 5 |
| `app/Models` | 4 |

**Gate:** level 5 with 0 errors (`scripts/performance-gate.sh`, check `quality-types`, also run in CI). Raising it to 6 is a follow-up that needs no behaviour change.

## Architecture and maintainability

**Layering:**

- Controllers validate and delegate.
- Business rules live in services: `OrderService`, `PaymentService`, `RefundService`, `PayoutService`, `DisputeService` and others.
- Money is integer minor units through `Money` and brick/math.
- Statuses are enums.

This structure is consistent; nothing needed restructuring for performance.

**Largest files:**

| File | Lines |
|---|---|
| `PaymentService` | 412 |
| `UserService` | 390 |
| `ShkeeperPayoutService` | 370 |
| `Seller/ProductController` | 354 |
| `DisputeService` | 334 |

Each covers one domain. No function was found that would read better split for its own sake; the audit did not rewrite code for style.

**Duplication** (open, Low):

- The list of "paid" order states is written out in about ten queries, in two variants (with and without `refunded`). `OrderStatus::isPaidState()` covers PHP, but there is no SQL-side helper. A shared constant would prevent one query drifting from the others.
- The user filters of `Admin\UserController` and `BulkController` are duplicated.

Recorded, not changed: they behave correctly today and no test fails.

**Business logic in `routes/console.php` closures** (stuck-order recovery, staff digest): works and is tested (`StuckOrderRecoveryTest`), but service classes would be easier to test in isolation. Open, Low.

**Dead code removed:**

- `AnalyticsService::attention()`
- duplicate `fresh()` calls (PERF-21)

**Concurrency and resource lifecycle:**

- Row locks: checkout, payouts, disputes and key import.
- Idempotency keys on orders; webhook events deduplicated by key.
- Jobs with bounded tries and backoff.
- No unbounded in-memory collections on request paths. The sitemap is capped at 45,000 URLs; lists are paginated; exports are streamed in keyset chunks.

**Timeouts:**

| Resource | Timeout |
|---|---|
| HTTP to Shkeeper | 10 s, 5 s connect |
| Database connect | 5 s |
| ClamAV | 60 s |
| nginx FastCGI read | 60 s |

## Tests

| Suite | Result at the candidate |
|---|---|
| PHPUnit | 314 / 314, 4,103 assertions |
| Performance regression tests (`tests/Feature/PerformanceAuditTest.php` plus one dispute test and one image test) | 19 tests, including query-count guards for 18 pages |
| Simulation, 101 scenarios on the production image | Recorded in the summary |

**Regression tests before the fixes:** 15 of 19 failed on the pre-fix code. The other 4 are guards for pages that had no N+1. Details: [evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt).

**Test quality notes:**

- The query-count tests compare two data sizes, not a fixed number, so they catch N+1 without breaking on unrelated query changes.
- The earning-formula test compares SQL with PHP on 300 generated cases, including all rounding half-way points.
- External services are faked (`Http::preventStrayRequests()`). The simulation runs the real HTTP paths against a Shkeeper mock with failure modes.

**Not covered by automated tests:** latency and Web Vitals budgets. They need the simulation stack and are run by `tests/performance/` and the gate's optional checks, not in CI.
