#!/usr/bin/env bash
# Final functional release gate (docs/functional). Runs the functional suites
# against the current revision and decides from their results only:
#
#   phpunit   PHPUnit with JUnit output (includes the FunctionalGateTest races)
#   browser   tests/browser (no-JS journeys, live updates, UX, accessibility), JSON report
#   sim       production image + simulation stack, phases 1-6 and 8-12 (SIM_RUNS runs)
#   load      load profiles (LOAD_PROFILES) with recovery and invariant checks
#   large     year-scale dataset and input limits (tests/functional/large_data.py)
#   matrix    tests/functional/matrix.py --check: exit 1 unless every gate passes
#
#   scripts/functional-gate.sh                      all stages
#   FG_STAGES="phpunit matrix" scripts/functional-gate.sh
#
# Stages that cannot run (no Docker, no k6) leave their scenarios NOT VERIFIED,
# which blocks the release; nothing is skipped silently. Destructive stages
# (sim, load, large) only ever target the disposable simulation stack started
# here (https://localhost:8443); never point them at a real shop.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
OUT=${OUT:-"$ROOT/storage/functional-gate"}
mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)
STAGES=${FG_STAGES:-"phpunit browser sim load large matrix"}
SIM_RUNS=${SIM_RUNS:-1}
LOAD_PROFILES=${LOAD_PROFILES:-"baseline contention load spike stress soak"}
K6=${K6:-k6}
REVISION=$(git rev-parse --short HEAD)
STATUS=0
has() { case " $STAGES " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }
note() { echo "[functional-gate] $*" | tee -a "$OUT/gate.log"; }

note "revision $REVISION, stages: $STAGES"
if has phpunit; then
    php artisan test --log-junit "$OUT/phpunit.xml" > "$OUT/phpunit.log" 2>&1 || { note "phpunit: FAILED"; STATUS=1; }
fi
if has browser; then
    (PLAYWRIGHT_JSON_OUTPUT_NAME="$OUT/browser.json" ./tests/browser/run.sh --reporter=json,list > "$OUT/browser.log" 2>&1) || { note "browser: FAILED"; STATUS=1; }
fi
if has sim || has load || has large; then
    SIM_BACKEND=compose sh tests/simulation/run.sh up > "$OUT/sim-up.log" 2>&1 || { note "simulation stack did not start"; STATUS=1; }
    docker images digital-goods-shop:latest --format '{{.ID}}' > "$OUT/image-id.txt" 2>/dev/null
fi
if has sim; then
    before=$(ls tests/simulation/reports 2>/dev/null)
    SIM_BACKEND=compose sh tests/simulation/run.sh test --runs "$SIM_RUNS" > "$OUT/sim.log" 2>&1 || { note "simulation: FAILED"; STATUS=1; }
    for d in $(ls tests/simulation/reports); do
        case " $before " in *" $d "*) ;; *) [ -d "tests/simulation/reports/$d/run-1" ] && echo "tests/simulation/reports/$d" >> "$OUT/sim-dirs.txt" ;; esac
    done
fi
if has load; then
    OUT="$OUT/load" K6="$K6" SIM_BACKEND=compose sh tests/functional/load/run.sh $LOAD_PROFILES > "$OUT/load.log" 2>&1 || { note "load: FAILED"; STATUS=1; }
fi
if has large; then
    { SIM_BACKEND=compose sh tests/simulation/run.sh reset &&
      docker exec -i shopsim-db-1 psql -U shop -d shop -v ON_ERROR_STOP=1 < tests/performance/scale-data.sql &&
      python3 -I tests/functional/large_data.py "$OUT/large-data.json"; } > "$OUT/large.log" 2>&1 || { note "large data: FAILED"; STATUS=1; }
fi
if has sim || has load || has large; then
    SIM_BACKEND=compose sh tests/simulation/run.sh down > /dev/null 2>&1 || true
fi
if has matrix; then
    SIM_DIRS=$(cat "$OUT/sim-dirs.txt" 2>/dev/null || true)
    python3 -I tests/functional/matrix.py --revision "$REVISION" --artifact "image $(cat "$OUT/image-id.txt" 2>/dev/null)" \
        ${SIM_DIRS:+--sim $SIM_DIRS} --phpunit "$OUT/phpunit.xml" --browser "$OUT/browser.json" --load "$OUT/load" \
        --large "$OUT/large-data.json" --check | tee "$OUT/matrix.log" || STATUS=1
fi
note "done: exit $STATUS"
exit $STATUS
