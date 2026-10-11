# OWASP mapping

**Edition used:** OWASP Top 10:2025, the current edition at the time of the
audit (released November 2025, the first update since 2021). Official source:
<https://owasp.org/Top10/2025/> (introduction:
<https://owasp.org/Top10/2025/0x00_2025-Introduction/>). owasp.org could not
be reached from the audit environment (DNS failure), so the edition and its
ten categories were confirmed through search results citing the official
introduction page; nothing older was substituted.

Additional guidance used: OWASP ASVS 4.0.3 chapters as a checklist (no level is
claimed, see the end of this file), OWASP API Security Top 10:2023 for the two
JSON endpoints (`/live`, `/search/suggest`) and the webhooks, and the OWASP
Web Security Testing Guide for the dynamic probes.

Evidence keys: **T** = PHPUnit test, **P** = dynamic probe on the production
images (`evidence/dynamic-probes.json`), **Z** = ZAP (`evidence/zap-*.md`),
**S** = scanner in `scripts/security-gate.sh`, **Sim** = simulation suite
(docs/SIMULATION.md), **R** = code review.

| Category | Applicable | Components | Verification | Findings / result | Regression tests | Residual risk |
|---|---|---|---|---|---|---|
| **A01 Broken Access Control** (now including SSRF) | Yes | All 189 routes, policies, staff permission map, downloads, `/live`, notifications, Shkeeper callback URL | R of every route parameter (attack-surface section 8); T `OrderAccessTest`, `StaffRolesTest`, `SecurityAuditTest`; Sim phases 1-11 (cross-user, cross-role scenarios); P (CORS, CSRF) | SEC-01, SEC-02, SEC-05, SEC-09, SEC-19, SEC-20, SEC-21, SEC-22, SEC-37 fixed. SSRF: outbound requests only to the env-configured Shkeeper, ClamAV and SMTP hosts, no user-supplied URL is fetched; redirects not followed (SEC-27) | `test_sec01`, `test_sec02`, `test_sec05`, `test_sec09`, `test_sec19`-`test_sec22`, `test_sec37` | No authenticated DAST crawl (R-03) |
| **A02 Security Misconfiguration** | Yes | nginx, PHP, Laravel config, headers, cookies, CORS, compose | P (headers, cookies, exposure of 28 sensitive paths, debug endpoints, body/header limits, Host and X-Forwarded-* handling), Z, S (Trivy config), R of compose/Dockerfile | SEC-04, SEC-31, SEC-33, SEC-39 fixed; CSP, HSTS, XFO, nosniff, COOP, Referrer-Policy, Permissions-Policy present; no CORS headers; `APP_DEBUG=false` in images | `test_sec31`, probe set | TLS configuration belongs to the deployment (R-07) |
| **A03 Software Supply Chain Failures** | Yes | composer/npm lockfiles, base images, GitHub Actions, Dependabot | S (composer audit, npm audit, OSV, Trivy image), gitleaks, Semgrep `p/github-actions` | SEC-28, SEC-29, SEC-32 fixed; 0 advisories in locked packages | Gate job in CI | Base images by tag not digest (R-06); PostgreSQL image `gosu` (R-04) |
| **A04 Cryptographic Failures** | Yes | Argon2id passwords, encrypted licence keys/payloads/2FA secrets, HMAC webhooks, signed URLs, cookies | R (`config/hashing.php`, casts, `KeyedHash`, `ShkeeperClient::verifySignature`), T `WebhookTest`, P (webhook with wrong signature / stale timestamp: 401) | No finding. Constant-time comparisons, 5-minute replay window, key rotation via `APP_PREVIOUS_KEYS` | `WebhookTest`, `AccountSecurityTest` | The Shkeeper API key doubles as the callback signing key (Shkeeper's design): keep it out of logs and URLs (R-07) |
| **A05 Injection** | Yes | SQL (Eloquent, raw expressions), Blade, mail templates, CSV export, headers, logs, live-update JS | R of every raw SQL and `{!! !!}`; Semgrep registry + project rules (`shop-no-interpolated-raw-sql`, `shop-no-unescaped-blade-output`, `shop-no-dangerous-functions`); Z active scan (SQLi, XSS, command, path traversal, SSTI rules: no finding) | SEC-25 (line breaks in mail fields), SEC-26 (mXSS hardening) fixed; no injection found | `test_sec25`, Semgrep rules in CI | - |
| **A06 Insecure Design** | Yes | Payout holds, disputes, refunds, transfers, uploads, order limits | R of money flows and state machines; T; Sim phases 2, 4, 5, 9 (concurrency, retries) | SEC-03, SEC-06, SEC-07, SEC-08, SEC-13, SEC-23, SEC-24, SEC-35, SEC-38 fixed | `test_sec03`, `test_sec06`-`test_sec08`, `test_sec13`, `test_sec23`, `test_sec24` | Collusion with second buyer accounts (R-02) |
| **A07 Authentication Failures** | Yes | Login, lock, 2FA, sessions, remember-me, reset, email change | R; T `AccountSecurityTest`, `LoginLockTest`, `SecurityAuditTest`; P (rate limit with rotating X-Forwarded-For, login timing) | SEC-10, SEC-11, SEC-12, SEC-14, SEC-15, SEC-16, SEC-17, SEC-18, SEC-36 fixed; SEC-30 open (accepted risk proposed) | `test_sec10`-`test_sec12`, `test_sec16_to_18` | Registration enumeration (R-01) |
| **A08 Software or Data Integrity Failures** | Yes | Webhook payloads, queued jobs, deserialisation, CI pipeline | R (no `unserialize`, theme cookie decrypted with `unserialize=false`, jobs carry ids), T `WebhookTest`, `WebhookRetryTest`, S (pinned actions, CODEOWNERS) | No finding | Semgrep rule `shop-no-dangerous-functions` | Branch protection must be configured on GitHub (R-09) |
| **A09 Security Logging and Alerting Failures** | Yes | JSON log, audit log, gateway log, staff alerts | R; T; log review of the simulation stack (no passwords, tokens or keys; emails only as SHA-256) | SEC-34 fixed; SEC-11/SEC-18 add owner alerts | `test_sec34`, `test_sec11` | Central log shipping and retention are operator tasks (R-07) |
| **A10 Mishandling of Exceptional Conditions** | Yes | Gateway timeouts, scanner outages, storage errors, error pages | R; T `OutageTest`, `StorageFailureTest`, `GatewayTest`; Sim phase 8 (recovery); P (malformed input: no stack trace) | SEC-08 (timeout treated as failure), SEC-35 (assumed failures, lost results), SEC-39 (signed links failing behind TLS) fixed; ClamAV fails closed; error pages show a request id only | `test_sec08` (3 tests) | - |

## OWASP API Security Top 10:2023 (JSON endpoints and webhooks)

| Risk | Endpoint(s) | Result |
|---|---|---|
| API1 BOLA | `/live?path=` | Same policies as the page; internal notes excluded (LivePages); T `NotificationCentreTest` |
| API2 Broken authentication | webhooks | HMAC + timestamp + de-dup; P 401 for unsigned, wrong and stale requests |
| API4 Unrestricted resource consumption | `/live` (30/min), `/search/suggest` (60/min), webhooks (120/min) | P: limiter holds with rotating X-Forwarded-For |
| API7 SSRF | none | No user-controlled URLs are fetched |
| API8 Misconfiguration | all | No CORS headers (P), JSON errors without traces |

## ASVS

Chapters V1 to V14 of ASVS 4.0.3 were used as a review checklist. **No ASVS
level is claimed**: several level-2 requirements depend on the deployment
(TLS configuration, log retention, secret storage, backups) and were not
assessed in this repository-only audit.
