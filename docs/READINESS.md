# Go-live readiness check

Checked on 2026-10-11 against the pre-launch checklist (notifications, user
experience, real-time updates, responsive design, performance, code
cleanup, missing features). Each item is marked:

- **Verified**: already in place; checked in the code or in a browser.
- **Added**: missing or incomplete; built in this pass, with tests.
- **Fixed**: a defect found during the check and corrected.
- **Declined**: deliberately not built; the reason is given.
- **Open**: needs something this environment cannot provide.

How it was checked:

- PHPUnit: 252 tests. They also run with deprecations, notices and
  warnings treated as failures, and with lazy loading forbidden, which
  catches N+1 queries.
- A Chromium pass over 51 pages for guest, buyer, seller and super admin,
  each at 320, 375, 768, 1024, 1440 and 1920 px. It checks horizontal
  overflow, touch targets, console errors and the password toggle, and
  takes screenshots in light, dark and print mode.
- The production image (PHP-FPM behind nginx) behind TLS, checked with
  curl for compression and cache headers.
- The 88-scenario simulation (docs/SIMULATION.md) on the rebuilt image.

## 1. Notifications

All messages are email. They are queued, retried 5 times over about
20 minutes, and kept in the failed jobs list after that.

### Buyer

| Event | Status |
|---|---|
| Registration, email verification | Verified |
| Password reset | Verified |
| Order placed (crypto invoice) | Verified |
| Order paid | Verified. Sent once automatic delivery has run; a delivery failure is retried by `shop:recover-stuck-orders` and alerts staff. |
| Order delivered (manual delivery or replacement) | Verified. Instant items are listed in the paid mail. |
| Refund (balance, manual, crypto once confirmed) | Verified |
| Dispute opened: confirmation to the buyer | **Added** |
| Dispute resolved, escalated, new message | Verified |
| Support ticket reply | Verified |
| Seller response to a review | Not possible: sellers cannot reply to reviews (see section 8) |

### Seller

| Event | Status |
|---|---|
| New sale | Verified; can now be turned off (see Preferences) |
| Dispute opened by a buyer | Verified |
| Payout paid or rejected | Verified |
| Payout approved | Declined. Approval is an internal step right before sending, so the seller is mailed when the money is sent or the request is rejected. |
| Product approved or rejected | Verified |
| Product disabled by the virus scan | **Added** |
| Review received (any rating, so negative ones too) | **Added**; can be turned off |

### Staff

Alerts go to `SHOP_STAFF_ALERT_EMAIL`, which defaults to the support
address. Repeating events are throttled. The full table is in
docs/OPERATIONS.md, "Staff alerts".

| Event | Status |
|---|---|
| New seller application, product awaiting review, payout request | **Added**: 15-minute digest, sent only when something new arrived |
| Dispute opened or escalated | Verified (immediate) |
| New ticket, customer reply on a ticket | **Added** (digest). Tickets have no separate escalation step. |
| Failed webhook signature or disallowed sender | **Added**: immediate, at most hourly |
| Gateway outage | **Added**: new health check "Shkeeper API errors (last 15 minutes)", reported by `shop:health-alerts` |
| Unusual login activity | **Added**: a lock on a staff account alerts at once; all account locks appear in the digest. New-device sign-ins still mail the account owner. |
| Also added | A job that used up its retries, a failed Shkeeper payout or crypto refund, an infected upload, a ledger reconciliation mismatch, any critical health check |

### Channels, preferences, reliability

- **Channels:** email only.
  - **In-app notification centre: Declined.** Every event already appears
    where the person works:
    - buyers: order, dispute and ticket pages;
    - sellers: their dashboard;
    - staff: the "Needs attention" counts on the dashboard.

    A second inbox would duplicate these pages. Listed in section 8 as a
    possible future feature.
  - **Browser push: Declined.** It needs JavaScript, a service worker and
    VAPID keys, for little gain over email.
- **Preferences: Added.**
  - Sellers can turn off per-sale and per-review emails.
  - Emails about orders, payments, refunds, disputes, tickets and account
    security cannot be turned off; they are transactional.
  - Email is the only channel, so "honoured on every channel" holds.
- **Failed email does not disappear: Added.**
  - Mail is retried, then lands in `failed_jobs` and is logged.
  - Staff get an alert, at most once per mail type every 30 minutes.
  - The dashboard shows the count.
  - `/admin/health` lists the failed jobs with Retry, Retry all and Delete
    (super admin and manager).
  - Health alerts are sent directly, not through the queue, so they still
    arrive when the queue worker is down.

Defect found while building this: in Laravel, `send()` on a mailable that
implements `ShouldQueue` still queues it. Health alerts would therefore
never arrive while the queue worker was down. They now use `sendNow()`,
and a test covers it.

## 2. User experience

