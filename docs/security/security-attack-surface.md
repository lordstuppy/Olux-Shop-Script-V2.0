# Attack surface, assets and trust boundaries

Audit of 2026-10-11, revisions `c0bae6a` and `70954d9` (the release
candidate is named in [security-audit-summary.md](security-audit-summary.md)).

## 1. Stack

| Layer | Technology | Security-relevant notes |
|---|---|---|
| Application | Laravel 13.35, PHP 8.3 (FPM, Alpine image, read-only root filesystem, no capabilities) | Server-rendered Blade; works without JavaScript; one first-party script (`public/js/enhance.js`) |
| Web server | nginx 1.27 unprivileged, template `docker/nginx/default.conf.template` | Only `public/` is served, only `index.php` executes, dotfiles denied, 55 MB body limit |
| Database | PostgreSQL 15 | One application role; isolation is enforced in the application (no row-level security, see section 6) |
| Queue, cache, sessions | Database drivers | Sessions in the `sessions` table (HttpOnly, Secure, SameSite=Lax) |
| Payments | Self-hosted Shkeeper (crypto) | Invoices, payment callbacks (HMAC), payouts and refunds via its payout API |
| Virus scanning | ClamAV (clamd, INSTREAM over TCP) | Seller files; delivery and approval only after a clean scan |
| Mail | SMTP | Text-only mails; links built from `APP_URL` |
| CI/CD | GitHub Actions (`.github/workflows/ci.yml`), Dependabot | No deployment pipeline in the repository: deployment is manual (docs/OPERATIONS.md) |
| AI / LLM | None | No model, agent, retrieval or prompt anywhere in the code (searched: no SDKs, no API calls) |

## 2. Identities and privilege levels

| Identity | How it is established | Can |
|---|---|---|
| Anonymous | none | Browse the catalogue, cart (session), register, log in, reset password, read public pages |
| Buyer | Password (Argon2id) + optional TOTP; email verification for money actions | Own orders, downloads, wallet, tickets, disputes, reviews, notifications |
| Seller | Buyer whose seller application was approved | Own products, files, keys, sales, payouts, review replies |
| Staff: support, moderator, finance, manager, super admin | Role + mandatory TOTP; every ability in `App\Support\Permissions` is a Gate that also requires 2FA | See the permission map; money, settings and roles need a password confirmed within 15 minutes |
| Shkeeper | HMAC-SHA256 over `timestamp.body` with the webhook secret, 5-minute window | Report payment and transfer results |
| Operators | Shell / container access, `.env` | Everything; outside the application's control |

## 3. Assets

Customer and seller accounts (credentials, sessions, 2FA secrets); money (wallet
balances, gift cards, seller ledger, payouts, refunds, crypto sent by Shkeeper);
digital goods (product files, licence keys, manual delivery texts, all
encrypted or private); personal data (emails, IPs, orders, tickets, disputes);
the Shkeeper API key and payout credentials; `APP_KEY`; the audit log.

## 4. Trust boundaries and data flows

```
 browser --TLS--> [LB/TLS terminator] --> nginx --FastCGI--> PHP-FPM (Laravel) --> PostgreSQL
                                                                  |  ^
                               Shkeeper API (invoices, payouts) <-+  +-- Shkeeper callbacks (HMAC) via nginx
                               ClamAV (INSTREAM) <----------------+
                               SMTP (mail) <----------------------+
```

1. **Internet to nginx.** Untrusted input. nginx believes `X-Forwarded-*`
   only from `NGINX_TRUSTED_PROXY` and hands PHP the client address
   (`REMOTE_ADDR`) and scheme (`HTTPS`) itself (SEC-04, SEC-39); Laravel
   trusts no proxy unless `TRUSTED_PROXIES` is set and never
   `X-Forwarded-Host` (SEC-31).
2. **Anonymous to authenticated.** Login, 2FA challenge, password reset,
   email verification. Rate limits per email+IP and IP, account lock,
   2FA budget per account (SEC-11).
3. **Buyer to seller to staff.** Policies (`app/Policies`) for object access,
   `role:` and `can:` middleware plus 2FA for staff, abilities re-checked in
   services for money actions (SEC-02).
4. **Application to Shkeeper.** Outbound: base URL and credentials only from
   the environment, no redirects followed (SEC-27), callback URL from
   `APP_URL` (SEC-01). Inbound: HMAC, replay window, de-duplication, amounts
   and currency from the order row.
