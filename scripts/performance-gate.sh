#!/usr/bin/env bash
# Performance and code quality release gate (docs/performance). Runs the
# checks that need no running stack, plus the measurement checks whose input
# files are given, and fails (exit 1) when any check fails or cannot run.
# Results: $OUT/performance-gate-results.json and the console. Used by the CI
# job "performance"; runs the same locally.
#
#   scripts/performance-gate.sh
#   GATE_BENCH=bench.json GATE_WEBVITALS=webvitals.json scripts/performance-gate.sh
#   GATE_IMAGE="digital-goods-shop:latest digital-goods-shop-web:latest" scripts/performance-gate.sh
#
# The measurement inputs come from the simulation stack with the performance
# dataset (tests/performance/README.md); CI has no such stack, so there those
# checks are NOT VERIFIED (never passed) and the overall status is INCOMPLETE.
# For a release decision run with GATE_STRICT=1: then anything not verified
# fails the gate.
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
OUT=${OUT:-"$ROOT/storage/performance-gate"}
mkdir -p "$OUT"
REVISION=$(git rev-parse HEAD 2>/dev/null || echo unknown)
DIRTY=$(git status --porcelain 2>/dev/null | grep -q . && echo true || echo false)
RESULTS=()
FAILED=0
UNVERIFIED=0

# run_check <id> <title> <command...>: PASS on exit 0, FAIL otherwise; output in $OUT/<id>.log.
run_check() {
    local id=$1 title=$2
    shift 2
    local started rc status
    started=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    echo "==> [$id] $title"
    "$@" > "$OUT/$id.log" 2>&1
    rc=$?
    if [ $rc -eq 0 ]; then status=PASS; else status=FAIL; FAILED=1; fi
    echo "    $status (exit $rc, log: $OUT/$id.log)"
    [ $rc -ne 0 ] && tail -n 25 "$OUT/$id.log" | sed 's/^/    | /'
    RESULTS+=("{\"id\":\"$id\",\"title\":\"$title\",\"status\":\"$status\",\"exit_code\":$rc,\"started\":\"$started\",\"log\":\"$id.log\"}")
}

not_run() {
    UNVERIFIED=1
    [ "${GATE_STRICT:-0}" = "1" ] && FAILED=1
    echo "==> [$1] $2: NOT VERIFIED ($3)"
    RESULTS+=("{\"id\":\"$1\",\"title\":\"$2\",\"status\":\"NOT VERIFIED\",\"reason\":\"$3\"}")
}

check_style() { vendor/bin/pint --test; }
check_types() { vendor/bin/phpstan analyse --no-progress --memory-limit=1G; }
check_perf_tests() { php artisan test tests/Feature/PerformanceAuditTest.php; }
check_assets() { python3 -I tests/performance/check-budgets.py assets; }
check_bench() { python3 -I tests/performance/check-budgets.py bench "$GATE_BENCH"; }
check_webvitals() { python3 -I tests/performance/check-budgets.py webvitals "$GATE_WEBVITALS"; }
check_image() {
    # shellcheck disable=SC2086
    set -- $GATE_IMAGE
    python3 -I tests/performance/check-budgets.py image "$1" "$2"
}

run_check quality-style "Code style (Pint)" check_style
run_check quality-types "Static analysis and types (PHPStan with Larastan)" check_types
run_check perf-tests "Query budgets and performance regression tests (PHPUnit)" check_perf_tests
run_check assets "CSS and JavaScript size budgets" check_assets
if [ -n "${GATE_BENCH:-}" ]; then
    run_check bench "Page latency and query budgets (tests/performance/bench.py)" check_bench
else
    not_run bench "Page latency and query budgets" "GATE_BENCH not given: needs the simulation stack with the performance dataset"
fi
if [ -n "${GATE_WEBVITALS:-}" ]; then
    run_check webvitals "Lab Core Web Vitals (tests/performance/webvitals.mjs)" check_webvitals
else
    not_run webvitals "Lab Core Web Vitals" "GATE_WEBVITALS not given: needs the simulation stack and Chromium"
fi
if [ -n "${GATE_IMAGE:-}" ]; then
    run_check image "Runtime image size budgets" check_image
else
    not_run image "Runtime image size budgets" "GATE_IMAGE not given"
fi

if [ $FAILED -ne 0 ]; then STATUS=FAIL; elif [ $UNVERIFIED -ne 0 ]; then STATUS=INCOMPLETE; else STATUS=PASS; fi

{
    echo "{"
    echo "  \"revision\": \"$REVISION\","
    echo "  \"working_tree_dirty\": $DIRTY,"
    echo "  \"generated\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo "  \"strict\": $([ "${GATE_STRICT:-0}" = "1" ] && echo true || echo false),"
    echo "  \"status\": \"$STATUS\","
    echo "  \"checks\": ["
    for i in "${!RESULTS[@]}"; do
        sep=","; [ "$i" -eq $((${#RESULTS[@]} - 1)) ] && sep=""
        echo "    ${RESULTS[$i]}$sep"
    done
    echo "  ]"
    echo "}"
} > "$OUT/performance-gate-results.json"

echo
echo "Performance gate: $STATUS (revision $REVISION, results: $OUT/performance-gate-results.json)"
exit $FAILED
