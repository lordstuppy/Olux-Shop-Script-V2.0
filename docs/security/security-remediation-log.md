# Remediation log

For each fix: root cause, change, regression test, verification and commit.
"Before" means the regression test was run against the code before the fix
(fixes stashed, tests kept) and failed for the reason named in
[evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt);
"after" means it passes on the fixed code. All 296 PHPUnit tests pass on
`34fd05c` (`php artisan test`), as do Pint and the ASCII check.

| Id | Root cause | Change (main files) | Regression test | Verification | Commit |
|---|---|---|---|---|---|
| SEC-01 | Absolute URLs built from the request Host | `app/Http/Middleware/PinAppUrl.php`, `bootstrap/app.php`, `config/shop.php` (`pin_app_url`) | `test_sec01_*` | Before: callback `http://evil.example/...`; after: `https://shop.example.test/...`; probe HOST on the images | c0bae6a |
| SEC-02 | Refund branch trusted the route ability `disputes.manage` | `DisputeService` (needs `orders.manage`, party check), `disputes/show.blade.php` | `DisputeTest::test_sec02_*` | Before: refund form shown and accepted for moderators; after: refused, balances unchanged | c0bae6a |
| SEC-03 | Availability ignored delivery and the dispute window | `PayoutService::balances`, seller payouts page, `config/shop.php` | `test_sec03_*`, `RefundAndPayoutTest` (held on day 8, payable on day 15) | Before: payable after 30 days although undelivered; after: held until delivered + window | c0bae6a |
| SEC-04 | nginx passed client `X-Forwarded-*` to PHP; whole private ranges trusted | `docker/nginx/default.conf.template` (realip from `NGINX_TRUSTED_PROXY`, own fastcgi params), `docker-compose.yml`, `docker-compose.test.yml` | Probe (stack) | Before: forged IP stored in `sessions.ip_address` (old config); first attempt (trust 172.16/12) still spoofable via Docker gateway; after: `spoofed sessions: 0` on the production default, rate limits hold with rotating headers | c0bae6a, 70954d9 |
| SEC-31 | Framework trusts all proxies for `*.on-forge.com` hosts when none are configured | `app/Http/Middleware/TrustProxies.php` | `test_sec31_*` | Before: `203.0.113.7|shop.on-forge.com`; after: real peer, host from Host header | 70954d9 |
| SEC-32 | Stale `1.27-alpine` web image | `docker/nginx/Dockerfile` (1.30-alpine + `apk upgrade`), compose `build:`, `tests/simulation/run.sh`, gate scans both images | Gate `image` check | Before: 41 fixable HIGH/CRITICAL; after: Trivy `image` check PASS for app and web | a00e05f |
| SEC-05 | Access checked order status, not line refunds | `OrderItem::isFullyRefunded`, `DownloadController`, `OrderController::show`, `orders/show.blade.php`, `ReviewService::eligibleItem`, `DeliveryService::deliver` | `test_sec05_*` | Before: download 200; after: 403, keys hidden, no review | c0bae6a |
| SEC-06 | One external id for every payout attempt | `Payout::externalId`, migration `2026_10_15_000100_add_payout_attempts`, `ShkeeperPayoutService::handleResult/staleAttempt` | `test_sec06_*` | Before: stale FAIL failed attempt 2; after: ignored, stale SUCCESS alerts staff | c0bae6a |
| SEC-07 | Status checked before, changed after the HTTP call | `ShkeeperPayoutService::sendPayout` (claim under row lock) | `test_sec07_*` | Before: status `approved` during the transfer and a second send accepted; after: `processing`, second send refused | c0bae6a |
| SEC-08 | Every client error treated as "not sent" | `ShkeeperException::$outcomeUnknown`, `ShkeeperClient::createPayout/payoutStatus`, `ShkeeperPayoutService::sendRefund/reconcile` | three `test_sec08_*` | Before: refund marked failed after a timeout; after: pending until the poll, failed only when Shkeeper does not know it after 30 min | c0bae6a |
| SEC-09 | Approve/reject did not check the application status | `UserService::assertPendingApplication` | `test_sec09_*` | Before: rate wiped to null; after: refused, 2500 bps kept | c0bae6a |
| SEC-10 | Single-session sign-out kept the remember token | `SessionController::destroy` | `test_sec10_*` | Token rotated, current device re-remembered | c0bae6a |
| SEC-11 | Only a per-minute limiter checked after the attempt | `TwoFactorController::verifyChallenge` (hit before check, 10/h budget, owner mail) | `test_sec11_*` | Before: 11th code accepted; after: refused, mail queued | c0bae6a |
| SEC-12 | 2FA enable without password, replace allowed; recovery codes without second factor | `TwoFactorController`, `TwoFactorService`, `account/two-factor.blade.php` | `test_sec12_*`, `AccountSecurityTest` | Before: replace allowed; after: refused, password and code required | c0bae6a |
| SEC-13 | No upload throttle or quota; 40 MP decode | `routes/web.php` (`throttle:uploads`), `AppServiceProvider`, `Seller\ProductController::assertStorageRoom`, `ProductImageService`, `config/shop.php` | two `test_sec13_*` | Before: 11th upload accepted, quota absent; after: 429, quota and cap enforced | c0bae6a |
| SEC-14 | Check-then-save without a lock | `TwoFactorService::verify` (row lock) | Existing replay tests in `AccountSecurityTest` | Code review; replay of a used code still refused | c0bae6a |
| SEC-15 | No hash work for unknown emails; then a per-request dummy hash | `UserService::checkCredentials`, cached `dummyHash()` | Timing probe | Before fix: unknown faster; first fix: unknown slower (0.759 s vs 0.528 s); final: 0.434 s vs 0.422 s | c0bae6a, 70954d9 |
| SEC-16/17/18 | Remember token kept on status change; reset tokens kept; no security mails | `UserService::setStatus/resetPassword`, `AccountController::updatePassword`, `AccountSecurityMail` (+ template `account_security`) | `test_sec16_to_18_*` | Token rotated, reset token deleted, mails queued | c0bae6a |
| SEC-19 | Route model binding answered for any product id | `CartController::assertKnown`, `WishlistController::destroy` | `test_sec19_*` | Before: 302 with the title; after: 404 | c0bae6a |
| SEC-20 | Session revoke lacked the staff check of `setStatus` | `Admin\UserController::revokeSessions` | `test_sec20_*` | Refused, admin token unchanged | c0bae6a |
| SEC-21 | Visibility ignored the seller's role | `Product::scopeVisible`, `isPurchasable` | `test_sec21_*` | Product hidden after demotion | c0bae6a |
| SEC-22 | Only `infected` blocked | `Admin\ProductController::downloadFile`, `admin/product-show.blade.php` | `test_sec22_*` | 409 for pending, 200 for clean | c0bae6a |
| SEC-23 | `isPaidState()` includes `refunded` | `Admin\OrderController::retryDelivery`, `DeliveryService::deliver` | `test_sec23_*` | 422, nothing delivered | c0bae6a |
| SEC-24 | Missing rate skipped the limits | `OrderService::assertWithinOrderLimits` | `test_sec24_*` | Order refused | c0bae6a |
| SEC-25 | No line-break rule on single-line fields | Validators in six controllers | `test_sec25_*` | Validation errors for `subject` and `name` | c0bae6a |
| SEC-26 | `innerHTML` round trip | `public/js/enhance.js` | Browser suite (`tests/browser/live.spec.js`) | Code review | c0bae6a |
| SEC-27 | Guzzle follows redirects with custom headers | `ShkeeperClient` (`withoutRedirecting`) | `test_sec27_*` | Before: request sent to evil.example; after: not sent | c0bae6a |
| SEC-28 | Mutable action tags, default token scope | `.github/workflows/ci.yml`, `.github/dependabot.yml` | Semgrep `p/github-actions` | 12 findings before, 0 after | c0bae6a |
| SEC-29 | Unused Go binary in the image, no OS updates | `Dockerfile` | Gate `image` check | 147 Go CVEs before, PASS after | c0bae6a |
| SEC-33 | Readable XSRF cookie, no HSTS on static files | `PreventRequestForgery::$addHttpCookie`, nginx static location | Probe (cookies, static headers) | Only `laravel-session` set; HSTS on `/css/app.css` | a00e05f |
| SEC-34 | HTTP refusals not reported | `LogSecurityEvents` middleware | `test_sec34_*` | Fails without the middleware, passes with it | 66726de |
| SEC-35 | Heuristic applied to accepted transfers; late SUCCESS dropped; 2xx without task id = failure | `ShkeeperPayoutService` (`reconcile`, `payoutSucceeded`, `alertIfStuck`, refund SUCCESS on failed), `ShkeeperClient`, `ShkeeperException::$shkeeperNotFound` | four `test_sec35_*` | All four failed on 66726de, pass now | 34fd05c |
| SEC-36 | Code budget only at sign-in; enable without per-user limit | `TwoFactorService::verifyLimited`, `TwoFactorController`, `routes/web.php` | two `test_sec36_*` | Failed on 66726de (correct code accepted after 10 guesses; 302 instead of 429) | 34fd05c |
| SEC-37 | No self-dealing check outside disputes | `App\Support\SelfDealing`, admin order/user controllers, payout services | `test_sec37_*` | Failed on 66726de (own refund accepted) | 34fd05c |
| SEC-38 | Refund check outside the row lock | `DeliveryService::deliverInstant` | `test_sec38_*` (guard) | Code review for the race | 34fd05c |
| SEC-39 | Scheme lost after realip | `docker/nginx/default.conf.template` (`HTTPS`, `REQUEST_SCHEME`) | Simulation P3/P4/P5 | 403 reproduced on the stack (valid link), after: 302 valid, 403 tampered | 34fd05c |

