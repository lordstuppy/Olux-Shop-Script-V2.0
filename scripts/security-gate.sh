#!/usr/bin/env bash
# Security release gate: runs every automated security check against the
# current checkout and fails (exit 1) when any mandatory check fails or cannot
# run. Results go to $OUT/security-gate-results.json (machine-readable) and
# the console. Used by the CI job "security"; runs the same locally.
#
#   scripts/security-gate.sh                 # all checks that need no running app
#   GATE_IMAGE=digital-goods-shop:latest scripts/security-gate.sh   # plus the image scan
#
# Needs: PHP + Composer (vendor installed), Node/npm, Docker, Python 3 with
# semgrep (pip install semgrep==$SEMGREP_VERSION). Scanner images are pinned.
# A check that cannot run is a failure, never a pass ("no evidence, no pass").
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
OUT=${OUT:-"$ROOT/storage/security-gate"}
mkdir -p "$OUT"

SEMGREP_VERSION=${SEMGREP_VERSION:-1.180.0}
GITLEAKS_IMAGE=${GITLEAKS_IMAGE:-zricethezav/gitleaks:v8.21.2}
TRIVY_IMAGE=${TRIVY_IMAGE:-aquasec/trivy:0.57.1}
OSV_IMAGE=${OSV_IMAGE:-ghcr.io/google/osv-scanner:v1.9.1}
# Extra trust anchors for egress proxies (optional), mounted into the scanner containers.
CA_BUNDLE=${GATE_CA_BUNDLE:-}

REVISION=$(git rev-parse HEAD 2>/dev/null || echo unknown)
DIRTY=$(git status --porcelain 2>/dev/null | grep -q . && echo true || echo false)
RESULTS=()
FAILED=0

ca_args() {
    if [ -n "$CA_BUNDLE" ]; then
        echo "-v $CA_BUNDLE:/etc/ssl/certs/ca-certificates.crt:ro -e SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt"
    fi
}

proxy_args() {
    local a=""
    [ -n "${HTTPS_PROXY:-}" ] && a="$a -e HTTPS_PROXY=$HTTPS_PROXY --network host"
    [ -n "${NO_PROXY:-}" ] && a="$a -e NO_PROXY=$NO_PROXY"
    echo "$a"
}

# run_check <id> <title> <command...>: runs the command, keeps its output in
# $OUT/<id>.log and records PASS (exit 0) or FAIL (anything else).
run_check() {
    local id=$1 title=$2
    shift 2
    local started finished rc status
    started=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    echo "==> [$id] $title"
    "$@" > "$OUT/$id.log" 2>&1
    rc=$?
    finished=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    if [ $rc -eq 0 ]; then status=PASS; else status=FAIL; FAILED=1; fi
    echo "    $status (exit $rc, log: $OUT/$id.log)"
    [ $rc -ne 0 ] && tail -n 25 "$OUT/$id.log" | sed 's/^/    | /'
    RESULTS+=("{\"id\":\"$id\",\"title\":\"$title\",\"status\":\"$status\",\"exit_code\":$rc,\"started\":\"$started\",\"finished\":\"$finished\",\"log\":\"$id.log\"}")
}

check_composer_audit() {
    composer audit --locked --no-interaction --abandoned=fail --format=plain
}

check_npm_audit() {
    (cd tests/browser && npm audit --audit-level=high)
}

check_osv() {
    # shellcheck disable=SC2046
    docker run --rm $(proxy_args) $(ca_args) -v "$ROOT:/repo:ro" "$OSV_IMAGE" \
        --lockfile /repo/composer.lock --lockfile /repo/tests/browser/package-lock.json
}

check_secrets() {
    # Full git history, with the allowlist of public test-only values in .gitleaks.toml.
    docker run --rm -v "$ROOT:/repo:ro" "$GITLEAKS_IMAGE" git /repo --config /repo/.gitleaks.toml --redact --exit-code 1 --no-banner
}

