#!/usr/bin/env sh
# Phase 7: load test on a freshly reset stack. Called by tests/simulation/run.sh load.
#   K6=/path/to/k6 (default: k6 on PATH), DURATION=2m, NO_RESET=1 to keep the data.
set -eu
export PYTHONDONTWRITEBYTECODE=1
HERE=$(cd "$(dirname "$0")" && pwd)
SIM=$(dirname "$HERE")
K6=${K6:-k6}
OUT="$SIM/reports/load-$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$OUT"
[ "${NO_RESET:-}" = 1 ] || sh "$SIM/run.sh" reset
python3 -I "$HERE/prepare.py" "$OUT/data.json"
python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$OUT/data.json"
SINCE=$(date -u '+%Y-%m-%d %H:%M:%S')
# Host backend: each virtual user connects from its own loopback address, so
# per-address rate limits apply per person as they would in production.
IPS=""
[ "${SIM_BACKEND:-compose}" = host ] && IPS="--local-ips=127.0.1.1-127.0.1.250"
set +e
$K6 run $IPS -q -e DATA="$OUT/data.json" -e SUMMARY="$OUT/k6-summary.json" -e DURATION="${DURATION:-2m}" "$HERE/load.js" > "$OUT/k6.log" 2>&1
K6_EXIT=$?
set -e
echo "k6 exit $K6_EXIT (log: $OUT/k6.log)"
python3 -I "$HERE/analyze.py" "$OUT/k6-summary.json" "$OUT/data.json" "$OUT/report.md" "$SINCE"