5. **Application to file storage.** Generated names, private disks, signed
   expiring download URLs plus ownership, delivery and refund checks.
6. **Staff to money.** Re-authentication, audit log, 2FA, and the
   claim-before-transfer rule for Shkeeper transfers (SEC-06 to SEC-08).

## 5. Threat model (abuse cases and controls)

| Threat | Attack path | Controls (evidence) |
|---|---|---|
| Account takeover | Credential stuffing, reset-token theft, 2FA brute force, session theft | Rate limits and lock (`UserService::checkCredentials`), single-use hashed reset tokens, 2FA budget (SEC-11), remember-token rotation (SEC-10, SEC-16), security emails (SEC-18); tests in `AccountSecurityTest`, `LoginLockTest`, `SecurityAuditTest` |
| Unauthorised data access (IDOR) | Changing ids in order, item, file, ticket, dispute, notification URLs | Policies and parent checks on every route parameter (inventory below); `OrderAccessTest`, simulation phases 1 to 11 |
| Privilege escalation | Moderator moving money, support signing out admins, manager overwriting super-admin settings | Permission map plus service-level checks (SEC-02, SEC-09, SEC-20); `StaffRolesTest` |
| Payment manipulation | Forged or replayed callbacks, Host-header callback hijack, currency switching | HMAC + window + de-dup (`WebhookTest`), APP_URL pinning (SEC-01), server-side amounts, order limits without rates (SEC-24) |
| Seller fraud | Withdrawing money still disputable, then disappearing | Earnings held until delivery + dispute window (SEC-03), dispute holds, payout approval |
| Double spending of shop money | Retried or concurrent transfers, ambiguous timeouts | Claim before transfer, per-attempt ids, unknown outcome stays pending (SEC-06 to SEC-08); row locks on balances, coupons, gift cards, keys |
| Malicious uploads | Malware, decompression bombs, storage exhaustion | ClamAV fail-closed, image re-encoding with a 25 MP cap, quotas and throttling (SEC-13), no unscanned file to staff (SEC-22) |
| Injection / XSS | SQL, Blade, mail templates, live updates | Bindings only, escaped Blade, strict CSP, text-only mails, project Semgrep rules |
| Supply chain | Compromised dependency or CI action | Lockfiles, advisories in CI, pinned action SHAs, Dependabot cooldown, gate job (`scripts/security-gate.sh`) |
| Service disruption | Request floods, large bodies, expensive pages | nginx body/header limits (probe), per-route rate limits, load test (docs/SIMULATION.md phase 7) |
| Secret theft | Leaked `.env`, logs, API key via callback | `.env` untracked (gate), secrets only from env, gateway log without headers/bodies, SEC-01 |

## 6. Database access and row-level security

PostgreSQL row-level security is **not used**: the application connects with a
single role and enforces ownership and tenant (buyer/seller) boundaries in
application code (policies, scoped queries, service checks). There are no
database views, stored procedures or RPC functions exposed to users, and no
client connects to the database directly. The applicable controls are
therefore the server-side authorisation checks listed in section 7, verified by
PHPUnit and the simulation suite. Least privilege for the database role (no
superuser, no `CREATEROLE`) is an operator task listed in
[security-residual-risks.md](security-residual-risks.md) (R-08), because the
role is created outside the repository.

## 7. Entry-point inventory (all 189 routes)

Generated from `php artisan route:list --except-vendor` on the audited
revision. "Access" is the server-side requirement from route middleware; object
ownership is checked additionally in the controller or policy (column notes in
section 8). Every row was reviewed; none is unclassified.