## Mistakes made during remediation (and caught)

- The first SEC-04 fix trusted all private ranges in nginx; the dynamic probe
  showed clients still spoofing through Docker's gateway.
- The first SEC-15 fix made unknown emails slower; the timing probe caught it.
- SEC-35, SEC-36 and SEC-38 were gaps in this audit's own fixes, found by the
  independent review; SEC-39 was a regression of the SEC-04 fix, found by the
  simulation suite.
- Twice an Eloquent `value('status')` returned a cast enum where a string was
  compared (seller approval, delivery guard); the PHPUnit suite caught both
  before any image was built.

## Adjacent paths checked for the same weakness

- Other absolute URLs built in requests (SEC-01): mails built in queue
  workers use `APP_URL` already; `PinAppUrl` covers every request-time URL.
- Other money transfers (SEC-06 to SEC-08): balance and manual refunds are
  local transactions under row locks; gift cards and coupons lock their rows.
- Other places that deliver goods (SEC-05, SEC-23): `DeliveryService::replace`
  (dispute replacement) runs only for open disputes on paid lines;
  manual delivery already required a paid order.
- Other staff actions on staff accounts (SEC-20): role and status changes
  already required a super admin.

## Supporting changes

- Tests adjusted to the new rules (2FA password field, earnings held through
  the dispute window): `AccountSecurityTest`, `DisputeTest`,
  `MultiSellerOrderTest`, `PaymentAutomationTest`, `RefundAndPayoutTest`.
- Harnesses: `tests/simulation/phases/*` and `tests/live/live_test.py` send
  the password when enabling 2FA and move sales past the dispute window
  before payout scenarios.
- Gate tooling: `scripts/security-gate.sh`, `.semgrep/shop-rules.yml`,
  `.gitleaks.toml`, CI job `security`, `.github/CODEOWNERS`.
