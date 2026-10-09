# Live test

`tests/live/run.sh` runs the shop as it would run in production and drives
it like real users would, through HTTP forms only (no JavaScript, no direct
service calls). It was used to validate the release on 2026-10-09; the run
recorded below passed all 22 scenarios (the last four were added with
the commission, dispute, gateway, template, bulk, dashboard and health
features).

## Environment

| Part | What runs |
|---|---|
| Code | `git archive HEAD` into a separate directory, `composer install --no-dev --classmap-authoritative` |
| Web | nginx 1.27 with TLS (self-signed, port 8443) in front of the app; a plain HTTP listener (8080) for Shkeeper callbacks |
| App | `APP_ENV=production`, `APP_DEBUG=false`, config, route and view caches, encrypted `Secure` sessions, Argon2id, `TRUSTED_PROXIES=127.0.0.1`. PHP's built-in server (8 workers) stands in for PHP-FPM |
| Workers | `queue:work` and `schedule:work`, as in `docker-compose.yml` |
| Database | PostgreSQL 15 (container) |
| Payments | `docker/shkeeper-mock` with payout API and signed callbacks |
| Virus scan | `SHOP_VIRUS_SCAN=required` against a minimal clamd stand-in that flags the EICAR test file (real ClamAV needs signature downloads) |
| Mail | SMTP to a local sink that stores every message; links are followed from the stored mails |

The first administrator is created with `php artisan shop:create-admin`
(interactive prompt); everything else is created through the web forms.

## Scenarios

1. **Infrastructure:**
   - TLS and security headers (HSTS, CSP, frame, referrer and permissions policies, nosniff); no `X-Powered-By`.
   - `Secure`, `HttpOnly` and `SameSite=Lax` cookies; https URLs behind the proxy.
   - Health endpoint detail hidden without the token; metrics need the bearer token.
   - The 404 page shows the request id; dotfiles and PHP files are not served.
   - CSRF returns 419; unsigned or badly signed webhooks return 401.
2. **Admin onboarding:** the CLI-created admin cannot open `/admin` until 2FA is on. Recovery codes are shown once.
3. **Admin setup:** categories and runtime settings.
4. **Buyer registration:**
   - Registration triggers an email verification link.
   - The honeypot and the time trap reject bots.
5. **Seller onboarding:**
   - The seller registers, verifies their email and applies.
   - Staff approve the application, and the decision is emailed.
6. **Catalogue:**
   - A file upload is scanned clean in the queue, and the screenshot is re-encoded to WebP.
   - An EICAR upload is removed and its product disabled; staff cannot approve it.
   - A licence-key product and a manual subscription product.
   - Search and the product pages.
7. **Crypto purchase:**
   - Checkout, a Shkeeper invoice, and the payment page with address and QR code.
   - The signed webhook arrives and the queued delivery runs.
   - The signed download works, and the same link fails for another user.
   - PDF invoice; order, sale and payment emails.
   - A replayed webhook does not pay twice.
8. **Partial payment then overpayment:**
   - The buyer sees what was received and what is still due.
   - The overpayment is credited to the balance, and the licence key is delivered once.
9. **Gift card, coupon and balance:**
   - A gift card is created and redeemed; it cannot be redeemed twice.
   - Coupon with a per-buyer limit.
   - Balance checkout of a manual product; the seller delivers it, and subscription access is set to 30 days.
10. **Reviews and wishlist:** a verified-buyer review and its moderation; wishlist add and remove.
11. **Support:**
    - The ticket reply is emailed to the buyer.
    - The internal note is neither shown to the buyer nor emailed.
    - Assignment and closing.
12. **Refunds:**
    - Balance and manual refunds.
    - An on-chain refund through the Shkeeper payout API and its callback.
    - An over-refund is rejected while a refund is in flight.
13. **Payouts:**
    - The payout address change needs password confirmation and an emailed link; the old and new owners are notified.
    - Request, approval, then sending via Shkeeper, with the callback marking it paid.
    - The ledger reconciliation passes.
