# Gap Analysis: Legacy "Olux/Felux Shop Script V2.0" vs. the Rebuild

This document records what the legacy code base (commit `75d7e8d`) contained,
which parts were excluded on legal grounds, what was reusable as a *concept*,
and why every line of code was replaced rather than refactored.

No legacy code was carried over. The rebuild reuses only the generic commerce
ideas listed in section 3.

---

## 1. Illegal or abusive functionality (excluded, not rebuilt)

The legacy script was a marketplace for criminal tooling and stolen data. The
following files and database tables implement that trade. They were
**deleted** from the repository, and nothing equivalent exists in the
rebuild. The rebuild's catalog is limited to lawful digital goods (software
tools, tutorials, licence keys, subscriptions) and sellers must accept terms
that prohibit the categories below.

| Legacy file(s) | Table | What it did | Why it is excluded |
|---|---|---|---|
| `shell.php`, `check2shell.php`, `seller/shell*.php`, `seller/ajax/dtool.php` | `stufs` | Sold access to web shells (backdoors) planted on compromised websites, with a "checker" that verified the backdoor still worked | Unauthorised access to computer systems |
| `cPanel.php`, `check2cp.php`, `apicheckcp.php`, `seller/cpanel*.php` | `cpanels` | Sold stolen hosting control panel credentials, with a live checker | Trafficking in stolen credentials |
| `rdp.php`, `seller/rdp*.php` | `rdps` | Sold access to hijacked Windows RDP servers | Unauthorised access to computer systems |
| `smtp.php`, `check2smtp.php`, `SMTPSend.php`, `seller/smtp*.php` | `smtps` | Sold stolen SMTP relays and test-sent mail through them | Spam and phishing infrastructure |
| `mailer.php`, `check2mailer.php`, `seller/mailer*.php` | `mailers` | Sold PHP mass-mailer scripts uploaded to hacked hosts | Spam and phishing infrastructure |
| `scampage.php`, `buyscam.php`, `seller/scampage*.php` | `scampages` | Sold phishing kits ("PayPal ScamPage") | Fraud |
| `banks.php`, `seller/banks*.php` | `banks` | Sold bank logins and full card data (PAN, CVV, IBAN, DOB, ID number) | Fraud, identity theft, payment card crime |
| `leads.php`, `seller/lead*.php` | `leads` | Sold harvested email lists | Spam, data protection violations |
| `premium.php`, `seller/premium*.php` | `accounts` | Sold logins to third-party accounts (Netflix, Upwork, ...) | Trafficking in stolen credentials |
| `PortChecker.php`, `PMcheck.php` | - | Network port probing from the shop server | Reconnaissance tooling |
| `.htaccess` geo-IP deny list | - | Blocked whole national IP ranges | Not a fraud control; obstructs investigation |

**Data hazard:** `felux.sql` contained rows in the `banks` table that look
like real personal and payment card data (name, address, IBAN, card number,
CVV, date of birth, ID document number), plus third-party account logins in
`accounts`. The file was deleted from the working tree. Because it remains in
git history, the repository owner should purge it with `git filter-repo`
(requires a force-push) and treat the data as compromised. This rebuild does
not reproduce any of it.

**Secrets in source:** `includes/config.php` (database password),
`addBalanceAction.php` (Block.io wallet PINs), `encrypt.php` (static AES key
and IV). All must be considered leaked and rotated wherever they are reused.

---

## 2. File inventory and disposition

