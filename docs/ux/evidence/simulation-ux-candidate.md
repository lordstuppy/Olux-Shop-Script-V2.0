# Simulation summary (UX candidate: image built from 974e3f5, app aa9c31e6d589, web 8a5f73838e2d; run 20261013-000543)

Backend: compose. Runs: 1.

| Scenario | Results | Consecutive passes |
|---|---|---|
| P1 Single item purchase (crypto) | PASS | 1 |
| P1 Two items from the same vendor | PASS | 1 |
| P1 Three vendors; one delivery fails without blocking the others | PASS | 1 |
| P1 Five units of one product | PASS | 1 |
| P1 Mixed stock states in the cart | PASS | 1 |
| P1 Coupon on the subtotal; commission on the discounted amount | PASS | 1 |
| P1 Insufficient balance: blocked, no partial order | PASS | 1 |
| P1 Abandoned cart, return later: cart kept, prices revalidated, sold-out items flagged | PASS | 1 |
| P1 Guest checkout | PASS | 1 |
| P1 Concurrent purchase of the last unit | PASS | 1 |
| P2 Successful payment | PASS | 1 |
| P2 Underpayment (90%) | PASS | 1 |
| P2 Overpayment (110%) | PASS | 1 |
| P2 Expired quote and expired order, retry possible | PASS | 1 |
| P2 Duplicate callback delivered five times | PASS | 1 |
| P2 Out-of-order callbacks | PASS | 1 |
| P2 Callback delayed 30 minutes | PASS | 1 |
| P2 Forged callbacks are rejected (401) and audited | PASS | 1 |
| P2 Malformed webhook bodies return 400, never 500 | PASS | 1 |
| P2 Gateway timeout on invoice creation | PASS | 1 |
| P2 Gateway timeout during callback processing | PASS | 1 |
| P3 Registration: valid, invalid and duplicate emails | PASS | 1 |
| P3 Verification link: valid, already used, expired, tampered | PASS | 1 |
| P3 Login: correct password, wrong password, unknown email | PASS | 1 |
| P3 Lockout after 20 rapid failed logins | PASS | 1 |
| P3 Password reset | PASS | 1 |
| P3 Password change | PASS | 1 |
| P3 Email change | PASS | 1 |
| P3 2FA: enable, sign in, recovery codes, disable | PASS | 1 |
| P3 Profile update | PASS | 1 |
| P3 Account deletion | PASS | 1 |
| P3 Sessions: logout, expiry, fixation resistance | PASS | 1 |
| P3 Two devices at once | PASS | 1 |
| P4 Seller onboarding | PASS | 1 |
| P4 Product create and edit (file scan, image, keys, review) | PASS | 1 |
| P4 Deactivate and reactivate a product | PASS | 1 |
| P4 Sales and ledger views match the database | PASS | 1 |
| P4 Payout request, approve and reject | PASS | 1 |
| P4 Dispute response by the seller | PASS | 1 |
| P4 Seller rating | PASS | 1 |
| P4 IDOR: sellers and buyers cannot reach other people's data (403) | PASS | 1 |
| P5 Each staff role sees only what it may | PASS | 1 |
| P5 Manager is denied destructive and super-admin actions | PASS | 1 |
| P5 Approve and reject seller applications | PASS | 1 |
| P5 Approve and reject products; admin edit | PASS | 1 |
| P5 Order filters | PASS | 1 |
| P5 Full and partial refunds | PASS | 1 |
| P5 Disputes resolved both ways | PASS | 1 |
| P5 Suspend a buyer | PASS | 1 |
| P5 Suspend a seller hides their products | PASS | 1 |
| P5 Payouts via the gateway | PASS | 1 |
| P5 Audit log search | PASS | 1 |
| P5 Email templates | PASS | 1 |
| P5 Gateway config and logs | PASS | 1 |
| P5 CSV export of orders and users | PASS | 1 |
| P6 XSS in every field people can write | PASS | 1 |
| P6 SQL injection in search, filters, paths and login | PASS | 1 |
| P6 Path traversal | PASS | 1 |
| P6 Oversized uploads and request bodies | PASS | 1 |
| P6 Unicode and emoji input | PASS | 1 |
| P6 Extremely long inputs | PASS | 1 |
| P6 Malformed webhook JSON returns 400, never 500 | PASS | 1 |
| P6 Double-click on checkout and pay | PASS | 1 |
| P8 Kill the database mid-checkout | PASS | 1 |
| P8 Kill the queue worker | PASS | 1 |
| P8 Mail server down | PASS | 1 |
| P8 Kill the payment gateway (Shkeeper mock) | PASS | 1 |
| P8 Restore a backup | PASS | 1 |
| P8 Disk full during upload | PASS | 1 |
| P9 Gift cards: create, redeem, reuse, race, wrong currency, expiry, guessing | PASS | 1 |
| P9 Balance adjustments by finance | PASS | 1 |
| P9 Crypto refund through the gateway: success, failure, retry | PASS | 1 |
| P9 Payout address change with confirmation and waiting period | PASS | 1 |
| P9 Manual delivery by the seller and by staff | PASS | 1 |
| P9 Download limits, expired links and staff reset | PASS | 1 |
| P9 Subscription access over time | PASS | 1 |
| P9 Reviews | PASS | 1 |
| P9 Wishlist | PASS | 1 |
| P9 Support tickets | PASS | 1 |
| P9 Announcements | PASS | 1 |
| P9 Categories | PASS | 1 |
| P9 Commission settings screens drive real sales | PASS | 1 |
| P9 Bulk actions on products and users | PASS | 1 |
| P9 Currencies: EUR cart, EUR wallet, rate change, fixed-amount coupon | PASS | 1 |
| P9 Dispute deadline passes | PASS | 1 |
| P9 Payout hold period | PASS | 1 |
| P9 Reporting pages agree with the database | PASS | 1 |
| P9 Concurrent money operations | PASS | 1 |
| P10 Staff alert for rejected payment callbacks | PASS | 1 |
| P10 A failed email: retried, kept, alerted once, sent again from the health page | PASS | 1 |
| P10 Staff alert when Shkeeper stops answering | PASS | 1 |
| P10 Needs-attention digest for staff | PASS | 1 |
| P10 Seller email preferences and the review email | PASS | 1 |
| P10 Buyer gets a confirmation when opening a dispute | PASS | 1 |
| P10 Colour scheme, security.txt and static files through nginx and TLS | PASS | 1 |
| P10 Upgrade: the go-live migrations on a populated database | PASS | 1 |
| P11 Notification centre: events, badge, open and read all | PASS | 1 |
| P11 The notification list does not depend on the mail server | PASS | 1 |
| P11 Staff alerts in the notification list of super admins and managers | PASS | 1 |
| P11 Live updates endpoint through nginx and TLS | PASS | 1 |
| P11 Seller replies to a review | PASS | 1 |