check_env_not_tracked() {
    if git ls-files --error-unmatch .env >/dev/null 2>&1; then
        echo ".env is tracked by git"
        return 1
    fi
    git ls-files | grep -E '(^|/)\.env(\..+)?$' | grep -v '\.env\.example$' && { echo "environment files are tracked"; return 1; }
    echo ".env files are not tracked"
}

check_semgrep() {
    command -v semgrep >/dev/null || { echo "semgrep $SEMGREP_VERSION is not installed"; return 1; }
    SEMGREP_SEND_METRICS=off semgrep scan --metrics=off --error --disable-version-check \
        --config .semgrep/shop-rules.yml \
        --config p/php --config p/security-audit --config p/owasp-top-ten --config p/xss --config p/secrets \
        --config p/javascript --config p/dockerfile --config p/nginx --config p/github-actions \
        --exclude vendor --exclude node_modules --exclude storage \
        --exclude tests/simulation/work --exclude tests/simulation/reports \
        --json --output "$OUT/semgrep.json" .
}

check_iac() {
    # Dockerfile, compose files and nginx: misconfigurations rated HIGH or CRITICAL fail.
    # shellcheck disable=SC2046
    docker run --rm $(proxy_args) $(ca_args) -v "$ROOT:/repo:ro" "$TRIVY_IMAGE" config \
        --severity HIGH,CRITICAL --exit-code 1 --skip-dirs /repo/vendor --skip-dirs /repo/tests/simulation/work /repo
}

check_image() {
    [ -n "${GATE_IMAGE:-}" ] || { echo "GATE_IMAGE not set"; return 1; }
    # Fixable HIGH/CRITICAL vulnerabilities in OS packages and bundled binaries fail.
    # shellcheck disable=SC2046
    docker run --rm $(proxy_args) $(ca_args) -v /var/run/docker.sock:/var/run/docker.sock "$TRIVY_IMAGE" image \
        --severity HIGH,CRITICAL --ignore-unfixed --exit-code 1 "$GATE_IMAGE"
}

check_security_tests() {
    php artisan test --filter 'SecurityAuditTest|SecurityTest|test_sec0|AccountSecurityTest|OrderAccessTest|StaffRolesTest|WebhookTest'
}

run_check deps-composer "Composer advisories and abandoned packages" check_composer_audit
run_check deps-npm "npm advisories (high and critical)" check_npm_audit
run_check deps-osv "OSV database scan of all lockfiles" check_osv
run_check secrets-history "Secret scan of the full git history (gitleaks)" check_secrets
run_check secrets-env "No environment files tracked" check_env_not_tracked
run_check sast "Static analysis: project rules and Semgrep registry rulesets" check_semgrep
run_check iac "Container and infrastructure configuration (Trivy config)" check_iac
if [ -n "${GATE_IMAGE:-}" ]; then
    run_check image "Runtime image vulnerabilities (Trivy image)" check_image
fi
if [ "${GATE_TESTS:-0}" = "1" ]; then
    run_check security-tests "Security regression tests (PHPUnit)" check_security_tests
fi

{
    echo "{"
    echo "  \"revision\": \"$REVISION\","
    echo "  \"working_tree_dirty\": $DIRTY,"
    echo "  \"generated\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo "  \"tools\": {\"semgrep\": \"$SEMGREP_VERSION\", \"gitleaks\": \"$GITLEAKS_IMAGE\", \"trivy\": \"$TRIVY_IMAGE\", \"osv\": \"$OSV_IMAGE\"},"
    echo "  \"status\": \"$([ $FAILED -eq 0 ] && echo PASS || echo FAIL)\","
    echo "  \"checks\": ["
    for i in "${!RESULTS[@]}"; do
        sep=","; [ "$i" -eq $((${#RESULTS[@]} - 1)) ] && sep=""
        echo "    ${RESULTS[$i]}$sep"
    done
    echo "  ]"
    echo "}"
} > "$OUT/security-gate-results.json"

echo
echo "Security gate: $([ $FAILED -eq 0 ] && echo PASS || echo FAIL) (revision $REVISION, results: $OUT/security-gate-results.json)"
exit $FAILED