| Area | Legacy files | Disposition |
|---|---|---|
| Auth | `login.php`, `loginform.php`, `loginpage1-3.php`, `signup*.php`, `forget.php`, `passform.php`, `resetpass.php`, `logout.php`, `encrypt.php` | Replaced by `AuthController`, `PasswordResetController`, `UserService` (Argon2id) |
| Catalog / buy | `divPage0-15.php`, `buytool.php`, `buytuto.php`, `tutorial.php`, `static.php`, `ajax.php` | Replaced by `CatalogService`, `CartService`, `CheckoutController` |
| Orders | `orders.php`, `openorder.php` | Replaced by `OrderController`, `OrderService` |
| Wallet / payment | `addBalance*.php`, `payment.php`, `divPagepayment.php`, `check.php`, `includes/block_io.php`, `pm3.php` | Replaced by `PaymentService`, `ShkeeperClient`, `WebhookController`, balance ledger |
| Tickets / reports | `tickets.php`, `tticket.php`, `showTicket.php`, `addReply.php`, `reports.php`, `treport.php`, `addReportReply.php`, `divPageticket.php`, `divPagereport.php` | Replaced by `TicketService` (tickets with optional order link) |
| Settings | `setting.php`, `settingEdit.php`, `checkEmailChange.php` | Replaced by `AccountController` |
| Seller | `becomeseller.php`, `seller/*` (non-illegal parts: tutorials, sales, refund, withdrawal, reports) | Replaced by `SellerController`, `SellerOnboardingService`, `PayoutService` |
| Admin | `admin/*`, `support/*` | Replaced by `Admin\*` controllers under `role:admin` |
| Vendored code | `vendor/`, `_lib/` (PHPMailer copy), `composer.phar`, `admin/vendors/ace-builds`, `ckeditor`, `fullcalendar` | Removed; dependencies now come from Composer only |
| Debris | `dd.php`, `test.php`, `cr.php`, `buyer/` (duplicate of root) | Removed |

---

## 3. What the legacy script did well (concepts retained)

These are business concepts, not code:

1. **Per-user wallet balance.** Buyers prefunded a balance and spent it, so a
   purchase was fast. The rebuild keeps a balance, but every change goes
   through an append-only `balance_transactions` ledger.
2. **Three roles.** Buyer, seller (reseller) and admin, plus a support
   staff panel. The rebuild keeps buyer, seller and admin. Support is an
   admin capability.
3. **Seller onboarding.** A buyer could apply to become a seller and an
   admin activated them. The rebuild keeps this, with an explicit
   application state machine.
4. **Seller earnings and withdrawal requests.** Sellers saw sold and unsold
   totals and could request a payout. The rebuild turns this into a real
   ledger with reconciliation.
5. **Order-linked problem reports.** Buyers could open a "report" about a
   specific purchase, and the seller or admin could refund. The rebuild models
   this as a ticket with an `order_id`, and refunds as payment rows.
6. **Instant delivery of digital goods.** The item content became visible
   right after purchase. The rebuild delivers through signed, expiring
   download links or licence keys stored on `order_items`.
7. **News and announcement panel.** Not rebuilt in v1. It can be added as
   static content.

---

## 4. What the legacy script did poorly

### 4.1 Security (critical)

| Problem | Example | Impact |
|---|---|---|
| SQL injection | `buytool.php`: `SELECT * FROM $tbl WHERE id='$uid'` with `$tbl = $_GET['t']`; escaping does not protect identifiers | Full database read and write |
| Broken access control | `admin/header.php` only checks that *some* user is logged in, so every buyer is an admin | Total compromise |
| Reversible password storage | `encrypt.php`: AES-256-CBC with a hardcoded key and IV; the "hash" is stored in the session too | Every password recoverable |
| No CSRF protection anywhere | 0 files reference a token | Any site can buy, refund or withdraw on a victim's behalf |
| State changes via GET | `buytool.php?id=&t=`, `admin/activer.php?id=`, `admin/refundr.php?id=`, `tticket.php?s=&m=` | CSRF via `<img>`, prefetchers trigger purchases |
| Stored XSS | Ticket memos built by string concatenation into HTML (`CONCAT(memo,'$msg')`) | Session theft of staff |
| Weak password reset | Numeric `resetpin`, no expiry, compared with `==` | Account takeover |
| Hardcoded secrets | DB password and wallet PINs in source | Credential leak |
| Payment trust model | `check.php` credits balance when *any* coins appear at an address, triggered by the buyer's own polling; no signature, no amount check against the order | Free balance, double credit |
| Error disclosure | `or die(mysqli_error($dbcon))` | Schema leakage |
| No rate limiting | Login and signup unlimited | Credential stuffing |
| No security headers or CSP | jQuery, Bootstrap, CKEditor loaded with inline scripts | XSS amplification |

