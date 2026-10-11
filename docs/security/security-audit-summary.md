# Security audit summary

**Decision: BLOCKED - DO NOT SHIP** (machine-readable: [security-gates.json](security-gates.json))

No critical or high finding is open. All 39 findings except one low were fixed
and retested. Release stays blocked because several mandatory gates cannot
pass from the repository alone: the production TLS, secret storage, database
role and branch protection are deployment-owned and unverified, two
dependency exceptions need the security owner's approval, and authenticated
DAST plus production-size load testing were not run. The list of what closes
each gate is in [security-residual-risks.md](security-residual-risks.md).

| | |
|---|---|
| Audit date | 2026-10-11 |
| Release candidate | `34fd05c` (app image `digital-goods-shop`, web image `digital-goods-shop-web`, both built from it). Later commits change only documentation and test harnesses |
| Scope | Whole repository: Laravel app (189 routes), nginx, Dockerfile and compose, CI workflow, migrations, scripts, tests; dynamic tests against the production images on the simulation stack |
| OWASP edition | Top 10:2025 (November 2025), <https://owasp.org/Top10/2025/> |
| Method | Five parallel code reviews (auth, authorization, injection, files/SSRF/webhooks, business logic), scanners, dynamic probes, ZAP, fixes with regression tests, an independent review of the fixes, full simulation suite on the candidate |

## Gates

| Gate | Status |
|---|---|
| G01 Attack surface | PASS |
| G02 Threat model | PASS |
| G03 Dependencies and supply chain | **BLOCKED** (exceptions R-04, R-05 need approval) |
| G04 Authentication | PASS (SEC-30 low open, R-01) |
| G05 Authorization, isolation | PASS (RLS not applicable: single app role, justified) |
| G06 Injection | PASS |
| G07 CSRF, CORS, browser, transport | **BLOCKED** (production TLS terminator, R-07) |
| G08 Files and traversal | PASS |
| G09 SSRF and webhooks | PASS |
| G10 Business logic | PASS |
| G11 API abuse and DoS | **NOT VERIFIED** (load at production size, R-13) |
| G12 Secrets and infrastructure | **BLOCKED** (deployment-owned, R-07, R-08) |
| G13 Logging and alerting | PASS |
| G14 AI / LLM | NOT APPLICABLE (no AI functionality) |
| G15 OWASP mapping | PASS |
| G16 Automated testing | **NOT VERIFIED** (authenticated DAST, R-03) |
| G17 Remediation verification | PASS |
| G18 Independent reassessment | PASS |
| G19 CI/CD enforcement | **BLOCKED** (branch protection needs an admin, R-09) |

Totals: 19 gates; 12 PASS, 0 FAIL, 4 BLOCKED, 2 NOT VERIFIED, 1 NOT APPLICABLE.

## Findings

39 findings: 0 critical, 5 high, 14 medium, 18 low, 2 informational
([security-findings.md](security-findings.md)). 38 fixed and retested; each
fix has a regression test that failed on the code before the fix, or a probe
against the images ([evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt)).
Open: SEC-30 (low, registration reveals registered emails).

The high findings were: the Host header deciding Shkeeper's callback URL
(API key exposure, forged payments), moderators able to issue crypto refunds
and staff deciding their own disputes, seller earnings payable while still
disputable, a vulnerable internet-facing web image, and (found by the
independent review) a timeout rule that could have failed accepted transfers
and paid twice.

## Evidence on the release candidate

| Check | Result |
|---|---|
| PHPUnit (`php artisan test`) | 296 passed, 0 failed; 34 security regression tests |
| Security gate (`scripts/security-gate.sh`, both images, regression tests) | PASS, 9 of 9 checks ([evidence/security-gate-results.json](evidence/security-gate-results.json)) |
| Dependencies | Composer 0 advisories / 0 abandoned (120 packages), npm 0, OSV 0; Trivy HIGH/CRITICAL fixable: app 0, web 0 |
| Secrets | gitleaks over 96 commits: 0 (17 public test values allowlisted by exact value) |
| SAST | Semgrep 1.180.0, 9 registry rulesets + 6 project rules: 0 findings |
| Dynamic probes on the images | 61 of 61 ([evidence/dynamic-probes.json](evidence/dynamic-probes.json)): CORS, CSRF, 28 sensitive paths, headers, cookies, Host and X-Forwarded-* handling, size limits, webhook forgery, rate limits with rotating headers, login timing |
| ZAP 2.15.0 | Baseline: 0 fail, 48 pass, 9 low/info warnings; active scan (223 URLs): 0 fail, 101 pass, 2 warnings triaged as false positives ([evidence/](evidence/zap-full-scan.md)) |
| Simulation suite (production images, TLS front end) | SIM_RESULT |

## Tests not executed

Authenticated DAST per role (R-03), external penetration test (R-10), the
real Shkeeper instead of the mock (R-11), load at production size (R-13), and
everything that needs the production environment: TLS configuration, secret
storage, database role, network egress, backups, branch protection (R-07 to
R-09).

## Residual risk

Low for the code as audited, assuming the deployment follows
docs/OPERATIONS.md ("Reverse proxy, client addresses and TLS"). The
remaining risk sits in the deployment, in business fraud patterns (R-02,
R-16) and in what automated testing cannot show. This audit was performed by
an AI agent; it does not replace a human security review.

## To reach PASS

1. Security owner approves or rejects R-01, R-04 and R-05 (in
   security-residual-risks.md).
2. On staging: TLS check, secret storage, least-privilege database role,
   `NGINX_TRUSTED_PROXY`, egress limits, backups; record the evidence.
3. Repository admin: branch protection with required checks `php`,
   `security`, `browser` and code-owner review.
4. Authenticated ZAP per role and a load test on production-like hardware.
5. External penetration test.
