# Performance tooling

Measurements behind docs/performance. Everything here runs against the
disposable simulation stack (docs/SIMULATION.md), never a real shop.

| File | What it does |
|---|---|
| `scale-data.sql` | Grows a freshly reset simulation database to the provisional first-year volume (20,000 products, 200,000 orders, 1,000,000 audit entries, ...) |
| `bench.py` | Per page: SQL statements and round trips (from the PostgreSQL log), then p50/p95/p99 of 30 sequential requests |
| `explain.sql` | `EXPLAIN (ANALYZE, BUFFERS)` of the hot and periodic queries |
| `seed-images.py` | Uploads photo-like images to the newest products through the seller form |
| `webvitals.mjs` | Lab LCP, CLS, TBT, INP approximation and transfer sizes in Chromium (mobile profile) |
| `load-prepare.py`, `staged-load.js` | Staged read load (k6) at 10-80 requests/s, aborts on its own |
| `slow-downloads.py` | Do slow download clients hold PHP-FPM workers? |
| `budgets.json`, `check-budgets.py` | Provisional budgets and the checker used by `scripts/performance-gate.sh` |

Typical run (about 30 minutes on 4 cores):

```sh
SIM_BUILD_CA=/path/to/proxy-ca.crt tests/simulation/run.sh up   # or without SIM_BUILD_CA
tests/simulation/run.sh reset
docker exec -i shopsim-db-1 psql -U shop -d shop -v ON_ERROR_STOP=1 < tests/performance/scale-data.sql
docker exec -i shopsim-db-1 psql -U shop -d shop -q < tests/performance/explain.sql > plans.txt
python3 -I tests/performance/bench.py bench.json --runs 30
python3 -I tests/performance/seed-images.py 24
CHROMIUM=/path/to/chrome node tests/performance/webvitals.mjs webvitals.json 5
python3 -I tests/performance/load-prepare.py load-data.json
k6 run --local-ips=127.0.1.1-127.0.1.250 -e DATA=load-data.json -e SUMMARY=staged.json tests/performance/staged-load.js
GATE_STRICT=1 GATE_BENCH=bench.json GATE_WEBVITALS=webvitals.json \
  GATE_IMAGE="digital-goods-shop:latest digital-goods-shop-web:latest" scripts/performance-gate.sh
```

Compare runs only on the same hardware and dataset: two runs of the same
code differed by up to 18-25% per page in the shared sandbox.