14. **Account security:**
    - TOTP login and single-use recovery codes.
    - New-device email; ending other sessions.
    - Email change confirmed by the new address with a notice to the old one.
    - A password change signs out other sessions.
15. **Admin tour:**
    - Every admin page loads, reports draw SVG charts, and every CSV export downloads.
    - Audit log filter; balance adjustment; announcement; exchange rate and EUR display.
    - A support-role account is limited to its permissions, including a refused refund POST.
16. **Scheduler:**
    - An unpaid order expires and its stock comes back.
    - A payment whose webhook never arrived (nginx stopped during the callback) is picked up by `shop:reconcile-payments`.
17. **Commission and gateway:**
    - A seller's own rate outranks the category rate. After the seller rate is cleared, the category rate applies and is frozen on the next order line.
    - Balance payments switched off on the gateway page disappear from checkout and come back when switched on.
    - The gateway log shows rejected signatures and API calls.
18. **Dispute:**
    - A buyer opens a case on a licence-key line, and the seller is emailed.
    - The seller's payout page shows the held earnings. The seller's answer hands the case to staff and emails the buyer.
    - A super admin sends a replacement, and the buyer sees a new key.
19. **Email template:** an edited "order paid" text arrives over SMTP for the next crypto purchase, then is reset.
20. **Bulk actions, dashboard and health:**
    - Bulk CSV export, then a bulk disable and re-approve of a product.
    - The dashboard KPIs, charts, rankings and gateway health are shown.
    - The system health page reports the real queue worker, scheduler, clamd, database and last webhook as OK.
21. **Backup:**
    - `scripts/backup.sh` checksums verify.
    - The archive holds product files, images and invoices.
    - The dump restores into a scratch database with identical row counts.
22. **Final health:**
    - No error-level log entries.
    - No failed jobs or webhook events.
    - The ledger reconciles.
    - `shop_exceptions_total` is 0, with no 5xx responses (464 requests).

## Defects found and fixed

| Defect | Impact | Fix |
|---|---|---|
| Admin settings were applied during `config:cache` | Stored settings were baked into `bootstrap/cache/config.php` on every container start; the settings page then reported "no change" and wrote no audit entry | Settings are not applied while caching config |
| Queue workers kept settings from their start | A new commission or download limit did not reach webhook processing or delivery jobs until a restart | Settings are re-applied before every job |
| `shop_exceptions_total` never increased | The database cache store does not create a missing key on increment, so the alerting metric stayed at 0 | Key is created first |
| User-facing errors were reported as exceptions | A wrong 2FA code or a sold-out product logged a stack trace and (once the metric worked) would page operators | `UserFacingException` is not reported |
| Instant products with nothing to deliver could be approved | After an infected upload was removed, staff could still activate the product and buyers could pay for it | Approval and submission both require a file or licence keys |
| No feedback after a partial crypto payment | The payment page still asked for the full amount | Shows received, still due and the remaining crypto amount |
| Password confirmation was rate-limited per IP | All signed-in users behind one address shared five attempts a minute for sensitive actions | Per-account limiter |
| Two tests reached out to the network | They only passed through the Shkeeper fallback | `Http::preventStrayRequests()` in every test |

Each fix has a regression test that fails without it.

The second round (new features) found no defects in the shop; three
failures were wrong assumptions in the test script itself (the
reconciliation grace period, commission precedence, and the buyer's spent
balance), fixed in `tests/live/live_test.py`.

## Limitations

- PHP-FPM could not be installed offline here, so PHP's built-in server
  served the app behind nginx. The Docker image (PHP-FPM) could not be built
  in this sandbox because package mirrors were blocked; build and smoke-test
  it in your CI or on the staging host.
- ClamAV ran as a protocol-compatible stand-in. On staging, let the real
  `clamav` service download its signatures and upload the EICAR file once.
- Shkeeper was the mock. Before going live, make one small real payment and
  one payout on a test coin or with a tiny amount.
