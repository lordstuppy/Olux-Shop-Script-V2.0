# Hostile-conditions simulation

A browser-level simulation that acts as buyers, sellers and five kinds of staff
against a production-like stack. It tries to break the shop and records
everything it does. Every bug it found was fixed with a regression test (PHPUnit)
and retested in the simulation.

- **Code:** `tests/simulation/` (Python standard library only, plus k6 for phase 7).
- **Reports:** written to `tests/simulation/reports/`, which is git-ignored.
- **Results:** the latest certification results are at the end of this file.

## Running it

```sh
SIM_BACKEND=compose sh tests/simulation/run.sh up      # build and start the stack, fresh seeded database
sh tests/simulation/run.sh test --runs 5               # phases 1-6 and 8, five consecutive runs
K6=/path/to/k6 sh tests/simulation/run.sh load         # phase 7
sh tests/simulation/run.sh down
```

Options for `test`:

- `--phases 1,2`: run only those phases.
- `--only "text"`: run only scenarios whose name contains the text.
- `--no-reset`: keep the current data instead of starting from a fresh database.

Every run starts from a fresh database (`migrate:fresh` plus
`Database\Seeders\SimulationSeeder`).

### Backends

| | `compose` (certification) | `host` (development fallback) |
|---|---|---|
| App | production image, PHP-FPM, nginx | git HEAD copied to `tests/simulation/work/app`, PHP built-in server with the production `php.ini` limits |
| TLS front end | nginx 1.27, `docker/test/tls.conf` | nginx 1.27, `docker/test/host-tls.conf` |
| Worker, scheduler | containers with `restart: unless-stopped` | host processes in a restart loop (same behaviour) |
| PostgreSQL 15, Mailpit, Shkeeper mock, ClamAV | containers (fake clamd by default; profile `real-clamav` for the real one) | same containers; fake clamd on the host |
| Client addresses | Docker NAT shows one address | each simulated person connects from its own loopback address |

Both backends expose the same endpoints:

| Service | Address |
|---|---|
| Shop (TLS) | `https://localhost:8443` |
| PostgreSQL | `127.0.0.1:5433` |
| Mailpit | `127.0.0.1:8025` |
| Shkeeper mock | `127.0.0.1:8081` |

The compose overlay is `docker-compose.test.yml`. It sets:

- Debug-level logs.
- PostgreSQL logging of every data change (`log_statement=mod`) and of every statement over 200 ms.
- A 64 MB tmpfs for product files, used by the disk-full test.

### Test data (`SimulationSeeder`)

- **Staff:** 5 accounts (`superadmin@`, `manager@`, `finance@`, `moderator@`, `support@sim.test`), each with 2FA. The fixed TOTP secrets are in the seeder.
- **Sellers:** 10, `seller01`–`seller10@sim.test`. seller03 has a seller-level commission.
- **Buyers:** 50, `buyer01`–`buyer50@sim.test`, with varied USD and EUR balances. Every fifth buyer has a balance of 0.
- **Categories:** 8. Licences and courses carry category commissions.
- **Products:** 200.
  - Status: 185 active, 10 pending review, 5 disabled.
  - Delivery: 15 manual; 3 out of stock.
  - Content: 430 licence keys; 157 files, all scanned clean.

All accounts use the password `Sim-Password-2026`. Because the password is known, the seeder refuses to run in an environment named production unless `SHOP_SIMULATION=allow-known-passwords` is set. Never set that outside a disposable test stack.

### Evidence per scenario

Each scenario produces a record (`run-N/records.json`, `run-N/report.md`) with:

- name and precondition;
- the exact steps, with time offsets, URLs and fields;
- expected and actual result;
- severity if it fails;
- evidence: request ids, SQL results, mail subjects and log lines.

`run-N/trace.jsonl` holds every HTTP request and response for replay:

- method, URL, request headers and body;
- status, response headers and the first 2 KB of the body;
- duration and the shop's `X-Request-Id`.

PostgreSQL's log holds every data change.

### A scenario passes only when, in addition to its own checks:

- no response in the scenario was a 5xx, unless the scenario declares it correct (503 while the database is down);
- the app log has no exception and no error-level entry that the scenario did not declare;
- every data invariant holds (`simlib/shop.py`):
  - paid orders are covered by a confirmed charge;
  - totals add up;
  - each sold line has exactly one sale ledger entry, and unpaid orders have none;
  - seller earnings equal the ledger;
  - refunds stay within the total;
  - no negative stock or balances, and balance history matches balances;
  - licence keys are only on paid lines, and delivered lines belong to paid orders;
  - no duplicate charges and no orphan rows;
  - `shop:reconcile-payouts` reports no difference.

