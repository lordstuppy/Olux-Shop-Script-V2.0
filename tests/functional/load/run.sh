#!/usr/bin/env sh
# Load profiles of the final functional gate (docs/functional/load-stress-test-report.md)
# against the simulation stack (tests/simulation/run.sh up). Never against a real shop.
#
#   K6=/path/to/k6 tests/functional/load/run.sh baseline load stress spike soak contention
#
# Resets the database and prepares the data once (NO_RESET=1 keeps both), then
# runs each profile with a resource sampler that stops k6 when the host runs
# short, and checks recovery and the data invariants after each. Results:
# tests/simulation/reports/functional-load-<time>/<profile>/result.json.
set -eu
export PYTHONDONTWRITEBYTECODE=1
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../../.." && pwd)
SIM="$ROOT/tests/simulation"
K6=${K6:-k6}
OUT=${OUT:-"$SIM/reports/functional-load-$(date -u +%Y%m%d-%H%M%S)"}
mkdir -p "$OUT"
if [ "${NO_RESET:-}" != 1 ]; then
    sh "$SIM/run.sh" reset
    python3 -I "$HERE/prepare.py" "$OUT/data.json"
fi
FAILED=0
for PROFILE in "$@"; do
    D="$OUT/$PROFILE"
    mkdir -p "$D"
    rm -f "$D/stop"
    python3 -I "$HERE/after.py" --before "$D"
    python3 -I "$HERE/sample.py" "$D/samples.jsonl" "$D/stop" &
    SAMPLER=$!
    echo "== $PROFILE"
    set +e
    # Each virtual user connects from its own loopback address, so per-address limits apply per person.
    $K6 run --local-ips=127.0.1.1-127.0.1.250 -q -e PROFILE="$PROFILE" -e DATA="$OUT/data.json" -e SUMMARY="$D/k6-summary.json" \
        "$HERE/profiles.js" > "$D/k6.log" 2>&1
    echo "k6 exit $?" >> "$D/k6.log"
    touch "$D/stop"
    wait $SAMPLER
    python3 -I "$HERE/after.py" "$PROFILE" "$D" "$OUT/data.json" || FAILED=1
    set -e
done
exit $FAILED
