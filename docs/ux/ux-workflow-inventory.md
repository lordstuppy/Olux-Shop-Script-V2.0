# UX Workflow Inventory

The inventory of the user interface, and the verification status of every critical journey. Findings (UX-nn) are in [ux-findings.md](ux-findings.md).

## The interface at a glance

| Aspect | What the application uses |
|---|---|
| Rendering | Server-rendered Blade pages (Laravel 13); every page and every flow works without JavaScript |
| Script | One optional file, `public/js/enhance.js` (29.4 KB, 7.7 KB gzip; 20.5 KB and 5.6 KB before this audit), no framework |
| Styles | One stylesheet, `public/css/app.css`, with colour tokens for light and dark |
| Fonts and assets | System fonts; no external fonts, scripts or styles (blocked by the Content Security Policy) |
| Components | Blade components `field`, `select`, `textarea`, `status`, `confirm` (new) and partials (flash messages, product card, admin and seller menus, column chart) |
| State | The server is the only source of truth: no client cache and no optimistic updates |
| Freshness | Polling `/live` every 10 s for signed-in users, version per page (see [ux-realtime-sync-report.md](ux-realtime-sync-report.md)) |
| Locale | English only, with every string translatable (`lang/en.json`, checked by a unit test) |
| Test tooling | PHPUnit, Playwright 1.56 with axe-core 4.10 (`tests/browser`), and the 101-scenario simulation (`tests/simulation`) |

## Routes and pages

| Area | Pages (GET) | Actions (POST, PUT, DELETE) |
|---|---|---|
| Public: home, catalogue, product, cart, legal pages | 14 | 5 |
| Sign-in, registration, recovery, two-factor | 8 | 5 |
| Signed-in account: orders, wallet, wishlist, notifications, tickets, disputes, settings | 22 | 39 |
| Seller | 10 | 16 |
| Staff (admin and four restricted roles) | 31 | 45 |
| **Total** | **85** | **105** |

**Crawled:** 73 distinct pages, every one that a role can open without first creating data. The crawl covered:

- 4 roles: guest, buyer, seller and admin;
- 6 viewports: 320, 375 and 768 px, 812 x 375 landscape, 1280 and 1920 px;
- both colour schemes.

**Roles:** guest, buyer, seller, and five staff roles: super admin, manager, moderator, finance and support. Each staff role only sees the menu entries its permissions allow (`StaffRolesTest`).

## Critical journeys

**Columns:**

- **No JS:** the journey works without JavaScript.
- **Mobile:** the journey was verified on a phone viewport.
- **Verified by:** the test or scenario that checks it.

**Test file names:**

- **ux:** `tests/browser/ux.spec.js`
- **nojs:** `tests/browser/nojs.spec.js`
- **live:** `tests/browser/live.spec.js`
- **a11y:** `tests/browser/a11y.spec.js`
- **sim Pn:** simulation phase n (`docs/SIMULATION.md`)
- **crawl:** the page crawl of this audit (rendering, layout and axe on every page)

### Buyer

