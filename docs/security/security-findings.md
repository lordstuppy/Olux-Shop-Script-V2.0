# Security findings

Audit of 2026-10-11 (OWASP Top 10:2025 categories in brackets). Every finding
was reproduced: by a regression test that failed on the code before the fix
(`tests/Feature/SecurityAuditTest.php`, `tests/Feature/DisputeTest.php`, run
against the pre-fix tree with the fixes stashed: 25 of 25 failed), by a probe
against the production images (`docs/security/evidence/`), or both. Fix
commits: `c0bae6a` (main fixes), `70954d9` (proxy trust, gate), `a00e05f`
(web image, ZAP follow-ups), `66726de` (security event log), `34fd05c`
(findings of the independent review, SEC-35 to SEC-39). Severity uses impact x exploitability for this
shop, not scanner scores.

| Id | Severity | Title | Status |
|---|---|---|---|
| SEC-01 | High | Host header controls the Shkeeper callback URL and emailed links | Fixed, retested |
| SEC-02 | High | Moderators can issue dispute refunds (incl. crypto to any address); staff can decide their own disputes | Fixed, retested |
| SEC-03 | High | Seller earnings payable while the buyer can still dispute, or before delivery | Fixed, retested |
| SEC-32 | High | Internet-facing web image with 41 fixable HIGH/CRITICAL OS vulnerabilities (OpenSSL critical) | Fixed, retested |
| SEC-04 | Medium | Client-supplied X-Forwarded-For trusted (IP spoofing: rate limits, webhook allowlist, audit IPs) | Fixed, retested |
| SEC-31 | Medium | No proxies configured + forged `*.on-forge.com` Host = every proxy trusted (framework default) | Fixed, retested |
| SEC-05 | Medium | Fully refunded line keeps downloads, licence keys and review rights | Fixed, retested |
| SEC-06 | Medium | Payout result not bound to the transfer attempt (stale FAIL leads to a double payout) | Fixed, retested |
| SEC-07 | Medium | Payout not claimed before the transfer (double send, send + reject race) | Fixed, retested |
| SEC-08 | Medium | Crypto refund timeout treated as failure (retry refunds twice) | Fixed, retested |
| SEC-09 | Medium | Manager re-approving a seller wipes a super-admin commission rate; reject of approved sellers | Fixed, retested |
| SEC-10 | Medium | "Sign out" of one session leaves its remember-me login valid | Fixed, retested |
| SEC-11 | Medium | Two-factor codes not limited per account (about 7,200 guesses a day) | Fixed, retested |
| SEC-12 | Medium | 2FA enable needs no password and can replace an active setup; recovery codes without second factor | Fixed, retested |
| SEC-13 | Medium | Seller uploads unthrottled, no quota, 40 MP decode | Fixed, retested |
| SEC-14 | Low | TOTP step / recovery code usable twice in a race | Fixed (row lock), covered by existing replay tests |
| SEC-15 | Low | Login response time reveals whether an email is registered | Fixed, retested by timing probe |
| SEC-16 | Low | Remember-me cookies revive when a suspended account is reactivated | Fixed, retested |
| SEC-17 | Low | Password change leaves an open reset link valid | Fixed, retested |
| SEC-18 | Low | No email on password change/reset or 2FA changes | Fixed, retested |
| SEC-19 | Low | Cart/wishlist answers reveal titles and stock of unlisted products | Fixed, retested |
| SEC-20 | Low | Support staff can sign out super admins | Fixed, retested |
| SEC-21 | Low | Seller demoted to buyer stays listed and purchasable | Fixed, retested |
| SEC-22 | Low | Staff can download files the virus scanner has not passed | Fixed, retested |
| SEC-23 | Low | "Deliver again" delivers on refunded orders | Fixed, retested |
| SEC-24 | Low | Order maximum skipped when the currency has no exchange rate | Fixed, retested |
| SEC-25 | Low | Line breaks in titles, names and subjects let users shape shop emails | Fixed, retested |
| SEC-26 | Info | Live-update swap re-serialised markup through `innerHTML` (mXSS primitive) | Hardened |
| SEC-27 | Low | Shkeeper client followed redirects with API key / payout credentials | Fixed, retested |
| SEC-28 | Low | CI actions on mutable tags, default token permissions, no Dependabot cooldown | Fixed (Semgrep clean) |
| SEC-29 | Low | App image shipped an unused Go binary with old Go runtime CVEs; no `apk upgrade` | Fixed |
| SEC-30 | Low | Registration form says when an email is already registered | **Open, accepted risk proposed** (R-01) |
| SEC-33 | Info | ZAP baseline: script-readable `XSRF-TOKEN` cookie, no HSTS on static files | Hardened |
| SEC-34 | Low | Access-denied (403), CSRF (419) and rate-limit (429) refusals were not logged | Fixed, retested |
| SEC-35 | High | (Review of SEC-06/08) The 30-minute "never sent" rule also failed transfers Shkeeper had accepted; a later SUCCESS was dropped; a 2xx without task id counted as failure | Fixed, retested |
| SEC-36 | Medium | (Review of SEC-11/12) No per-account code budget for turning 2FA off or creating recovery codes; enabling 2FA was an unthrottled password oracle | Fixed, retested |
| SEC-37 | Medium | (Review) Staff could refund, deliver, pay out or credit their own orders, sales, payouts and balance | Fixed, retested |
| SEC-38 | Low | (Review of SEC-23) Delivery checked the refund state outside the row lock | Fixed (guard test; race verified by review) |
| SEC-39 | Medium | (Found by the simulation suite) After SEC-04, PHP saw every request as plain HTTP behind the TLS terminator: signed download and email-verification links answered 403 | Fixed, retested |

