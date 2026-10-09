# Security Policy

## Reporting a vulnerability

Please report vulnerabilities privately. Do not open a public issue.

- Email: the address configured as `SHOP_SECURITY_EMAIL` for the deployment
  (security@example.com in the template). Encrypt sensitive details if a PGP
  key is published.
- Include the affected URL or component, steps to reproduce, the impact you
  observed, and the request id shown on any error page.
- Do not access other users' data, degrade the service, or use automated
  scanners that send high volumes of traffic. Testing against your own
  account and a local deployment (see README) is welcome.

What to expect:

| Step | Target |
|---|---|
| Acknowledgement | within 3 business days |
| Initial assessment and severity | within 10 business days |
| Fix for critical/high issues | as fast as possible, normally within 30 days |
| Public disclosure | coordinated with the reporter after a fix ships |

We will credit reporters who want to be named.

## Supported versions

Only the latest release on the default branch receives security fixes.

## Security controls in this code base

| Area | Control |
|---|---|
| Passwords | Argon2id (`HASH_DRIVER=argon2id`), rehash on login, minimum 12 characters |
| Two-factor authentication | TOTP (RFC 6238) with single-use codes and 8 recovery codes; optional for customers, **required for staff** before `/admin` |
| Staff roles | `admin`, `finance`, `support`, each limited by a permission map (`App\Support\Permissions`) checked on every admin route |
| Re-authentication | A password confirmed within 15 minutes is required for refunds, payouts, role/status changes, balance adjustments, gift cards, exchange rates, settings, exports, payout address and 2FA changes |
| Email | Verified address required for checkout, payments, gift cards, reviews and seller applications; email changes confirmed by the new address and announced to the old one |
| Sign-in alerts | Email on sign-in from a new device; users can list and end their sessions; staff can end all sessions of a user |
| Bot protection | Registration honeypot and time trap (no JavaScript, no third party) plus rate limits |
| Abuse limits | At most `SHOP_MAX_OPEN_ORDERS` unpaid orders per buyer; per-buyer coupon limits; download limits per item |
| Uploads | Seller files scanned by ClamAV before delivery or approval (infected files deleted, product disabled); reviewers can inspect files; images decoded and re-encoded to WebP (metadata stripped) |
| Payouts | Address changes need a recent password, an emailed confirmation link and a 48-hour payout pause; Shkeeper transfers need staff approval and are confirmed only by a signed callback or status poll |
| Sessions | Database driver, `HttpOnly`, `Secure`, `SameSite=Lax`, id regenerated at login, other sessions dropped on password change or reset |
| CSRF | Token on every form; every state-changing route is POST/PUT/DELETE; enforced in tests too. Only the Shkeeper callbacks (`/webhooks/shkeeper`, `/webhooks/shkeeper/payouts`) are exempt; they are authenticated by HMAC |
| Rate limits | Login (per email and per IP), registration, password reset, checkout, gift card redemption, forms, webhook, search |
| SQL | Eloquent / query builder bindings only; `LIKE` input is escaped |
| Output | Blade `{{ }}` escaping everywhere; JSON-LD encoded with `JSON_HEX_*` flags |
| Headers | CSP limited to self (no inline script or style), HSTS on HTTPS, `nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, `X-Frame-Options: DENY`, no `X-Powered-By` |
| Payments | Amount and currency come from the order row, never the client; an order is paid only by a verified webhook, a server-side status poll, or a balance debit; webhooks are idempotent |
| Webhooks | HMAC-SHA256 over `timestamp.body`, constant-time compare, 5-minute replay window, 401 on failure, events stored and de-duplicated |
| Downloads | Files outside the web root; signed expiring URLs plus an ownership and delivery check |
| Secrets at rest | Licence keys and delivery payloads encrypted with the app key; gift card codes stored only as HMAC |
| Errors | Generic error page with a request id; stack traces only in server logs |
| Audit | `audit_log` table for money movements, role and status changes, product review, payouts, refunds; viewer at `/admin/audit` |
| Secrets | Environment variables only; `.env` is git-ignored |
| Proxies | Trusted proxies come from config at request time (safe with `config:cache`) |
| Containers | Read-only root filesystems, all Linux capabilities dropped, `no-new-privileges`, unprivileged nginx |
| Dependencies | `composer audit` and `npm audit` in CI; Dependabot for Composer, npm, Actions and Docker |

## Known operational requirements

- Set `APP_DEBUG=false` and a strong `APP_KEY` in production. To rotate the
  key, put the old key in `APP_PREVIOUS_KEYS` (comma-separated) and set the new
  `APP_KEY`: encrypted values (licence keys, delivery payloads, 2FA secrets)
  stay readable, and gift card codes and recovery codes are matched against
  the previous keys too. Keep old keys until nothing encrypted with them
  remains.
- Create the first administrator with `php artisan shop:create-admin
  admin@example.com`; the admin area stays locked until that account turns on
  two-factor authentication.
- Serve over HTTPS only and set `TRUSTED_PROXIES` to your reverse proxy
  addresses.
- Restrict network access to the Shkeeper admin UI and keep its API key out of
  logs. Never expose `shkeeper-mock` outside a test network.
- The legacy code previously in this repository contained hardcoded
  credentials and what appears to be real personal and payment card data in
  `felux.sql`. It remains in git history until the history is purged. Treat
  those credentials as compromised.