| Method | URI | Route name | Access (server-side) | Extra controls | Data / asset |
|---|---|---|---|---|---|
| GET | `/.well-known/security.txt` | security-txt | public | - | public content |
| GET | `/` | home | public | - | public content |
| GET | `/account` | account.settings | signed-in | - | account credentials, sessions, 2FA |
| POST | `/account/email` | account.email | signed-in | rate limit email-change | account credentials, sessions, 2FA |
| GET | `/account/email/confirm/{token}` | account.email.confirm | signed-in | - | account credentials, sessions, 2FA |
| POST | `/account/email/confirm/{token}` | - | signed-in | rate limit forms | account credentials, sessions, 2FA |
| PUT | `/account/notifications` | account.notifications | signed-in | - | account credentials, sessions, 2FA |
| PUT | `/account/password` | account.password | signed-in | rate limit password-check | account credentials, sessions, 2FA |
| PUT | `/account/profile` | account.profile | signed-in | - | account credentials, sessions, 2FA |
| DELETE | `/account/sessions` | account.sessions.destroy-others | signed-in | recent password | account credentials, sessions, 2FA |
| DELETE | `/account/sessions/{handle}` | account.sessions.destroy | signed-in | - | account credentials, sessions, 2FA |
| DELETE | `/account/two-factor` | account.two-factor.disable | signed-in | recent password, rate limit two-factor | account credentials, sessions, 2FA |
| GET | `/account/two-factor` | account.two-factor | signed-in | - | account credentials, sessions, 2FA |
| POST | `/account/two-factor` | account.two-factor.enable | signed-in | rate limit two-factor | account credentials, sessions, 2FA |
| POST | `/account/two-factor/recovery-codes` | account.two-factor.recovery | signed-in | recent password | account credentials, sessions, 2FA |
| GET | `/admin` | admin.dashboard | staff+2FA: staff.dashboard | - | staff back office |
| GET | `/admin/announcements` | admin.announcements.index | staff+2FA: announcements.manage | - | staff back office |
| POST | `/admin/announcements` | admin.announcements.store | staff+2FA: announcements.manage | - | staff back office |
| DELETE | `/admin/announcements/{announcement}` | admin.announcements.destroy | staff+2FA: announcements.manage | - | staff back office |
| POST | `/admin/announcements/{announcement}/toggle` | admin.announcements.toggle | staff+2FA: announcements.manage | - | staff back office |
| GET | `/admin/audit` | admin.audit.index | staff+2FA: audit.view | - | audit log |
| POST | `/admin/bulk/orders/export` | admin.bulk.orders.export | staff+2FA: orders.export | recent password | bulk changes / exports |
| POST | `/admin/bulk/products` | admin.bulk.products | staff+2FA: products.manage | - | bulk changes / exports |
| POST | `/admin/bulk/users` | admin.bulk.users | staff+2FA: users.manage | recent password | bulk changes / exports |
| GET | `/admin/categories` | admin.categories.index | staff+2FA: categories.manage | - | staff back office |
| POST | `/admin/categories` | admin.categories.store | staff+2FA: categories.manage | - | staff back office |
| DELETE | `/admin/categories/{category:id}` | admin.categories.destroy | staff+2FA: categories.manage | - | staff back office |
| PUT | `/admin/categories/{category:id}` | admin.categories.update | staff+2FA: categories.manage | - | staff back office |
| GET | `/admin/commission` | admin.commission.index | staff+2FA: commission.manage | - | commission rates |
| PUT | `/admin/commission/categories/{category:id}` | admin.commission.category | staff+2FA: commission.manage | recent password | commission rates |
| PUT | `/admin/commission/products` | admin.commission.product | staff+2FA: commission.manage | recent password | commission rates |
| PUT | `/admin/commission/sellers/{profile}` | admin.commission.seller | staff+2FA: commission.manage | recent password | commission rates |
| GET | `/admin/coupons` | admin.coupons.index | staff+2FA: coupons.manage | - | staff back office |
| POST | `/admin/coupons` | admin.coupons.store | staff+2FA: coupons.manage | - | staff back office |
| POST | `/admin/coupons/{coupon}/toggle` | admin.coupons.toggle | staff+2FA: coupons.manage | - | staff back office |
| GET | `/admin/disputes` | admin.disputes.index | staff+2FA: disputes.manage | - | disputes, refunds |
| POST | `/admin/disputes/{dispute}/resolve` | admin.disputes.resolve | staff+2FA: disputes.manage | recent password | disputes, refunds |
| GET | `/admin/email-templates` | admin.email-templates.index | staff+2FA: templates.manage | - | staff back office |
| DELETE | `/admin/email-templates/{key}` | admin.email-templates.reset | staff+2FA: templates.manage | - | staff back office |
| GET | `/admin/email-templates/{key}` | admin.email-templates.edit | staff+2FA: templates.manage | - | staff back office |
| PUT | `/admin/email-templates/{key}` | admin.email-templates.update | staff+2FA: templates.manage | - | staff back office |
| GET | `/admin/exchange-rates` | admin.rates.index | staff+2FA: rates.manage | - | staff back office |
| POST | `/admin/exchange-rates` | admin.rates.store | staff+2FA: rates.manage | recent password | staff back office |
| GET | `/admin/exports` | admin.exports.index | staff+2FA: exports.download | - | bulk personal data |
| GET | `/admin/exports/download` | admin.exports.download | staff+2FA: exports.download | recent password | bulk personal data |
| GET | `/admin/gateway` | admin.gateway.index | staff+2FA: gateway.view | - | payment gateway settings |
| PUT | `/admin/gateway` | admin.gateway.update | staff+2FA: gateway.manage | recent password | payment gateway settings |
| POST | `/admin/gateway/check` | admin.gateway.check | staff+2FA: gateway.view | rate limit forms | payment gateway settings |
| GET | `/admin/gift-cards` | admin.gift-cards.index | staff+2FA: giftcards.manage | - | gift cards (money) |
| POST | `/admin/gift-cards` | admin.gift-cards.store | staff+2FA: giftcards.manage | recent password | gift cards (money) |
| GET | `/admin/health` | admin.health | staff+2FA: health.view | - | jobs, system state |
| POST | `/admin/health/failed-jobs/retry` | admin.health.jobs.retry-all | staff+2FA: jobs.manage | - | jobs, system state |
| DELETE | `/admin/health/failed-jobs/{uuid}` | admin.health.jobs.forget | staff+2FA: jobs.manage | - | jobs, system state |
| POST | `/admin/health/failed-jobs/{uuid}/retry` | admin.health.jobs.retry | staff+2FA: jobs.manage | - | jobs, system state |
| GET | `/admin/orders` | admin.orders.index | staff+2FA: orders.view | - | orders, refunds, buyer data |
| GET | `/admin/orders/{order}` | admin.orders.show | staff+2FA: orders.view | - | orders, refunds, buyer data |
| POST | `/admin/orders/{order}/cancel` | admin.orders.cancel | staff+2FA: orders.manage | - | orders, refunds, buyer data |
| POST | `/admin/orders/{order}/invoice` | admin.orders.invoice | staff+2FA: orders.manage | - | orders, refunds, buyer data |
| POST | `/admin/orders/{order}/items/{item}/deliver` | admin.orders.items.deliver | staff+2FA: orders.manage | - | orders, refunds, buyer data |
| POST | `/admin/orders/{order}/items/{item}/reset-downloads` | admin.orders.items.reset-downloads | staff+2FA: orders.manage | - | orders, refunds, buyer data |
| POST | `/admin/orders/{order}/redeliver` | admin.orders.redeliver | staff+2FA: orders.resend | - | orders, refunds, buyer data |
| POST | `/admin/orders/{order}/refunds` | admin.orders.refund | staff+2FA: orders.manage | recent password | orders, refunds, buyer data |
| GET | `/admin/payments` | admin.payments.index | staff+2FA: payments.view | - | staff back office |
| GET | `/admin/payouts` | admin.payouts.index | staff+2FA: payouts.manage | - | payouts, seller money |
| POST | `/admin/payouts/{payout}/approve` | admin.payouts.approve | staff+2FA: payouts.manage | recent password | payouts, seller money |
| POST | `/admin/payouts/{payout}/paid` | admin.payouts.paid | staff+2FA: payouts.manage | recent password | payouts, seller money |
| POST | `/admin/payouts/{payout}/reject` | admin.payouts.reject | staff+2FA: payouts.manage | recent password | payouts, seller money |
| POST | `/admin/payouts/{payout}/send` | admin.payouts.send | staff+2FA: payouts.manage | recent password | payouts, seller money |
| GET | `/admin/products` | admin.products.index | staff+2FA: products.manage | - | staff back office |
| GET | `/admin/products/{product:id}` | admin.products.show | staff+2FA: products.manage | - | staff back office |
| GET | `/admin/products/{product:id}/files/{file}` | admin.products.files.download | staff+2FA: products.manage | - | staff back office |
| POST | `/admin/products/{product:id}/status` | admin.products.status | staff+2FA: products.manage | - | staff back office |
| GET | `/admin/reconciliation` | admin.reconciliation | staff+2FA: payouts.manage | - | staff back office |
| GET | `/admin/reports` | admin.reports | staff+2FA: reports.view | - | staff back office |
| GET | `/admin/reviews` | admin.reviews.index | staff+2FA: reviews.moderate | - | staff back office |
| DELETE | `/admin/reviews/{review}/reply` | admin.reviews.reply.destroy | staff+2FA: reviews.moderate | - | staff back office |
| POST | `/admin/reviews/{review}/status` | admin.reviews.status | staff+2FA: reviews.moderate | - | staff back office |
| GET | `/admin/sellers` | admin.sellers.index | staff+2FA: sellers.manage | - | staff back office |
| POST | `/admin/sellers/{profile}/approve` | admin.sellers.approve | staff+2FA: sellers.manage | - | staff back office |
| POST | `/admin/sellers/{profile}/reject` | admin.sellers.reject | staff+2FA: sellers.manage | - | staff back office |
| GET | `/admin/settings` | admin.settings.index | staff+2FA: settings.manage | - | shop settings |
| PUT | `/admin/settings` | admin.settings.update | staff+2FA: settings.manage | recent password | shop settings |
| GET | `/admin/tickets` | admin.tickets.index | staff+2FA: tickets.manage | - | staff back office |
| GET | `/admin/users` | admin.users.index | staff+2FA: users.view | - | accounts, roles, balances |
| GET | `/admin/users/{user}` | admin.users.show | staff+2FA: users.view | - | accounts, roles, balances |
| POST | `/admin/users/{user}/balance` | admin.users.balance | staff+2FA: balances.adjust | recent password | accounts, roles, balances |
| POST | `/admin/users/{user}/role` | admin.users.role | staff+2FA: roles.manage | recent password | accounts, roles, balances |
| POST | `/admin/users/{user}/sessions/revoke` | admin.users.sessions.revoke | staff+2FA: sessions.revoke | - | accounts, roles, balances |
| POST | `/admin/users/{user}/status` | admin.users.status | staff+2FA: users.manage | recent password | accounts, roles, balances |
| GET | `/admin/webhooks` | admin.webhooks.index | staff+2FA: webhooks.manage | - | payment events |
| POST | `/admin/webhooks/{event}/retry` | admin.webhooks.retry | staff+2FA: webhooks.manage | - | payment events |
| GET | `/cart` | cart.show | public | - | cart (session) |
| POST | `/cart/currency` | cart.currency | public | rate limit forms | cart (session) |
| POST | `/cart/items` | cart.add | public | rate limit forms | cart (session) |
| DELETE | `/cart/items/{product:id}` | cart.remove | public | rate limit forms | cart (session) |
| PUT | `/cart/items/{product:id}` | cart.update | public | rate limit forms | cart (session) |
| GET | `/checkout` | checkout.show | signed-in, verified email | - | orders, payments |
| POST | `/checkout` | checkout.store | signed-in, verified email | rate limit checkout | orders, payments |
| DELETE | `/checkout/coupon` | checkout.coupon.remove | signed-in | - | orders, payments |
| POST | `/checkout/coupon` | checkout.coupon | signed-in, verified email | rate limit forms | orders, payments |
| GET | `/confirm-password` | password.confirm | signed-in | - | re-authentication |
| POST | `/confirm-password` | - | signed-in | rate limit password-check | re-authentication |
| GET | `/data-retention` | pages.retention | public | - | public content |
| GET | `/disputes` | disputes.index | signed-in | - | disputes |
| GET | `/disputes/{dispute}` | disputes.show | signed-in | - | disputes |
| POST | `/disputes/{dispute}/messages` | disputes.reply | signed-in | rate limit forms | disputes |
| POST | `/disputes/{dispute}/withdraw` | disputes.withdraw | signed-in | - | disputes |
| POST | `/email/verification-notification` | verification.send | signed-in | rate limit verification | account email |
| GET | `/email/verify` | verification.notice | signed-in | - | account email |
| GET | `/email/verify/{id}/{hash}` | verification.verify | signed-in | signed URL, rate limit verification | account email |
| GET | `/forgot-password` | password.request | guest only | - | public content |
| POST | `/forgot-password` | password.email | guest only | rate limit password-reset | public content |
| GET | `/health` | health | public | - | health |
| GET | `/live` | live | signed-in | rate limit live | page versions (own data) |
| GET | `/login` | login | guest only | - | credentials |
| POST | `/login` | - | guest only | rate limit login | credentials |
| POST | `/logout` | logout | signed-in | - | public content |
| GET | `/metrics` | metrics | public | - | metrics (token) |
| GET | `/notifications` | notifications.index | signed-in | - | notifications |
| POST | `/notifications/read-all` | notifications.read-all | signed-in | - | notifications |
| GET | `/notifications/{notification}/open` | notifications.open | signed-in | - | notifications |
| GET | `/orders` | account.orders | signed-in | - | orders, downloads, keys |
| GET | `/orders/{order}` | orders.show | signed-in | - | orders, downloads, keys |
| POST | `/orders/{order}/cancel` | orders.cancel | signed-in | - | orders, downloads, keys |
| GET | `/orders/{order}/invoice` | orders.invoice | signed-in | - | orders, downloads, keys |
| GET | `/orders/{order}/items/{item}/dispute` | disputes.create | signed-in | - | orders, downloads, keys |
| POST | `/orders/{order}/items/{item}/dispute` | disputes.store | signed-in, verified email | rate limit forms | orders, downloads, keys |
| GET | `/orders/{order}/items/{item}/files/{file}` | orders.download | signed-in | signed URL | orders, downloads, keys |
| GET | `/orders/{order}/pay` | orders.pay | signed-in | - | orders, downloads, keys |
| POST | `/orders/{order}/pay` | orders.pay.start | signed-in, verified email | rate limit checkout | orders, downloads, keys |
| POST | `/orders/{order}/pay-balance` | orders.pay.balance | signed-in, verified email | rate limit checkout | orders, downloads, keys |
| POST | `/orders/{order}/reorder` | orders.reorder | signed-in | - | orders, downloads, keys |
| GET | `/orders/{order}/result` | orders.result | signed-in | - | orders, downloads, keys |
| GET | `/privacy` | pages.privacy | public | - | public content |
| GET | `/product-images/{image}/{size}` | products.image | public | - | images |
| GET | `/products` | products.index | public | - | catalogue, reviews |
| GET | `/products/{slug}` | products.show | public | - | catalogue, reviews |
| POST | `/products/{slug}/reviews` | products.reviews.store | signed-in, verified email | rate limit forms | catalogue, reviews |
| GET | `/register` | register | guest only | - | accounts |
| POST | `/register` | - | guest only | rate limit register | accounts |
| POST | `/reset-password` | password.update | guest only | rate limit password-reset | credentials |
| GET | `/reset-password/{token}` | password.reset | guest only | - | credentials |
| GET | `/robots.txt` | robots | public | - | public content |
| GET | `/search/suggest` | search.suggest | public | rate limit search | public content |
| GET | `/sell` | seller.apply | public | - | seller applications |
| POST | `/sell` | seller.apply.store | signed-in, verified email | rate limit forms | seller applications |
| GET | `/seller` | seller.dashboard | seller | - | seller catalogue, sales, money |
| POST | `/seller/items/{item}/deliver` | seller.items.deliver | seller | - | seller catalogue, sales, money |
| GET | `/seller/payout-address/confirm/{token}` | seller.payout-address.confirm | seller | - | payout address, money |
| POST | `/seller/payout-address/confirm/{token}` | seller. | seller | rate limit forms | payout address, money |
| GET | `/seller/payout-settings` | seller.payout-settings | seller | - | payout address, money |
| POST | `/seller/payout-settings` | seller.payout-settings.store | seller | recent password, rate limit forms | payout address, money |
| GET | `/seller/payouts` | seller.payouts | seller | - | payout address, money |
| POST | `/seller/payouts` | seller.payouts.store | seller | rate limit forms | payout address, money |
| GET | `/seller/products` | seller.products.index | seller | - | seller catalogue, sales, money |
| POST | `/seller/products` | seller.products.store | seller | - | seller catalogue, sales, money |
| GET | `/seller/products/new` | seller.products.create | seller | - | seller catalogue, sales, money |
| PUT | `/seller/products/{product:id}` | seller.products.update | seller | - | seller catalogue, sales, money |
| GET | `/seller/products/{product:id}/edit` | seller.products.edit | seller | - | seller catalogue, sales, money |
| POST | `/seller/products/{product:id}/files` | seller.products.files.store | seller | rate limit uploads | seller catalogue, sales, money |
| DELETE | `/seller/products/{product:id}/files/{file}` | seller.products.files.destroy | seller | - | seller catalogue, sales, money |
| POST | `/seller/products/{product:id}/images` | seller.products.images.store | seller | rate limit uploads | seller catalogue, sales, money |
| DELETE | `/seller/products/{product:id}/images/{image}` | seller.products.images.destroy | seller | - | seller catalogue, sales, money |
| POST | `/seller/products/{product:id}/keys` | seller.products.keys.store | seller | rate limit uploads | seller catalogue, sales, money |
| POST | `/seller/products/{product:id}/pause` | seller.products.pause | seller | - | seller catalogue, sales, money |
| POST | `/seller/products/{product:id}/resume` | seller.products.resume | seller | - | seller catalogue, sales, money |
| POST | `/seller/products/{product:id}/submit` | seller.products.submit | seller | - | seller catalogue, sales, money |
| GET | `/seller/reviews` | seller.reviews | seller | - | seller catalogue, sales, money |
| PUT | `/seller/reviews/{review}/reply` | seller.reviews.reply | seller | rate limit forms | seller catalogue, sales, money |
| GET | `/seller/sales` | seller.sales | seller | - | seller catalogue, sales, money |
| GET | `/sitemap.xml` | sitemap | public | - | public content |
| GET | `/terms` | pages.terms | public | - | public content |
| POST | `/theme` | theme | public | rate limit forms | public content |
| GET | `/tickets` | tickets.index | signed-in | - | support tickets |
| POST | `/tickets` | tickets.store | signed-in | rate limit forms | support tickets |
| GET | `/tickets/new` | tickets.create | signed-in | - | support tickets |
| GET | `/tickets/{ticket}` | tickets.show | signed-in | - | support tickets |
| POST | `/tickets/{ticket}/assign` | tickets.assign | signed-in, tickets.manage | - | support tickets |
| POST | `/tickets/{ticket}/close` | tickets.close | signed-in | - | support tickets |
| POST | `/tickets/{ticket}/messages` | tickets.reply | signed-in | rate limit forms | support tickets |
| GET | `/two-factor-challenge` | two-factor.challenge | guest only | - | 2FA |
| POST | `/two-factor-challenge` | - | guest only | rate limit two-factor | 2FA |
| GET | `/wallet` | wallet.show | signed-in | - | balance |
| POST | `/wallet/redeem` | wallet.redeem | signed-in, verified email | rate limit redeem | balance |
| POST | `/webhooks/shkeeper` | webhooks.shkeeper | public | rate limit webhook | payment state (HMAC) |
| POST | `/webhooks/shkeeper/payouts` | webhooks.shkeeper.payouts | public | rate limit webhook | payment state (HMAC) |
| GET | `/wishlist` | wishlist.index | signed-in | - | wishlist |
| POST | `/wishlist` | wishlist.store | signed-in | rate limit forms | wishlist |
| DELETE | `/wishlist/{product:id}` | wishlist.destroy | signed-in | - | wishlist |