### 4.2 Data integrity

* **No transactions.** `buytool.php` reads the balance, checks it, then runs
  four separate `UPDATE`s. Two concurrent requests can spend the same balance
  twice and sell the same item twice. There is no `SELECT ... FOR UPDATE`.
* **Integer dollars and a float column.** `price int` holds whole dollars, and
  `payment.amount double` holds crypto amounts as floats.
* **Free-text dates.** Formats such as `'22/03/2020 02:35:15 pm'` are stored
  in `text` columns, so the data cannot be sorted or range-queried.
* **Usernames as foreign keys.** `sto`, `resseller` and `buyer` are varchar
  usernames, with no FK constraints and no indexes.
* **Duplicated tables per product type.** There are 11 near-identical item
  tables instead of one `products` table.
* **Denormalised counters.** `resseller.soldb` and `allsales` are updated by
  hand and drift from the real sales.

### 4.3 Engineering

* There is no framework, routing, templating or autoloading. Every page is a
  mix of SQL and HTML.
* `buyer/` is a full copy of the root directory, so every bug exists twice.
* There are no tests, migrations, logging, configuration management or
  dependency pinning. Third-party code is committed.
* The pages need JavaScript for the core flows: `ajax.php` loads the
  `divPage*.php` fragments, and checkout is a jQuery call.

---

## 5. What was missing entirely

| Capability | Legacy | Rebuild |
|---|---|---|
| Database transactions and row locking | None | All money and stock changes inside `DB::transaction` with `lockForUpdate()` |
| Orders as first-class entities | `purchases` row per item, no order header | `orders` + `order_items` with a state machine |
| Refunds and partial refunds | Balance edited in place | Separate `payments` rows of kind `refund`, linked to the original |
| Invoices | None | PDF invoices stored in `storage/invoices/` |
| CSRF | None | Laravel CSRF middleware; every form renders `@csrf` |
| Rate limiting | None | Named limiters on login, register, password reset, checkout, webhook |
| Logging and audit | None | Monolog JSON lines plus an `audit_log` table with an admin viewer |
| Webhook verification | None | HMAC-SHA256 verified with `hash_equals`, timestamp window, idempotency |
| Email notifications | None | Queued mailables for order paid, delivery, ticket reply |
| Inventory | `sold` flag per row | `stock` decremented under row lock; reservations released on expiry |
| Multi-currency | USD only, implicit | Explicit currency per amount with a documented conversion policy |
| Seller payout ledger | A counter column | `seller_ledger_entries`, payout requests, reconciliation command |
| Coupons and gift balances | None | `coupons`, `gift_cards`, redemption ledger |
| Search and filters | Client-side table filter | Server-side query with category, price and text filters, paginated |
| SEO | None | Sitemap, canonical tags, JSON-LD Product data, `robots.txt` |
| Legal pages | None | Terms, privacy, data retention |
| Backups | None | `scripts/backup.sh` and `scripts/restore.sh` with a documented procedure |
| Observability | None | `/health`, `/metrics` (Prometheus text), request ids in logs and error pages |
| Idempotent checkout | None | Per-form idempotency key, unique per buyer |
| Webhook retry | None | Stored webhook events retried by a queued job with exponential backoff |

---

## 6. What must be replaced entirely

Everything. Specifically:

1. **Authentication.** It cannot be migrated, because passwords are stored
   reversibly with a leaked key. If you import legacy users, mark them for a
   forced password reset and do not import the password column.
2. **Payment layer.** Block.io and PerfectMoney polling is replaced by
   Shkeeper invoices and signed webhooks.
3. **Database schema.** It is redesigned from scratch (section 5). Do not
   import legacy item tables. Treat their content as unlawful.
4. **Front end.** jQuery and Bootstrap 3 with AJAX page fragments are
   replaced by server-rendered Blade views that work without JavaScript.
5. **Admin and support panels.** They are rebuilt behind role checks, and
   every admin action is audited.