`summary.md` counts consecutive passes per scenario. The pass rule is 5 consecutive passes.

## Scenarios

### Phase 1: purchases

1. Single item purchase (crypto).
2. Two items from the same vendor.
3. Three vendors. A fault is injected: one seller's licence keys are withdrawn after checkout. That line stays undelivered and is audited; the other lines are delivered.
4. Five units of one product.
5. Mixed stock states in the cart.
6. Coupon on the subtotal, with commission on the discounted amount.
7. Insufficient balance: blocked, and no partial order is created.
8. Abandoned cart. The buyer returns after the session has ended; prices are revalidated and sold-out items are flagged.
9. Guest checkout: *not applicable by decision*. Accounts are required, and the cart survives login.
10. Concurrent purchase of the last unit.

### Phase 2: payment events

1. Successful payment.
2. Underpayment (90%).
3. Overpayment (110%), credited to the buyer's balance.
4. Expired quote, then an expired order. Retry works through a new invoice and through "Buy again".
5. Duplicate callback, delivered five times.
6. Out-of-order callbacks.
7. Callback delayed 30 minutes. Polling or the late callback pays the order once.
8. Forged callbacks: unsigned, wrong key, stale timestamp, tampered body, missing header. Each gets 401 and leaves an audit entry.
9. Malformed bodies get 400.
10. Gateway timeout on invoice creation.
11. Gateway timeout during callback processing. The order row is locked so the callback times out at the gateway; the gateway re-sends.

### Phase 3: account lifecycle

1. Registration with valid, malformed and duplicate emails (including other letter case), and a weak password.
2. Verification link: valid, reused, expired (correctly signed), tampered, and for another user.
3. Login: correct password, wrong password, unknown email.
4. Lockout. From one address: throttled with a 429 page. From five addresses: 20 failures lock the account, the owner is emailed, and a password reset unlocks it.
5. Password reset.
6. Password change.
7. Email change.
8. 2FA, including recovery codes.
9. Profile update.
10. Account deletion: *not applicable by decision*.
11. Sessions: logout, expiry, fixation resistance.
12. Two devices at once.

### Phase 4: seller

1. Onboarding. Document upload is *not applicable by decision*.
2. Product create and edit:
   - virus scan, including an EICAR upload;
   - image re-encoding;
   - licence keys;
   - review;
   - a price change sends the product back to review.
3. Pause and resume a product.
4. Sales and ledger views.
5. Payout request, reject and approve.
6. Dispute response.
7. Seller rating: *not applicable by decision*.
8. IDOR attempts by sellers and buyers. All 15 cross-account requests answer 403.

### Phase 5: admin

1. Role matrix: 25 admin pages × 5 roles, plus navigation links.
2. Manager (and moderator) denied destructive actions.
3. Seller applications approved and rejected, with a reason.
4. Products approved and rejected, with a reason. Admin product editing is *not applicable by decision*.
5. Order filters.
6. Full and partial refunds.
7. Disputes resolved both ways.
8. Suspend a buyer.
9. Suspend a seller, which hides their products.
10. Payouts through the gateway, including a duplicate callback.
11. Audit log search.
12. Email templates.
13. Gateway configuration and logs.
14. CSV export of orders and users, including formula neutralisation and the absence of secrets.

### Phase 6: hostile input

1. XSS in every writable field, checked on buyer, seller and admin pages, plus the CSP.
2. SQL injection in search, filters, paths and login.
3. Path traversal.
4. Oversized uploads and request bodies.
5. Unicode and emoji: storage, PDF invoice, mails.
6. Extremely long inputs.
7. Malformed webhook JSON (also in phase 2).
8. Double-click on checkout.

### Phase 7: load (k6, `tests/simulation/load/`)

These workloads run at the same time:

- 50 browsers, 30 of them signed in.
- 20 mixed-vendor checkouts, each with three sellers, paid through the gateway.
- About 100 signed webhooks per minute, including 20 duplicates.
- A super admin paging the product list.

The run passes when all of these hold:

| Check | Requirement |
|---|---|
| Browser request failures | under 1% |
| Browser p95 latency | under 1.5 s |
| Admin product list p95 | under 500 ms |
| Mixed-vendor checkouts | all 20 delivered |
| Webhook orders | all 100 paid exactly once |
| Data invariants | all hold |
| Failed jobs | none |
| Error-level log entries | none |
| PostgreSQL statements | none over 200 ms (from the slow-query log) |

### Phase 8: recovery

