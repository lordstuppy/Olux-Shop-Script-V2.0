# Residual risks, blocked tests and required follow-up

Everything here keeps at least one release gate from PASS until it is resolved
or formally accepted. "Approval needed" means the designated security owner
must sign the exception (name, date, expiry) in this file; nothing here has
been approved yet.

## Open findings and proposed exceptions

| Id | Risk | Severity | Why open | Compensating controls | Action / approval |
|---|---|---|---|---|---|
| R-01 | Registration reveals whether an email is registered (SEC-30) | Low | Fix changes the sign-up flow (generic "check your email" + mail to the existing owner): product decision | 10 registrations per hour per IP, IP no longer spoofable (SEC-04), login and reset do not enumerate (SEC-15) | Approval needed, or implement the generic flow. Proposed expiry 2027-01-31 |
| R-04 | `postgres:15-alpine` contains `gosu` built with Go 1.24.6 (1 critical, 24 high Go stdlib CVEs) | Low (not reachable) | No rebuilt upstream PG 15 image at audit time; `gosu` only drops privileges at container start, receives no input | Database port bound to the internal network / 127.0.0.1, no shell access for the app | Approval needed; re-check monthly; move to a newer PostgreSQL image in the next maintenance window. Expiry 2027-01-31 |
| R-05 | Vulnerable lockfiles and sample Dockerfiles inside third-party `vendor/*/docs` and `vendor/*/tests` | Info | Third-party files, never installed or executed | Excluded from execution; app image built `--no-dev` | Approval needed; review on each dependency update |
| R-02 | Seller collusion with a second buyer account (review farming, turning balance into payouts); FX rounding up to half a cent per unit; platform coupons reduce seller earnings | Low / business | Needs fraud rules or policy, not a code defect | Payout approval by finance, audit log, earnings holds (SEC-03) | Product owner decision |
| R-14 | A Shkeeper transfer can stay "processing" if Shkeeper never answers status polls; there is no audited "mark failed" action, only an alert after 24 h and "mark paid" | Low | Deliberate: the shop cannot know whether money left; a person must check Shkeeper | Daily alert, audit log | Add a super-admin "mark failed after checking Shkeeper" action with a reason (follow-up) |
| R-15 | Product images count towards no storage quota (at most 6 per product, 5 MB each, upload throttle 60/h) | Low | Bounded by throttle and per-product cap | Upload throttle, image cap | Include images in the seller quota (follow-up) |
| R-16 | Insider with gift-card rights can create a card and redeem it on their own account | Low | Gift cards are bearer codes by design | Audit log of every card, finance-only ability | Four-eyes rule for gift cards above a threshold (policy) |
| R-12 | Reviews written before a later full refund stay visible (new reviews are blocked, SEC-05) | Low | Moderation decision | Moderators can hide reviews | Product owner decision |

## Tests not executed or only partly executed

| Id | What | Status | Why | Needed to close |
|---|---|---|---|---|
| R-03 | Authenticated DAST (ZAP logged in as buyer, seller and each staff role) | NOT VERIFIED | ZAP ran unauthenticated (passive + active, 223 URLs). The authenticated surface was tested by 288 PHPUnit tests and the simulation suite (cross-user and cross-role scenarios), not by a scanner | ZAP with authentication contexts per role against a staging copy |
| R-10 | Human penetration test and second-engineer review of this audit | NOT VERIFIED | An automated/AI-assisted audit is no substitute | External pentest of the staging deployment |
| R-11 | Behaviour of the real Shkeeper (payout status 404 for unknown transfers, callback headers, retries) | NOT VERIFIED | Only the repository's mock was available | Run the live test (`tests/live`) against a Shkeeper test instance |
| R-13 | Load at production size | NOT VERIFIED | 4-core sandbox (docs/SIMULATION.md phase 7) | Load test on production-like hardware |

## Controls that live outside this repository (deployment)

| Id | Control | Status | Required action |
|---|---|---|---|
| R-07 | TLS configuration (protocols, ciphers, certificates, HSTS preload decision), secret storage for `.env` values, log shipping and retention, encrypted backups, network egress limits, `TRUSTED_PROXIES` / `NGINX_TRUSTED_PROXY` set to the real proxy, `SHKEEPER_WEBHOOK_ALLOWED_IPS`, the Shkeeper API key kept secret (Shkeeper also signs callbacks with it), ClamAV `AlertEncrypted` / `AlertExceedsMax` | BLOCKED (no production environment available) | Operator checklist in docs/OPERATIONS.md; verify on staging and record the evidence here |
| R-08 | Least-privilege PostgreSQL role (owner of the schema only, no superuser, no `CREATEROLE`/`CREATEDB`), no public database port | BLOCKED | Create the role as documented; check with `\du` |
| R-09 | GitHub branch protection: required checks `php`, `security`, `browser`; "Require review from Code Owners"; no force pushes to the default branch | BLOCKED (needs repository admin) | Configure in GitHub settings; the files (`ci.yml`, `CODEOWNERS`) are ready |
| R-06 | Base images pinned by tag, not by digest | Open (Low) | Pin digests and let Dependabot (docker ecosystem) update them |

## Credential rotation

No live credential was found in the repository or its history (gitleaks over
96 commits). The values allowlisted in `.gitleaks.toml` are public test
values of the disposable stacks. No rotation is required. If any of those
values was ever reused outside the test stacks, rotate it.