| # | Goal | Steps | Expected outcome | Failure states handled | Feedback and confirmation | Permission | No JS | Mobile | Verified by |
|---|---|---|---|---|---|---|---|---|---|
| B1 | Find a product | Header search (suggestions) or catalogue filters, sort, pages | Result count announced; empty result with "Clear filters" | Invalid price (field error); no match (empty state) | Count in a status region; suggestions announced (UX-05) | None | Yes | Yes (filters in 2 columns, UX-08) | ux, crawl, sim P4 |
| B2 | Register and confirm the email | Form, then email link | Account created, verification required before buying | Taken email, weak password, bot timing, expired or tampered link | Errors at the fields and in the summary, which takes focus (UX-07) | Guest | Yes | Page | sim P3, PHPUnit |
| B3 | Sign in, sign out, recover the password | Login (2FA for staff); forgot password; reset link | Signed in at the page asked for (deep link) | Wrong password, lockout after 20 attempts, unknown email answered like a known one | Flash messages; password show/hide (keyboard, screen reader) | Guest | Yes | Page | ux (deep link, keyboard journey), nojs, sim P3 |
| B4 | Fill the cart | Add, change quantity, remove | Totals updated, cart badge | Sold out, quantity over stock, currency change | Flash per action | None | Yes | Yes | nojs, sim P1 |
| B5 | Pay with balance | Checkout, method, terms, place order | Paid order, delivery, notification | Balance too low (option disabled with the reason), prices changed, double submission | Busy indicator; idempotency key; second submit dropped (UX-06) | Verified email | Yes | Yes | nojs, ux (keyboard and phone journeys), sim P1 |
| B6 | Pay with crypto | Checkout, Shkeeper invoice, QR code, wait | The order page turns paid by itself, with a toast and a sound | Underpayment, overpayment, expiry, late or duplicate callbacks | Live update within one poll | Verified email | Yes (reload) | Page | live, nojs, sim P2 |
| B7 | Cancel an unpaid order | Cancel, confirm | Order cancelled | Already paid (refused) | Confirmation step naming the consequence (UX-02) | Owner | Yes | Page | ux, PHPUnit, sim P1 |
| B8 | Get the goods | Order page: download, key, invoice PDF | Files within the download limit | Limit reached, expired subscription | Remaining downloads shown | Owner, signed link | Yes | Page | nojs, sim P5 |
| B9 | Wallet and gift card | Redeem code | Balance credited | Wrong, used or expired code (rate-limited) | Flash; header balance live | Verified email | Yes | Page | sim P5, PHPUnit |
| B10 | Wishlist | Save, add to cart, remove | List kept | Product no longer purchasable (button hidden) | Flash | Signed in | Yes | Page | crawl, PHPUnit |
| B11 | Review a product | Rating and text on the product page | Review shown | Not a buyer, duplicate | Flash; leaving half-written text asks first (UX-12) | Buyer of the product | Yes | Page | sim P6, PHPUnit |
| B12 | Get support | Open a ticket, reply, close | Staff answer appears by itself | Session ended while typing (UX-03) | Live update; draft kept, offered instead of replaced | Signed in | Yes | Page | live, ux, sim P6 |
| B13 | Dispute a delivery | Report a problem, messages, withdraw | Refund, replacement or closure | Window over, duplicate dispute | Withdrawal confirmation (UX-02); live messages | Buyer of the item | Yes | Page | sim P9, PHPUnit |
| B14 | Follow notifications | Badge, centre, mark all read | Badge equal in all tabs | Mail server down (centre still works) | Toast for new ones, optional sound | Signed in | Yes (reload) | Page | live, sim P11 |
| B15 | Account security | Profile, email change, password, 2FA, sessions | Changes after confirmation (email link, password) | Wrong password, rate limits | Flash; recent password required for sensitive actions | Signed in | Yes | Page | sim P3, PHPUnit |
| B16 | Colour scheme | Footer setting | Applies on every page, survives reloads | - | Page reload | None | Yes | Page | ux |

### Seller

| # | Goal | Steps | Expected outcome | Failure states handled | Feedback and confirmation | Permission | No JS | Mobile | Verified by |
|---|---|---|---|---|---|---|---|---|---|
| S1 | Become a seller | Apply on `/sell`, wait for the decision | The page shows the decision by itself (UX-04) | Rejection with a reason | Live update; notification | Verified email | Yes | Page | ux (server), sim P4 |
| S2 | List a product | Draft, files, images, keys, submit for review | Staff decision visible in the list without a reload (UX-04) | Virus-scan failure, file limits, duplicate keys | Flash; live list | Seller | Yes | Page | ux, sim P4, PHPUnit |
| S3 | Maintain a product | Edit, pause or resume, stop delivering a file, remove an image | Changes saved | Concurrent edits: the last save wins (single owner) | Confirmation for irreversible removals (UX-02) | Owner | Yes | Page | ux (server), PHPUnit |
| S4 | Deliver manually | Sales page, delivery text | Buyer notified | Already delivered, disputed | Live sales page | Seller of the item | Yes | Page | sim P5 |
| S5 | Get paid | Request a payout; change the address (password, email link, cooling-off) | Payout requested, paid by staff | Below minimum, held earnings, blocked period | Flash; live payouts page | Seller | Yes | Page | sim P8, PHPUnit |
| S6 | Answer reviews | Reply to a review | Reply shown under the review | Hidden review | Flash | Seller of the product | Yes | Page | sim P11 |

