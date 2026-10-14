# Load, Stress, Spike and Soak Test Report

**Stack:** the production image of the candidate (app `c173f14e26d2`, built from `40594c9`) on the disposable simulation stack. It runs PHP-FPM with 20 workers, nginx, a TLS front end, the queue worker, the scheduler, PostgreSQL 15, Mailpit and the Shkeeper mock. Its data is the simulation seed: 200 products, 50 buyers and 10 sellers, plus 100 pending orders and 40 funded buyers for the profiles.

**Not equivalent to production:**

- **Shared machine.** k6, nginx, PHP and PostgreSQL share one 4-core machine with 15 GB of memory. The latency figures are therefore pessimistic and the throughput ceiling is this sandbox's, not production's.
- **Provisional budgets.** The budgets are provisional (docs/performance), and no target workload or service-level objective has been approved.

Gate F10 is therefore BLOCKED (G-07). The data integrity under load is what these runs certify.

**Tools:** `tests/functional/load/` (k6 0.54.0).

- `profiles.js`: the workloads.
- `sample.py`: CPU and memory per container, database connections, queue depth and host load every 5 s.
- `after.py`: recovery and data checks after each profile.

## Safety limits

| Limit | Value |
|---|---|
| Target | `https://localhost:8443` only, the stack started by `tests/simulation/run.sh up` |
| Abort thresholds (k6 `abortOnFail`, evaluated after 30 s) | more than 5 % failed requests, or p95 above 10 s |
| Host ceiling (sampler stops k6) | less than 1 GiB available memory, or less than 400 MB free disk |
| Duration | at most 20 minutes per profile |
| Clean-up | the database is reset before the profile set |

## Workloads

**Browser** (every profile with browsers): home page, a category listing (random sort), a 30 % chance of a search, and a product page. With a 30 % chance it also adds to the cart and opens the cart. Think time is 1-3 s. The first 30 browsers sign in.

| Profile | Composition |
|---|---|
| baseline | 5 browsers and 1 staff member paging the product list, 2 minutes |
| load (target workload of the earlier phase 7) | Ramp to 50 browsers (30 signed in). Plus 20 buyers checking out from three sellers each, paid through the gateway mock; signed payment callbacks at 100 a minute for 100 orders (20 of them duplicates); and staff paging. 3 minutes 45 seconds. |
| contention | 40 buyers buying one product limited to 10 units with their balance, all released at once. Plus 120 callbacks for the same 5 orders from 30 parallel senders. |
| spike | 10 browsers; then 150 within 10 s, held for a minute; back to 10 for 2 minutes |
| stress | Browsers in steps of 25, 50, 100, 150 and 200 (20 s ramp, 60 s hold each) |
| stress-high | Continues with steps of 200, 250, 300 and 400 |
| soak | 30 browsers; a three-seller checkout every 20 s; callbacks at 6 a minute; staff paging; 20 minutes |

## Results

### Traffic and latency

All requests, latency in ms.

| Profile | Peak users | Seconds | Requests | Requests/s | p50 | p90 | p95 | p99 | max | Errors |
|---|---|---|---|---|---|---|---|---|---|---|
| baseline | 6 | 122 | 1,302 | 10.6 | 40 | 54 | 60 | 83 | 777 | 0.00 % |
| load | 76 | 226 | 17,379 | 76.8 | 92 | 243 | 383 | 950 | 3,711 | 0.00 % |
| contention | 70 | 9 | 484 | 52.1 | 745 | 1,669 | 2,679 | 3,575 | 4,521 | 0.00 % |
| spike | 150 | 262 | 10,900 | 41.5 | 977 | 1,184 | 1,233 | 1,389 | 1,827 | 0.00 % |
| stress | 200 | 422 | 35,803 | 84.8 | 629 | 1,514 | 1,589 | 1,698 | 2,082 | 0.00 % |
| stress-high | 400 | 344 | 30,117 | 87.6 | 2,492 | 4,108 | 4,233 | 4,618 | 4,933 | 0.00 % |
| soak | 34 | 1,203 | 69,661 | 57.9 | 52 | 89 | 106 | 154 | 3,013 | 0.00 % |

**Totals:** 165,646 requests, 0 failed. No abort threshold was crossed and the host ceiling was never reached.

### Resources, queue and recovery