## 8. Object-level checks behind the route parameters

| Parameter | Check | Where |
|---|---|---|
| `{order}` (public UUID) | `view`/`act` policy: buyer only; staff via `admin/*` abilities | `OrderPolicy`, `OrderController`, `Admin\OrderController` |
| `{item}`, `{file}` under an order | item belongs to order, file to item's product, file in delivered payload, line not fully refunded (SEC-05), signed URL, download limit under row lock | `DownloadController` |
| `{dispute}`, `{ticket}` | buyer, seller (disputes) or staff ability; internal notes staff-only | `DisputePolicy`, `TicketPolicy`, services |
| `{notification}` | query scoped to the signed-in user; redirect only to local paths | `NotificationController` |
| `{product}` (seller) | `ProductPolicy` owner check on every action; files/images belong to the product | `Seller\ProductController` |
| `{product}` (cart, wishlist) | answered only for listed products or ones already in the cart/wishlist (SEC-19) | `CartController`, `WishlistController` |
| `{image}/{size}` | size allowlisted; unlisted products only for owner or staff | `routes/web.php`, `ProductImageController` |
| `{review}` (seller reply) | owner of the product; visible reviews only | `ReviewService::reply` |
| `{payout}`, `{profile}`, `{user}` | staff ability + re-authentication; status machine under row locks; staff accounts only by super admins (SEC-20) | `PayoutService`, `UserService`, `Admin\UserController` |
| `{handle}` (sessions) | HMAC handle of the user's own sessions only | `SessionController` |
| `{token}` (email change, payout address) | hashed, single use, expiring, bound to the signed-in user | `UserService`, `PayoutSettingsController` |
| `/live?path=` | page version computed with the same policies as the page; internal notes excluded for customers | `LivePages` |