Rejected after verification: **X-01** "licence keys added after approval skip
review" - moderators only ever see a key count, never key contents, so a review
cannot catch bad keys; bad keys are handled through disputes (by design).
**X-02** "dispute withdrawal races a staff refund" - no money is duplicated, the
case only ends as Withdrawn; informational.

---

## SEC-01 Host header controls the Shkeeper callback URL and emailed links (High) [A01, A05]

- **Component:** `PaymentService::startShkeeperPayment` (`route('webhooks.shkeeper')`), `ShkeeperPayoutService::callbackUrl`, `PayoutSettingsController` (confirmation link), `UserService` (email change link); nginx `server_name _`; no trusted-host handling.
- **Root cause:** absolute URLs built during a web request use the request's `Host` header.
- **Attack:** a verified buyer sends `POST /orders/{id}/pay` with `Host: evil.example`. Shkeeper is told to call `https://evil.example/webhooks/shkeeper`; its callback carries the API key header and an HMAC keyed with the API key (Shkeeper signs callbacks with it). With the secret, the attacker can sign `OVERPAID` callbacks and credit their balance, mark any order paid, or forge payout results. The same trick on `POST /seller/payout-settings` (attacker knows the seller's password) sends the seller a genuine email whose confirmation link leaks the token to the attacker.
- **Prerequisites:** a buyer account; no `SHKEEPER_CALLBACK_URL` set (default).
- **Fix:** `App\Http\Middleware\PinAppUrl` forces every generated URL to `APP_URL` (on by default outside local/testing). Additionally `X-Forwarded-Host` is never trusted (SEC-31).
- **Regression test:** `test_sec01_host_header_does_not_reach_the_shkeeper_callback_url` (shows the poisoned URL with pinning off, the APP_URL with it on).
- **Retest on the production image:** probe `HOST` (forged `Host` and `X-Forwarded-Host`: every absolute link stays `https://localhost:8443`).

## SEC-02 Moderators can refund; staff can decide their own disputes (High) [A01]

- **Component:** `Admin\DisputeController::resolve` (route needs only `disputes.manage`), `DisputeService::resolveWithRefund`, `ShkeeperPayoutService::sendRefund`.
- **Root cause:** the refund branch relied on the route ability, which moderators have; no check of who the staff member is.
- **Attack:** a moderator posts `action=refund&method=shkeeper&destination=<own wallet>` on any open dispute and the shop sends crypto from its wallet. Staff may also buy: a staff member could buy an item, dispute it and resolve the case in their own favour.
- **Fix:** refunds need `orders.manage` (admin, manager, finance) in the service; any resolution is refused when the staff member is the buyer or seller of the case; the dispute page shows moderators a hint instead of the refund form.
- **Regression test:** `DisputeTest::test_sec02_moderator_cannot_refund_and_parties_cannot_resolve`.

## SEC-03 Earnings payable while still disputable (High) [A06]

- **Component:** `PayoutService::balances` (hold computed from the sale ledger entry only), `config/shop.php` (`payout_hold_days`=7 vs `dispute_window_days`=14).
- **Root cause:** availability ignored delivery and the dispute window.
- **Attack:** a seller sells undelivered or junk items, withdraws on day 7, buyers dispute on days 8 to 14 (or later for late manual deliveries); refunds drive the seller ledger negative and the shop pays.
- **Fix:** a line's earning stays "on hold" until it is delivered, the payout hold since payment has passed and the dispute window since delivery has closed; the seller page explains the rule.
- **Regression tests:** `test_sec03_undelivered_items_and_open_dispute_windows_hold_earnings`, updated `RefundAndPayoutTest::test_payout_request_respects_hold_and_rejection_restores_balance` (held on day 8, payable on day 15).

## SEC-32 Vulnerable internet-facing web image (High) [A03]

- **Component:** `docker-compose.yml` service `web`: `nginxinc/nginx-unprivileged:1.27-alpine`, built 2025-06-23 on Alpine 3.21.3.
- **Root cause:** a superseded mainline tag with no rebuilds; nothing updated OS packages.
- **Evidence:** Trivy: 41 fixable HIGH/CRITICAL, including OpenSSL CVE-2026-31789 (critical) and further OpenSSL, libxml2, libexpat, zlib, musl, nghttp2 issues. nginx terminates client connections (and TLS in the documented setup).
- **Fix:** `docker/nginx/Dockerfile` builds `digital-goods-shop-web` from `1.30-alpine` (nginx 1.30.5, current stable) with `apk upgrade`; the gate scans it on every push.
- **Retest:** Trivy on the rebuilt web image (see summary).

## SEC-04 Spoofable client IP (Medium) [A01, A02]

- **Component:** `docker/nginx/default.conf` (passed client `X-Forwarded-*` headers to PHP), `TRUSTED_PROXIES=172.16.0.0/12` default.
- **Attack:** `curl -H 'X-Forwarded-For: 203.0.113.11' http://host:8080/login` - the session was recorded with the forged address (reproduced on the stack with the old config). This sidesteps per-IP rate limits and `SHKEEPER_WEBHOOK_ALLOWED_IPS`, and falsifies audit/session IPs. A first fix that trusted the private ranges in nginx was shown insufficient by the same probe: clients that Docker proxies arrive from the bridge gateway.
- **Fix:** the nginx config is a template: `X-Forwarded-For` is believed only from `NGINX_TRUSTED_PROXY` (default `127.0.0.1/32`, i.e. nobody); PHP receives only nginx's own `X-Forwarded-For/-Proto/-Port`.
- **Retest:** with the production default the forged address was ignored (`spoofed sessions: 0`); through the TLS front end a forged header is overwritten; rate limits hold while `X-Forwarded-For` rotates (probe `RATELIMIT`).

## SEC-31 Forged Host enables proxy trust (Medium) [A02]

- **Component:** framework `TrustProxies`: with no configured proxies it trusts all proxies when the Host ends in `.on-forge.com`/`.on-vapor.com`. `.env.example` ships `TRUSTED_PROXIES=` (empty).
- **Fix:** `App\Http\Middleware\TrustProxies` trusts nobody when the list is empty and never trusts `X-Forwarded-Host` or `-Prefix`.
- **Regression test:** `test_sec31_forged_host_does_not_turn_on_proxy_trust` (failed before: `203.0.113.7|shop.on-forge.com`).

## SEC-05 Refunded lines keep their goods (Medium) [A01, A06]

- **Component:** `DownloadController`, `OrderController::show`, `orders/show.blade.php`, `ReviewService::eligibleItem`.
- **Attack:** buy A and B, dispute A, receive a full refund for A; the order is `partially_refunded`, so A's downloads, keys and "verified buyer" review stayed available.
- **Fix:** `OrderItem::isFullyRefunded()` gates downloads (403), the order page (keys and links hidden), review eligibility and redelivery.
- **Regression test:** `test_sec05_fully_refunded_line_loses_downloads_and_review_rights`.

## SEC-06, SEC-07, SEC-08 Shkeeper transfers (Medium) [A06, A10]

- **Component:** `ShkeeperPayoutService`, `ShkeeperClient`, `Payout::externalId`.
- **Root causes:** one external id for every attempt and results applied by id alone (SEC-06); status checked on an unlocked model before the HTTP call and the transition after it (SEC-07); every client exception, including timeouts after Shkeeper accepted the transfer, marked the refund failed (SEC-08).
- **Attacks / failure scenarios:** a late FAIL for attempt 1 fails attempt 2 while it is in flight, staff send again and the seller is paid twice; two clicks or send + reject both proceed; a timed-out refund is retried with a new id and the buyer is refunded twice.
- **Fix:** per-attempt ids (`payout-{id}`, `payout-{id}-a2`, ...) with a new `attempt` column; stale results never change the payout and a stale SUCCESS alerts staff; the payout is claimed (`processing`) under a row lock before the transfer request; `ShkeeperException::$outcomeUnknown` (timeouts, dropped connections, 5xx) keeps payouts processing and refunds pending until the status poll settles them; Shkeeper's "unknown transfer" (404) after 30 minutes marks them failed; connection refused proves nothing was sent.
- **Regression tests:** `test_sec06_*`, `test_sec07_*`, three `test_sec08_*`.

## SEC-09 Seller approval overwrites commission (Medium) [A01]

- **Fix:** approve and reject act on pending applications only (row lock); `test_sec09_reapproving_a_seller_cannot_reset_the_commission_rate`.

## SEC-10, SEC-11, SEC-12, SEC-14 to SEC-18 Authentication (Medium/Low) [A07]

- **SEC-10:** signing out one session now rotates the remember token (this device keeps its own cookie). `test_sec10_*`.
- **SEC-11:** codes counted before the check; 5 per minute and 10 per hour per account; the 11th is refused even when correct, and the owner gets an `AccountSecurityMail` (`two_factor_locked`). At most about 240 guesses a day against 3 valid codes in 10^6. `test_sec11_*`.
- **SEC-12:** enabling 2FA requires the current password and is refused when 2FA is already on; new recovery codes need a current code; emails on every 2FA change. `test_sec12_*`, `AccountSecurityTest::test_enable_two_factor_and_sign_in_with_it`.
- **SEC-14:** `TwoFactorService::verify` checks and consumes under a user row lock.
- **SEC-15:** unknown emails are checked against a cached dummy hash. The first version made unknown emails slower (0.76 s vs 0.53 s median) because PHP statics do not survive FPM requests; found by the timing probe and fixed by caching the hash.
- **SEC-16, SEC-17, SEC-18:** status changes rotate the remember token; password changes delete open reset tokens; password change/reset and 2FA changes send `AccountSecurityMail`. `test_sec16_to_18_*`.

## SEC-13 Upload abuse (Medium) [A06]

- **Fix:** `throttle:uploads` (10/min, 60/h per user) on file, image and key uploads; at most `SHOP_MAX_PRODUCT_FILES` (20) active files per product; per-seller quota `SHOP_SELLER_STORAGE_MB` (5 GB); image decode cap 25 MP (about 100 MB in GD, within `memory_limit`). `test_sec13_*`.

## SEC-19 to SEC-25 Authorization and business-rule gaps (Low)

| Id | Fix | Test |
|---|---|---|
| SEC-19 | Cart update/remove and wishlist removal answer 404 for unlisted products not already in the cart/wishlist | `test_sec19_*` |
| SEC-20 | Only super admins sign out staff accounts | `test_sec20_*` |
| SEC-21 | `Product::visible()` / `isPurchasable()` require a seller who is not a buyer | `test_sec21_*` |
| SEC-22 | Staff downloads only for `clean`/`skipped` files (409 otherwise), link hidden | `test_sec22_*` |
| SEC-23 | Redelivery only for paid/delivered/partially refunded orders; `DeliveryService::deliver` skips refunded orders and lines | `test_sec23_*` |
| SEC-24 | With order limits set and no rate for the currency, the order is refused | `test_sec24_*` |
| SEC-25 | `not_regex:/[\r\n]/` on product title, display name, ticket subject, review title and user name | `test_sec25_*` |

## SEC-26, SEC-27, SEC-28, SEC-29, SEC-33 Hardening

- **SEC-26:** `enhance.js` moves parsed nodes with `importNode` instead of `innerHTML`.
- **SEC-27:** `withoutRedirecting()` on every Shkeeper request; `test_sec27_*`.
- **SEC-28:** actions pinned to SHAs, `permissions: contents: read`, Dependabot `cooldown`; Semgrep `p/github-actions` clean.
- **SEC-29:** `vendor/laravel/framework/bin` removed from the image, `apk upgrade` in the build.
- **SEC-33:** `PreventRequestForgery::$addHttpCookie = false`; HSTS on static files.

## SEC-34 Refused requests not logged (Low) [A09]

- **Component:** exception handling; Laravel does not report HTTP exceptions.
- **Impact:** someone probing ids or staff URLs (403), replaying forms (419) or hammering a rate limit (429) left no trace in the application log.
- **Fix:** `LogSecurityEvents` (global middleware) logs these responses at `notice` with route name, user id and IP; the path is not logged because some paths carry tokens.
- **Regression test:** `test_sec34_refused_requests_are_logged` (fails without the middleware).

## Independent review (SEC-35 to SEC-39)

A second reviewer (a separate agent with no part in the fixes) re-read the
whole fix diff adversarially. It confirmed most fixes and found the problems
below; the simulation suite found SEC-39. All were fixed in `34fd05c`; their
regression tests failed on `66726de` (evidence file, second section).

- **SEC-35 (High) [A06, A10]:** `ShkeeperPayoutService::reconcile` applied the "Shkeeper has no record after 30 minutes" rule to every processing transfer, and `payoutStatus` treated any 404 (a proxy page, a wrong base URL) as "unknown"; a payout Shkeeper had accepted could be failed and paid again. A SUCCESS arriving after such a failure was ignored. Fix: the rule needs both no task id and Shkeeper's own JSON 404; SUCCESS moves a failed payout to paid with a staff alert; a late SUCCESS for a failed refund alerts staff; a 2xx without task id is an unknown outcome; transfers unresolved for 24 hours alert staff. Tests: four `test_sec35_*`.
- **SEC-36 (Medium) [A07]:** the hourly code budget of SEC-11 only covered sign-in; `DELETE /account/two-factor` and `POST /account/two-factor/recovery-codes` allowed unlimited TOTP guessing per account (IPs rotate). `POST /account/two-factor` answered "password incorrect" without a per-user limit. Fix: `TwoFactorService::verifyLimited` shared by all three, `throttle:password-check` on enabling. Tests: two `test_sec36_*`.
- **SEC-37 (Medium) [A01]:** SEC-02 stopped staff deciding their own disputes, but refunds on the order page, staff delivery, payout approval/sending and balance adjustments had no such check. Fix: `App\Support\SelfDealing` in `Admin\OrderController`, `Admin\UserController`, `PayoutService::transition`, `ShkeeperPayoutService::sendPayout`. Test: `test_sec37_*`.
- **SEC-38 (Low) [A06]:** `DeliveryService::deliver` checked refunds before taking the item lock. Fix: re-check order status and line refund under the lock (refunds lock the same rows). Test: `test_sec38_*` (guard); the race itself is verified by review.
- **SEC-39 (Medium) [A02, A10]:** with the realip module nginx passes the client as `REMOTE_ADDR`, so PHP no longer treated nginx as a proxy and ignored the forwarded scheme; behind TLS every signed URL (downloads, email verification) failed with 403 (fail-closed, but it breaks buying). Found by simulation scenarios P3/P4/P5. Fix: nginx sets `HTTPS`/`REQUEST_SCHEME` itself. Retest: valid link 302, tampered link 403 on the stack; simulation run on `34fd05c`.

Smaller review notes also fixed: wishlist and product images use the catalogue's visibility rule, the seller filter skips sellers demoted to buyers, refused-request logs name the route pattern instead of the internal `generated::` name.

## SEC-30 Registration reveals registered emails (Low, open) [A07]

`POST /register` answers "An account with this email already exists". Rate
limited to 10 per hour per IP (now unspoofable, SEC-04). Fixing it means
changing sign-up to "check your email" for every attempt and emailing the
existing owner, a product decision. Proposed as accepted risk R-01 in
[security-residual-risks.md](security-residual-risks.md).