CPU in % of one core (average / peak); memory peaks in MiB.

| Profile | App CPU | App memory | DB CPU | DB memory | Worker memory | DB connections, peak | Queued jobs, peak | Host load, peak | Queue drained after | Catalogue median before -> after (ms) | Failed jobs | Invariant violations |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| baseline | 30 / 214 | 59 | 20 / 43 | 75 | 39 | 5 | 0 | 0.9 | 0 s | 44 -> 36 | 0 | 0 |
| load | 154 / 313 | 762 | 136 / 170 | 109 | 129 | 20 | 90 | 13.2 | 0 s | 44 -> 40 | 0 | 0 |
| contention | 250 / 345 | 338 | 95 / 152 | 98 | 61 | 18 | 8 | 1.0 | 2 s | 40 -> 47 | 0 | 0 |
| spike | 87 / 283 | 395 | 76 / 177 | 114 | 130 | 21 | 3 | 18.6 | 0 s | 41 -> 42 | 0 | 0 |
| stress | 161 / 193 | 130 | 149 / 176 | 123 | 130 | 22 | 1 | 26.2 | 0 s | 41 -> 42 | 0 | 0 |
| stress-high | 183 / 279 | 339 | 168 / 179 | 148 | 118 | 22 | 2 | 27.0 | 0 s | 48 -> 47 | 0 | 0 |
| soak | 118 / 309 | 513 | 108 / 155 | 109 | 133 | 12 | 8 | 19.5 | 0 s | 38 -> 41 | 0 | 0 |

**After each profile:**

- The data invariants held (transaction-integrity-report.md) and `shop:reconcile-payouts` found no difference.
- No job failed.
- The catalogue answered as fast as before the load.

### Business outcomes under load

| Profile | Outcome |
|---|---|
| load | 20 of 20 multi-seller checkouts placed and paid through the gateway. 121 callbacks accepted; every order paid exactly once (invariant I8). |
| contention | Exactly 10 of the 10 units sold and stock 0. Of the other 30 buyers, 26 got "just sold out" and 4 no order at all, because the product was already off sale when they added it to the cart; none got an error. The 5 orders that received 120 parallel callbacks have exactly one confirmed charge each. |
| soak | 58 checkouts paid, 115 callbacks, 0 failed jobs |

## Interpretation

### Operating envelope on this machine

- **Saturation point:** throughput levels off at about 85-88 requests per second. That is 200 browsers in the stress profile; 400 browsers in stress-high added no throughput.
- **Beyond it, requests queue:** latency rose (p95 1.6 s at 200 browsers, 4.2 s at 400) while not a single request failed.
- **The 10 s abort threshold was not reached at 400 browsers.** No failure point was found within the range tested.
- **Bottleneck:** CPU. Under load PHP-FPM averaged 1.5-1.8 cores and PostgreSQL 1.1-1.7. Together with k6 on the same machine, the 4 cores were saturated (host load 13-27).

### Spike

- **During the spike:** 150 browsers arriving within 10 s were served without errors, at a median of about 1 s.
- **After it:** latency returned to the baseline once the spike ended.

### Soak

Over 20 minutes there was no sign of a leak:

- **Memory:** app memory averaged 79 MiB in the first quarter and 71 MiB in the last; the worker 115 and 117 MiB; PostgreSQL 87 and 94 MiB.
- **Database connections:** stayed at 3-12.
- **Queue:** never backed up.

### Comparison with the performance audit

The performance audit saw saturation at about 60 requests per second with a read-only constant-arrival workload on the earlier code (docs/performance). Here the workload has think time and a third of it is cart, checkout and callbacks, so the numbers are not directly comparable.

## Large data

Gate F11 ran on the year-scale dataset (`tests/performance/scale-data.sql`). The results are in [functional-test-summary.md](functional-test-summary.md#large-data) and the scenario matrix (category C06).

## Not verified

- **G-07:** the target workload on production-like hardware against approved service-level objectives, with the load generator on a separate machine.
- **G-08:** soaks over many hours. A 20-minute soak cannot show slow leaks over a day; the queue worker restarts every hour by design.
- **Per-page percentiles:** not reported for these runs; k6 reports a page's percentiles only when it has a threshold, which has since been added to `profiles.js`.
- **Two app instances behind a load balancer:** G-06.