| Item | Status |
|---|---|
| Password show/hide (eye) | **Added** to every password field as a script enhancement. Without JavaScript the field stays masked. A server-side toggle is declined: it would send the password back inside an HTML page. The field is masked again before submit, and spellcheck and autocorrect are off while it is visible. Checked in Chromium. |
| Dark mode | **Added**. Follows the device setting. A footer setting (Same as device, Light, Dark) overrides it; it works without JavaScript and is stored in a cookie for 400 days per browser, not per account. Covers every page, since all pages share the two layouts. |
| Contrast | **Added**: every text and background pair passes WCAG AA (4.5:1) in both schemes, computed rather than eyeballed. **Fixed**: form-field borders were 1.6:1 in the existing light scheme; they are now at least 3:1 (WCAG 1.4.11). |
| Inline form errors | Verified. Every field component shows its error next to the field and links it with `aria-describedby`; a summary also appears at the top. |
| Loading states | **Added**: the submit button shows a spinner after 200 ms (script). Without JavaScript the browser's own loading indicator does this. |
| Empty states | Verified on every list. **Fixed**: the admin user list had none; wishlist and sales now link to a next step. |
| Error pages | Verified: 401, 403, 404, 405, 419, 429, 500 and 503 (maintenance), plus a database-outage page. All are branded, show the request id and link back to the shop. |

## 3. Real-time updates

**Declined: WebSocket push.** The shop is server-rendered and built to work
without JavaScript. A WebSocket server, such as Laravel Reverb, would add:

- a long-running service and a public port;
- channel authorisation;
- a JavaScript client;
- reconnection handling.

It would serve only users who have JavaScript, so it would bring a lot of
new parts that can break for a convenience.

What exists instead:

- The payment page and the payment-result page reload every 30 seconds
  with `<meta http-equiv="refresh">`, without JavaScript. A buyer waiting
  for a crypto payment sees it change from pending to paid to delivered
  without doing anything.
- Every page shows current data when loaded and after every form submit,
  JavaScript or not.
- Staff dashboards are current when opened. The 15-minute digest and the
  immediate alerts bring staff back when something needs them.

If live updates become a requirement, the cheapest step is a small polling
script on the order and dashboard pages, not WebSockets. Listed in section 8.

## 4. Responsive design

The Chromium pass covered 51 pages at 6 widths, for guest, buyer, seller
and super admin. Order-detail pages were included after a simulation run
had created orders.

| Item | Status |
|---|---|
| No horizontal scrolling | **Fixed**. Before: 6 page and width combinations overflowed at 320 and 375 px. Visually hidden table labels escaped the table's scroll box; fact lists, fieldsets and health hints did not wrap. After: 0. |
| Touch targets of 44 x 44 px | **Fixed**. On touch screens, buttons, inputs, selects, navigation and pagination links are at least 44 px tall. Checkboxes are 22 px inside 44 px labels. The remaining exceptions are inline text links, such as breadcrumbs, which WCAG 2.5.8 exempts. |
| Navigation | Verified and improved. The header wraps. Section menus (admin, seller) become one swipeable row on narrow screens, scrolled to the current page. Skip link and visible focus outlines exist; nothing is fixed on screen over the content. |
| Tables | Verified. Every table sits in a horizontally scrollable box. Card layouts were not built: sideways scrolling keeps column comparison possible and is the checklist's accepted alternative. |
| `inputmode` and `autocomplete` | Verified: email, current-password, new-password, one-time-code and decimal amounts. |
| Images | Verified: WebP, with width and height set to avoid layout shift. **Added**: `srcset` and `sizes` on the product page, plus dimensions on thumbnails. |
| Print | **Added**: print styles hide navigation, footer, forms and buttons and force black on white (checked in a screenshot). Invoices are PDF files. |

## 5. Performance

| Item | Status |
|---|---|
| CSS and JS size | One hand-written stylesheet (15 KB, 4.3 KB gzipped) and one deferred script (7 KB), both needed on every page (header search, password fields). Declined: minification and unused-CSS removal would add a build step to save about 1 KB after gzip. |
| Image optimisation | Verified: uploads are re-encoded to WebP at 1600 px and 480 px. Originals are not kept, on purpose: it removes EXIF data such as GPS positions. |
| Database indexes | **Fixed**. `order_items.order_id` had no index, so every order page and delivery read the whole `order_items` table. 18 other foreign keys the app queries were also unindexed. A test now fails when a new foreign key has no index, unless it is listed as a deliberate exception. |
| N+1 queries | **Added**: outside production, touching a relation that was not loaded, on a model from a multi-row query, throws. Two N+1 cases were **Fixed**: the order page and bulk product moderation. |
| Pagination | Verified: lists paginate, exports stream, bulk actions are capped at 500 rows. |
| Caching | Verified: settings, email templates and announcements are cached and cleared on change. Prices and stock are never cached; checkout reads and locks them in the database. |
| Response-time budgets | Open. Already known from phase 7: data stayed correct under load, but p95 latency exceeded the budget on the 4-core test machine (no-load pages 55-75 ms). Needs a load test on production-like hardware. |
| Compression | **Added**: gzip for CSS, JS and SVG. **Fixed** during the check: compression did not apply behind a TLS terminator that forwards HTTP/1.0. HTML is deliberately not compressed, against BREACH (see docs/OPERATIONS.md). |
| HTTP caching | **Added**: CSS and JS URLs carry a content hash and are cached for one year (immutable). HTML is `private, must-revalidate`. Product images are cached for a year while the product is listed, and `no-store` otherwise. |
| Third-party requests | Verified: none. No CDN, font, analytics or external script. |