1. **PostgreSQL killed (SIGKILL) during six concurrent balance checkouts.** Expected: answers are complete or 503; `/health` gives 503; the shop recovers on its own; no half-created orders; stock and balances consistent.
2. **Queue worker stopped while orders are paid, and another worker killed with `kill -9` inside a delivery job.** Expected: the job is retried once and delivered once.
3. **Mail server down.** Expected: mails are retried and arrive later.
4. **Payment gateway killed.** Expected: a clear checkout message and a retry after the gateway returns.
5. **Backup restore,** following the procedure in `docs/OPERATIONS.md`. Expected: data checksums equal the backup and later changes are gone.
6. **Disk full during upload.** Expected: a clear error, no partial file and no database row; the upload works after space is freed.

## Bugs found and fixed

| # | Severity | Found in | Problem | Fix | Regression test |
|---|---|---|---|---|---|
| 1 | blocker | P1 insufficient balance | Balance checkout created the order before the debit failed, leaving a pending order and reserved stock | Order and debit in one transaction | `PurchaseFlowTest::test_insufficient_balance_creates_no_order` |
| 2 | critical | P5 suspend seller | Products of a suspended seller stayed listed and purchasable | `visible()` and `isPurchasable()` require an active seller | `CatalogFiltersTest` |
| 3 | major | P5 order filters | No buyer, seller or date filters; bulk export ignored them | Filters plus export of the filtered set | `AdminOrderFiltersTest` |
| 4 | major | P5 CSV export | No user export | `users` export without secrets | `AdminOrderFiltersTest` |
| 5 | major | P1 abandoned cart | Cart lost when the session ended or the buyer logged out | `saved_carts`, merged at login, pruned after 30 days | `SavedCartTest` |
| 6 | minor | P1 last unit | The losing buyer was told the item is "no longer available" | "Sorry, it just sold out" | `SavedCartTest` |
| 7 | critical (security) | P2 forged callbacks | Rejected callbacks left no audit entry | `webhook.rejected_signature` / `rejected_ip` audit entries; IP allow-list on payout callbacks too | `WebhookTest::test_forged_callbacks_are_rejected_and_audited` |
| 8 | minor | P2 malformed | A signed JSON array or scalar was accepted (202) | 400 unless it is a non-empty object | `WebhookTest::test_signed_bodies_that_are_not_json_objects_get_400` |
| 9 | major | P2 malformed | A signed object with a nested `external_id` or amount gave 500 | Fields the shop reads must be scalars, else 400 | same |
| 10 | major | P2 out of order | An older PARTIAL callback lowered the recorded amount received | Recorded amount never decreases | `WebhookTest::test_an_older_partial_callback_never_lowers_the_recorded_amount` |
| 11 | major | P2 expired | No way to retry after the order expired | "Buy again" on expired and cancelled orders | `ReorderTest` |
| 12 | critical (security) | P3 lockout | Password spraying across addresses stayed under the per-address limits | Account-level pause after 20 failures in 15 minutes, owner email, audit, reset unlocks | `LoginLockTest` |
| 13 | major | P3 registration | Existing email in other letter case gave 500 | Normalised before validation; race becomes a field error | `AccountSecurityTest::test_registration_with_an_existing_address_in_other_letter_case_is_refused` |
| 14 | minor | P3 email change | Shared the password-reset limiter, with a misleading message | Own per-account limit | `AccountSecurityTest::test_email_change_attempts_have_their_own_limit` |
| 15 | major | P4 deactivate | Sellers could not take a product off sale | Pause and resume (status `paused`) | `SellerPauseTest` |
| 16 | major | P5 reject product | Rejection without a reason; seller not told | Required reason, shown and emailed (editable template) | `ProductModerationNoteTest` |
| 17 | major | P6 SQL injection | A non-UUID order id in the URL reached PostgreSQL and gave 500 | 404; same for `/tickets/new?order=` | `SecurityTest::test_ids_that_are_not_uuids_give_404_not_500` |
| 18 | major | P6 long input | Redirect after a long query string exceeded nginx's 4–8 KB header buffer: 502 | 32 KB fastcgi and proxy header buffers | phase 6 scenario (nginx configuration) |
| 19 | minor | P6 oversized | Upload over the PHP limit said only "failed to upload" | Message names the size limit | phase 6 scenario |
| 20 | critical | P8 mail down / worker kill | Mails had 1 try: an SMTP hiccup or a worker restart lost them | Mails and notifications: 5 tries with backoff | `AccountSecurityTest::test_password_reset_mail_is_queued_with_retries`, phase 8 |
| 21 | major | P8 mail down | "Forgot password" sent mail inside the request: 500 when SMTP was down | Queued reset notification | same |
| 22 | major | P8 database killed | Generic 500 page during a database outage; `/health` answered 500 (session middleware) | 503 "Temporarily unavailable" with Retry-After; health and metrics without a session | `OutageTest` |
| 23 | critical | P8 disk full | Upload on a full disk gave 500 and could leave a partial file | Partial file removed, nothing recorded, clear message, critical log, checksum verified | `StorageFailureTest` |
| 24 | major | P7 load | Gateway returning an invoice id already used by another order gave 500 | Logged error, normal "try again" message | `WebhookTest::test_a_gateway_invoice_id_already_used_by_another_order_is_refused_cleanly` |
| 25 | major (perf) | P7 load | Checkout `SELECT … FOR UPDATE` waited up to 350 ms under 20 concurrent checkouts | Exclusive lock only for limited-stock products, shared lock otherwise | phase 7 slow-query check |
| 26 | test env | P7 load | Shkeeper mock handed out duplicate invoice ids under concurrency | State updates serialised with a lock file | phase 7 |
| 27 | test env | setup | Simulation seed used Faker, which is not in production images | Seeder creates accounts directly | `run.sh up` |
| 28 | major | independent review | Paused product could receive new files or images and be resumed without review | Uploads to a paused product send it to review; resume refuses unscanned files; conditional status updates | `ReviewFindingsTest` |
| 29 | major (security) | independent review | The login lock could keep an owner locked out indefinitely, mailing them on every lock | Right password from a known device works during a lock; lock mail at most once a day | `LoginLockTest` |
| 30 | minor | independent review | Array values (`email[]=`, `q[]=`, `order[]=`) gave 500 | Inputs checked for strings | `ReviewFindingsTest::test_array_inputs_never_cause_a_500` |
| 31 | minor | independent review | Non-numeric ids (`/tickets/abc`) reached PostgreSQL: 500 | Numeric route patterns: 404 | `ReviewFindingsTest::test_non_numeric_ids_are_404` |
| 32 | major | independent review | "Database starting up", "too many clients" and timeouts still answered 500 | 503 for SQLSTATE 08xxx, 57P01-03, 53300; 5 s connect timeout | `ReviewFindingsTest`, `OutageTest` |
| 33 | minor | independent review | Staff could relist a product the seller paused; "Buy again" ignored the cart line limit; text mails showed HTML entities; forged callbacks could flood the audit log | Refused; limit applied; raw output in text mails; one audit row per address and minute | `ReviewFindingsTest`, `WebhookTest` |
| 34 | minor | certification run | A valid signed 3 MB webhook body was accepted | Bodies over 64 KB refused with 413 before hashing | `WebhookTest::test_oversized_bodies_are_refused_before_processing` |
| 35 | major | P8 restore | `scripts/backup.sh` failed on a shop without invoices yet (missing directory) | Directories created before archiving | phase 8 restore scenario |