### Staff

| # | Goal | Steps | Expected outcome | Failure states handled | Feedback and confirmation | Permission | No JS | Mobile | Verified by |
|---|---|---|---|---|---|---|---|---|---|
| A1 | Watch the shop | Dashboard, health, reconciliation | KPIs and alerts current | Failed jobs, failed webhooks | Live dashboard; new orders appear in the order list by themselves | `staff.dashboard` | Yes (reload) | Page | ux, live (server), crawl |
| A2 | Moderate products and sellers | Approve, reject with a reason, disable | Seller sees it live | Status changed meanwhile ("changed status a moment ago") | Reason required; flash | `products.manage`, `sellers.manage` | Yes | Page | ux, sim P7, StaffRolesTest |
| A3 | Handle orders | Cancel, refund, redeliver, reset downloads, invoice | Buyer sees it live | Over-refund, not paid | Cancel confirmation (UX-02); refund form sent once | `orders.manage` | Yes | Page | sim P7, PHPUnit |
| A4 | Pay sellers | Approve, send via Shkeeper or mark paid, reject with a reason | Seller payouts page live | Gateway errors, double send | Send form sent once; reason required | `payouts.manage` | Yes | Page | sim P8 |
| A5 | Manage users | Role, suspend, balance adjustment, sign out sessions | Takes effect at once (the session ends, UX-03) | Self-dealing refused | Recent password required | `users.manage`, `roles.manage`, `balances.adjust` | Yes | Page | StaffRolesTest, sim P7 |
| A6 | Support and disputes | Tickets, internal notes, resolve disputes | Customer sees visible replies only | Internal notes never change the customer's page | Live pages | `tickets.manage`, `disputes.manage` | Yes | Page | live, ux, PHPUnit |
| A7 | Configure the shop | Categories, coupons, gift cards, rates, commission, announcements, email templates, gateway, settings | Saved and audited | Category with products cannot be deleted | Confirmation for delete and reset (UX-02) | Per section | Yes | Page | ux, crawl, PHPUnit |
| A8 | Exports and audit | CSV export, audit log, reports | Download, filters | Large ranges (keyset paging) | Busy indicator (not on downloads) | `orders.export`, `audit.view` | Yes | Page | crawl, PHPUnit |

**"Page" in the Mobile column** means the following:

- **Checked on every page at 320, 375 and 768 px and in landscape:** rendering, overflow, touch-target sizes and the axe scan.
- **Run end to end on a phone viewport:** only the purchase (B5).
- **Account deletion:** not offered, by a recorded product decision (`docs/SIMULATION.md`), so not a journey here.

## Duplicates, unnecessary steps, refresh dependence

| Observation | Result |
|---|---|
| Two search fields on the catalogue page (header and filter) | Kept on purpose. The header searches everything; the filter field keeps the other filters. Both carry the same value (UX-20, Info). |
| Pages that needed a manual reload | Before the audit: the seller product list and edit page, and the application page (UX-04); the header navigation after a role change (UX-15, open). The other pages that show other people's changes update by themselves. |
| Irreversible actions in one click | 10 actions, fixed with a confirmation step (UX-02). |
| Repeated data entry | Input stays after recoverable errors (passwords excepted). After a session end, typed text can be kept and sent (UX-03). |