## 6. Code cleanup

| Item | Status |
|---|---|
| History in comments | Verified: no changelog-style comments in the code. One borderline comment was reworded. README and SECURITY.md still mention the removed legacy script on purpose: they explain why the code was rebuilt and warn that the old code leaked credentials. |
| Dead code | **Fixed**: removed six unused methods. No TODO, FIXME or commented-out code apart from Laravel's stock config files. |
| Dependencies | Every package is used. `laravel/tinker` moved to dev dependencies, so the production image no longer ships an interactive PHP shell. `composer audit`: no advisories, no abandoned packages. The unused setting `SHOP_SECURITY_EMAIL` is now used by **Added** `/.well-known/security.txt` (RFC 9116). |
| Deprecated usage | Verified: the 252 tests pass with deprecations, notices and warnings treated as failures. |
| ASCII only | **Fixed**: docs/SIMULATION.md (dashes, multiplication sign, ellipsis) and the adversarial test data (now escape sequences, same bytes at run time). `scripts/check-ascii.sh` passes. |
| Documentation | **Updated**: README feature list, docs/OPERATIONS.md (staff alerts, email reliability, failed-job retry, caching and compression, new scheduled commands), `.env.example`. |
| Naming and member order | Declined: Pint enforces the code style. Re-sorting members in every class (constants, properties, constructor, then public, protected, private) would touch most files without changing behaviour; spot checks found no naming problems. |

## 7. Results

- PHPUnit: 252 tests pass. Pint, the ASCII check and the translation
  catalogue check pass.
- Chromium: 0 horizontal overflows on 51 pages x 6 widths; no console
  errors; password toggle works with JavaScript and the field stays masked
  without it; dark and print modes checked in screenshots.
- Simulation on the rebuilt production image: see docs/SIMULATION.md,
  "Readiness run".

## 8. Missing major features and improvements

Ordered by how much they matter for going live.

1. **VAT and sales tax.** Not handled at all. For digital goods sold to EU
   consumers, VAT is due at the buyer's country rate, and a marketplace
   that collects payment is often treated as the supplier. The shop needs:
   - the buyer's country, with evidence;
   - tax-inclusive or tax-added prices;
   - VAT on invoices;
   - an OSS report.

   Get legal advice before launch in the EU or the UK.
2. **Card or bank payments.** Only crypto through Shkeeper and the shop
   balance. Most buyers expect cards, and adding a provider means
   refunds, chargebacks and disputes per provider.
3. **Personal data export (GDPR Art. 15 and 20).** No self-service export;
   requests must be handled by staff from the admin pages and the
   database. Account deletion was ruled out earlier, but erasure requests
   still need a written procedure.
4. **Seller replies to reviews**, with a notification to the reviewer.
5. **Licence tiers or variants per product** (personal, commercial,
   extended). Today each tier must be a separate product.
6. **Telling buyers about product updates.** Sellers can replace files, but
   buyers are not notified that a new version is available.
7. **In-app notification centre** (an unread badge and a list), if the
   email-only approach turns out to be insufficient.
8. **Live updates on order and dashboard pages.** A small polling script
   would be the first step (see section 3).
9. **Seller storefront page** with a profile, description and all products.
   Today the catalogue filter by seller does this job.
10. **Seller self-service:** bulk product import (CSV) and per-product
    sales analytics.
11. **Abandoned-cart and wishlist price-drop emails** (opt-in). Saved carts
    and wishlists already store what is needed.
12. **More languages.** The UI is fully translatable, but only English
    exists.
13. **Stronger bot protection on registration** if honeypot and rate limits
    prove insufficient: a self-hosted proof-of-work challenge, which keeps
    the no-third-party rule.
14. **Affiliate or referral programme.**

Still open from the earlier simulation (docs/SIMULATION.md):

- load test on production-like hardware;
- tests against a real Shkeeper instance, real ClamAV and real mail
  delivery (SPF, DKIM, DMARC);
- a security test by a person;
- checks on real phones;
- sign-off by a second engineer.