Harness defects found along the way are not listed: wrong test data choices, k6 per-VU counters, and process supervision on the host backend. They are in the git history.

## Independent review

An independent reviewer agent read every application change made during the
simulation. It found no blockers and confirmed two major and several minor
problems (rows 28 to 33). All were fixed with regression tests, and the
certification was run again afterwards. This is not the human sign-off
required by exit criterion 8.

## Not applicable by decision

These were recorded as not applicable when the scope was agreed:

- guest checkout;
- seller document upload;
- account self-deletion (erasure through support; see the data retention page);
- seller ratings (products have reviews);
- admin product editing (staff disable with a reason and the seller edits).

## Known limits of this environment

- The certification backend (`compose`, the production image with PHP-FPM) needs the Alpine and Debian package mirrors to build the image. They were blocked by the sandbox's network policy during this work, so the recorded runs use the `host` backend:
  - the same PostgreSQL 15, nginx TLS front end, Mailpit and mock containers;
  - the app on PHP 8.3 with PHP's built-in server instead of PHP-FPM.
  
  Re-run `SIM_BACKEND=compose sh tests/simulation/run.sh up` and `test --runs 5` (and `load`) once the mirrors are reachable. Load numbers on FPM will differ.
- On the compose backend, Docker's port publishing hides client addresses. Per-address rate limits then apply to all simulated people together, and the "five source addresses" lockout case cannot spread its attempts.
- The restore scenario drives `scripts/backup.sh` and `scripts/restore.sh` on the host backend. On compose, run the same scripts with `docker compose exec`.
- **Restore point:** a restore returns to the backup's point in time. Payments made at Shkeeper after the backup refer to orders that no longer exist, and the shop ignores them as unknown orders. Staff must reconcile them from Shkeeper's dashboard. This is stated in the restore procedure.

## Sign-off

The simulation is evidence, not approval. Exit criterion 8 is a second engineer's review and sign-off, and it must be given by a person. Promotion to production should wait for it and for the compose-backend runs above.

## Results

See the end of this file, updated after each certification run.
