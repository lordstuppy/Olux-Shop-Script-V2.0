# Dependencies and software supply chain

Audit of 2026-10-11. Inventory from the lockfiles (`composer.lock`,
`tests/browser/package-lock.json`) and the built images, not from manifests
alone. The gate (`scripts/security-gate.sh`, CI job `security`) re-runs every
check below on each push.

## 1. Tools and databases used

| Check | Tool and version | Command | Result |
|---|---|---|---|
| PHP advisories and abandoned packages | Composer 2.8.12 (Packagist advisory DB, includes GitHub advisories and FriendsOfPHP) | `composer audit --locked --abandoned=fail` | 0 advisories, 0 abandoned (120 locked packages, 84 production) |
| npm advisories | npm (Node 22) | `npm audit --audit-level=high` in `tests/browser` | 0 vulnerabilities (7 packages, test-only) |
| Cross-ecosystem advisories | OSV-Scanner v1.9.1 (osv.dev) | `osv-scanner --lockfile composer.lock --lockfile tests/browser/package-lock.json` | No issues found |
| Filesystem SCA | Trivy 0.57.1 | `trivy fs --scanners vuln,secret,misconfig` | Findings only in documentation/test fixtures shipped inside `vendor/` (Ruby `Gemfile.lock` of league/config docs, a Python requirements file of mockery docs, sample Dockerfiles). None is installed or executed; the app image excludes nothing it needs and these files are not loaded. Recorded as R-05 |
| Image scan, app | Trivy 0.57.1 | `trivy image --severity HIGH,CRITICAL --ignore-unfixed digital-goods-shop` | Before: 147 Go stdlib CVEs in `vendor/laravel/framework/bin/splitsh-lite` (Laravel's repository-split tool, never executed) and 1 medium (nghttp2). After removing the binary and `apk upgrade`: see the final run in [security-audit-summary.md](security-audit-summary.md) |
| Image scan, web | Trivy 0.57.1 | as above on the web image | Before: `nginxinc/nginx-unprivileged:1.27-alpine` (built 2025-06-23, Alpine 3.21.3) had 41 fixable HIGH/CRITICAL, including OpenSSL CVE-2026-31789 (critical), libxml2, libexpat, zlib, musl (SEC-32). After: own image on `1.30-alpine` (nginx 1.30.5, Alpine 3.24.2) plus `apk upgrade` |
| Secrets | gitleaks v8.21.2 | `gitleaks git` (96 commits) and `gitleaks dir` | 17 history hits, all public fixed values of the disposable test stacks (Shkeeper mock keys, TOTP secrets of seeded simulation staff); allowlisted by exact value in `.gitleaks.toml`, so a new secret in the same files is still reported. Working tree: same values, plus third-party test keys inside `vendor/` (not ours) |

## 2. Direct dependencies

| Package | Locked version | Purpose | Support status (2026-10) | Decision |
|---|---|---|---|---|
| php | 8.3.35 (image) | Runtime | Security support until 2027-12-31 | Keep; plan 8.4 before end of 2027 |
| laravel/framework | 13.35.0 | Framework | Current major, security fixes | Keep, patch releases via Dependabot |
| barryvdh/laravel-dompdf | 3.1.2 (dompdf 3.1.6) | Invoices (PDF) | Maintained | Keep; remote resources and PHP stay disabled (defaults) |
| brick/math | 1.0.0 | Money arithmetic | Maintained | Keep |
| chillerlan/php-qrcode | 6.0.1 | QR codes (2FA, payment address) | Maintained | Keep |
| pragmarx/google2fa | 9.1.0 | TOTP | Maintained, small surface | Keep |
| Dev only: phpunit 12.5.38, mockery 1.6.15, faker 1.24.1, pint 1.32.1, pail 1.2.7, pao 1.1.5, tinker 3.0.2, collision 8.9.5 | | Tests and tooling, `--no-dev` in the image | Maintained | Not shipped |
| @playwright/test 1.56.1, @axe-core/playwright 4.10.2 | | Browser tests only | Maintained | Not shipped |

`composer outdated --direct`: all direct dependencies are at their latest
compatible release.

## 3. Base images and services

| Image | Tag (before -> after) | Status | Decision |
|---|---|---|---|
| App runtime | `php:8.3-fpm-alpine` + `apk upgrade` | Alpine 3.24.2 | `apk upgrade` added so builds pick up OS fixes released after the base image |
| Composer build stage | `composer:2` | Build only, not shipped | Keep |
| Web | `nginxinc/nginx-unprivileged:1.27-alpine` -> `digital-goods-shop-web` from `1.30-alpine` + `apk upgrade` | 1.27 mainline is superseded (no fixes); 1.30 is the current stable branch | Replaced (SEC-32) |
| Database | `postgres:15-alpine` | PostgreSQL 15 supported until 2027-11. Image contains `gosu` built with Go 1.24.6 (25 Go stdlib findings). `gosu` only drops privileges at container start, takes no network input | Accepted with justification (R-04); move to `postgres:17-alpine` with the next maintenance window |
| ClamAV | `clamav/clamav:stable` | Vendor image, signature updates at run time | Keep; scan in deployment pipeline (R-04) |
| Test-only images (`nginx` TLS front end, mailpit, shkeeper-mock, fakeclamd) | `nginx:1.27-alpine` -> `nginx:1.30-alpine` | Never deployed | Updated where cheap |

## 4. Integrity, pinning and provenance

- `composer.lock` and `package-lock.json` are committed; CI installs from
  them (`composer install`, `npm ci`). Composer verifies dist checksums.
- No install scripts run for production packages: the image runs
  `composer install --no-dev --no-scripts`. npm packages are test-only.
- GitHub Actions are pinned to full commit SHAs (SEC-28), the workflow token
  is read-only (`permissions: contents: read`), and Dependabot waits seven
  days before proposing a new version (`cooldown`), which avoids most
  short-lived malicious releases.
- Base images are pinned by tag, not digest. Digest pinning with Dependabot
  updates is recommended (R-06).
- Reproducibility: the gate records the git revision and whether the tree
  was dirty; images are rebuilt in CI from that revision.

## 5. Exceptions

| Id | Component | Finding | Why accepted | Owner / expiry |
|---|---|---|---|---|
| R-04 | `postgres:15-alpine` `gosu` | Go stdlib CVEs (1 critical, 24 high) in a binary that only runs at start-up as root to drop privileges | Not reachable from the network or the application; no fixed upstream image of PG 15 at audit time | Needs approval by the security owner; expires 2027-01-31 or on the next upstream image |
| R-05 | Files under `vendor/*/docs`, `vendor/*/tests` | Vulnerable Ruby/Python lockfiles and sample Dockerfiles of third-party documentation | Not installed, not executed | Needs approval by the security owner; review at each dependency update |
