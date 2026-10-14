# End-to-End Test Report

Customer, seller and staff journeys through the real application.

## Layers that ran

| Layer | What it is |
|---|---|
| **Simulation** | Python HTTP clients with cookies and no JavaScript. Each simulated person has their own source address. They drive the production image through nginx and TLS, with the queue worker, scheduler, PostgreSQL 15, Mailpit and the Shkeeper mock. After each scenario, PostgreSQL, Mailpit and the app log are checked. |
| **Browser suite** | Chromium through Playwright: journeys with and without JavaScript, live updates, keyboard and phone. |
| **PHPUnit** | Feature tests on PostgreSQL that post the real forms (CSRF, middleware, validation). |

Statuses are from the final runs on the candidate ([functional-test-summary.md](functional-test-summary.md#final-runs)); every row is in [scenario-matrix.md](scenario-matrix.md).

**Cross-layer verification:** each simulation scenario compares what the page said with the stored data (orders, payments, ledger, balances, keys), the emails in Mailpit and the audit log. Details are in [data-consistency-report.md](data-consistency-report.md).

## Customer journeys

| Journey | Covered by |
|---|---|
| Register, verify the email (valid, reused, expired, tampered link), sign in, sign out | P3 "Registration", "Verification link", "Login", "Sessions"; P12 FC-03 (same email five times at once) |
| Password reset; lockout and unlock | P3 "Password reset", "Lockout after 20 rapid failed logins"; LoginLockTest |
| Profile, password, email change, 2FA with recovery codes, two devices | P3 "Profile update", "Password change", "Email change", "2FA", "Two devices at once" |
| Search, filter, sort, view products | CatalogFiltersTest; browser no-JS "guest pages"; ux.spec search by keyboard; load profiles (catalogue, search) |
| Cart, saved cart after sign-in, abandoned cart revalidated | P1 "Abandoned cart"; SavedCartTest |
| Checkout and pay with crypto: single, multi-seller, multiple units, coupons | P1 (single, two items, three sellers with a failed delivery, five units, coupon); browser "buy with crypto via the Shkeeper mock without JavaScript" |
| Checkout with the balance; insufficient balance | P1 "Insufficient balance"; browser "buy with balance without JavaScript"; P12 FC-01 |
| Under- and overpayment, expiry and "Buy again" | P2; ReorderTest |
| Downloads (limits, expiry, tampered links), licence keys, subscriptions | P9 "Download limits", "Subscription access over time"; P12 FC-10 |
| Manual delivery received | P9 "Manual delivery" |
| Reviews, wishlist, tickets, disputes (open, reply, withdraw, outcome) | P9 "Reviews", "Wishlist", "Support tickets"; P4 "Dispute response"; P5 "Disputes resolved both ways"; P10 "Dispute confirmation"; P12 FC-09 |
| Notifications: centre, badge, live updates, other tabs, connection loss | P11; browser live.spec |
| Recovering from validation errors and interrupted operations | browser ux.spec (error summary focus, unsaved text, session end, double submit); P12 FR-01 |
| On a phone and by keyboard alone | browser ux.spec ("purchase on a phone", "with the keyboard alone"); a11y.spec (light, dark, phone) |

## Seller journeys

| Journey | Covered by |
|---|---|
| Apply, get approved or rejected | P4 "Seller onboarding"; P5 "Approve and reject seller applications" |
| Create and edit products: file scan, image re-encoding, licence keys, review, a price change sending the product back to review | P4 "Product create and edit"; ReviewFindingsTest; FD-04 and FD-07 tests |
| Pause and resume | P4 "Deactivate and reactivate"; SellerPauseTest |
| Sales and ledger views; payout requests; payout address change with the waiting period | P4 "Sales and ledger views", "Payout request, approve and reject"; P9 "Payout address change"; P12 FC-11 |
| Reply to reviews; email preferences | P11 "Seller replies to a review"; P10 "Seller email preferences" |

## Staff journeys

| Journey | Covered by |
|---|---|
| Role matrix: 25 pages x 5 roles; destructive actions limited | P5 "Each staff role sees only what it may", "Manager is denied ..."; StaffRolesTest |
| Find users; suspend and restore buyers and sellers | P5 "Suspend a buyer", "Suspend a seller hides their products"; P12 FC-12 |
| Change roles during an active session | browser ux.spec "a role change shows up in the menus"; StaffRolesTest |
| Orders: filters, refunds (full, partial, crypto), cancel, deliver on behalf, redeliver | P5 "Order filters", "Full and partial refunds"; P9 "Crypto refund", "Manual delivery"; P12 FC-07, FS-01 |
| Payouts: approve, reject, mark paid, send through Shkeeper | P4; P5 "Payouts via the gateway"; P12 FC-06, FF-03 |
| Moderation: products, reviews, categories, announcements | P5 "Approve and reject products"; P9 "Reviews", "Categories", "Announcements"; P12 FS-02 |
| Bulk operations | P9 "Bulk actions"; BulkOperationsTest |
| Configuration: settings, commission, gateway, email templates, exchange rates, coupons, gift cards | P5 "Gateway config and logs", "Email templates"; P9 "Commission settings screens", "Currencies", "Gift cards"; P9 "Balance adjustments by finance" |
| Reports, reconciliation, audit log, CSV exports | P5 "Audit log search", "CSV export"; P9 "Reporting pages agree with the database"; F11 large-data exports |
| Failed jobs, webhooks and alerts | P10 "A failed email", "Staff alert when Shkeeper stops answering", "Needs-attention digest"; WebhookRetryTest; SystemHealthTest |

## Not covered by end-to-end runs

- **Other browsers and devices:** Safari, Firefox and real phones (G-10); screen readers (docs/ux U8).
- **A real payment provider:** the real Shkeeper (G-01).
- **By decision, not offered:** guest checkout, account self-deletion and seller ratings (shown as NOT APPLICABLE in the matrix).
